<?php

declare(strict_types=1);

namespace Kpapy\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Kpapy\Auth\Authenticator;
use Kpapy\Auth\Context;
use Kpapy\Auth\Events;
use Kpapy\Auth\ShieldHmacAuthenticator;
use Kpapy\Auth\ShieldTokenAuthenticator;
use Kpapy\Auth\TokenUsage;
use Kpapy\Config\Kpapy as KpapyConfig;
use Kpapy\Http\ApiException;
use Kpapy\Http\Responder;
use RuntimeException;

/**
 * Filtre de les rutes de l'API (àlies 'kpapy'): CORS, autenticació, límit de peticions
 * i HTTPS. Arguments:
 *
 *     'kpapy'           token obligatori (autenticador de la configuració)
 *     'kpapy:public'    sense autenticació (només límit de peticions per IP)
 *     'kpapy:optional'  autentica si la petició porta token
 *     'kpapy:login'     sense autenticació, amb el límit de peticions de l'inici de sessió
 *     'kpapy:hmac'      tokens HMAC de Shield
 *
 * Les rutes de l'API no fan servir galetes ni sessió: s'han d'excloure del filtre 'csrf'.
 */
class ApiFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        /** @var IncomingRequest $request */
        $config    = config('Kpapy');
        $config    = $config instanceof KpapyConfig ? $config : new KpapyConfig();
        $arguments = (array) $arguments;
        $responder = new Responder($config);

        Context::reset();

        try {
            if (strtoupper($request->getMethod()) === 'OPTIONS') {
                return $this->preflight($request, $config);
            }

            if (ENVIRONMENT === 'production' && $config->requireHttps && ! $request->isSecure()) {
                throw ApiException::forbidden(lang('Kpapy.httpsRequired'));
            }

            $mode   = in_array('public', $arguments, true) || in_array('login', $arguments, true) ? 'public' : (in_array('optional', $arguments, true) ? 'optional' : 'required');
            $result = null;

            if ($mode !== 'public') {
                $result = $this->authenticator($config, $arguments)->authenticate($request);

                if ($result === null && $mode === 'required') {
                    throw ApiException::unauthorized();
                }
            }

            if ($result !== null) {
                Context::set($result['user'], $result['scopes'], $result['token']);

                if (isset($result['token']->id)) {
                    $this->tokenUsed($result['user'], $result['token'], $request, $config);
                }
            }

            $limit = in_array('login', $arguments, true) ? $config->rateLimitLogin : ($result === null ? $config->rateLimitPublic : $config->rateLimit);
            $key   = $result !== null && isset($result['token']->id) ? 'kpapy-t' . $result['token']->id : 'kpapy-ip' . md5($request->getIPAddress()) . (in_array('login', $arguments, true) ? '-login' : '');

            $this->throttle($key, $limit);
        } catch (ApiException $e) {
            return $this->cors($request, $config, $responder->problem($e));
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $config = config('Kpapy');
        $config = $config instanceof KpapyConfig ? $config : new KpapyConfig();

        return $this->cors($request, $config, $response);
    }

    /**
     * @param list<string> $arguments
     */
    protected function authenticator(KpapyConfig $config, array $arguments): Authenticator
    {
        $name  = in_array('hmac', $arguments, true) ? 'hmac' : $config->authenticator;
        $class = match ($name) {
            'shield' => ShieldTokenAuthenticator::class,
            'hmac'   => ShieldHmacAuthenticator::class,
            default  => $name,
        };

        $authenticator = new $class();

        if (! $authenticator instanceof Authenticator) {
            throw new RuntimeException("Kpapy: {$class} does not implement Kpapy\\Auth\\Authenticator.");
        }

        return $authenticator;
    }

    /**
     * Ús d'un token: l'esdeveniment kpapy.token.used (user, token, token_id, ip, user_agent) i,
     * amb Config\Kpapy::$trackTokenIps, la darrera IP a la taula kpapy_token_usage.
     */
    protected function tokenUsed(object $user, object $token, IncomingRequest $request, KpapyConfig $config): void
    {
        $ip = $request->getIPAddress();

        Events::fire('kpapy.token.used', [
            'user'       => $user,
            'token'      => $token,
            'token_id'   => (int) $token->id,
            'ip'         => $ip,
            'user_agent' => $request->getHeaderLine('User-Agent'),
        ]);

        if ($config->trackTokenIps) {
            (new TokenUsage())->record((int) $token->id, $user->id ?? null, $ip);
        }
    }

    /**
     * Límit de peticions per minut amb el Throttler de CodeIgniter.
     */
    protected function throttle(string $key, int $limit): void
    {
        if ($limit <= 0) {
            return;
        }

        $throttler = service('throttler');

        if (! $throttler->check($key, $limit, MINUTE)) {
            throw ApiException::tooManyRequests($throttler->getTokenTime());
        }
    }

    protected function preflight(IncomingRequest $request, KpapyConfig $config): ResponseInterface
    {
        $response = service('response')->setStatusCode(204)->setBody('');

        if ($this->allowedOrigin($request, $config) !== null) {
            $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->setHeader('Access-Control-Allow-Headers', implode(', ', $config->corsHeaders))
                ->setHeader('Access-Control-Max-Age', (string) $config->corsMaxAge);
        }

        return $this->cors($request, $config, $response);
    }

    protected function cors(RequestInterface $request, KpapyConfig $config, ResponseInterface $response): ResponseInterface
    {
        /** @var IncomingRequest $request */
        $origin = $this->allowedOrigin($request, $config);

        if ($origin !== null) {
            $response->setHeader('Access-Control-Allow-Origin', $origin)
                ->setHeader('Access-Control-Expose-Headers', 'ETag, Location, Retry-After')
                ->appendHeader('Vary', 'Origin');
        } else {
            $response->removeHeader('Access-Control-Allow-Origin');
        }

        return $response;
    }

    protected function allowedOrigin(IncomingRequest $request, KpapyConfig $config): ?string
    {
        $origin = $request->getHeaderLine('Origin');

        if ($origin === '' || $config->corsOrigins === []) {
            return null;
        }

        if (in_array('*', $config->corsOrigins, true)) {
            return '*';
        }

        return in_array(rtrim($origin, '/'), array_map(static fn (string $o): string => rtrim($o, '/'), $config->corsOrigins), true) ? $origin : null;
    }
}
