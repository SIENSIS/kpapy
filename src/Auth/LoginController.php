<?php

declare(strict_types=1);

namespace Kpapy\Auth;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Kpapy\Auth\TwoFactor\TwoFactorProvider;
use Kpapy\Config\Kpapy as KpapyConfig;
use Kpapy\Http\ApiException;
use Kpapy\Http\Responder;
use RuntimeException;
use Throwable;

/**
 * Inici de sessió de les apps amb segon factor (rutes de AuthRoutes::register()).
 *
 *  1. POST auth/token        {"email", "password", "device_name", "scopes"?}  → 202 amb un repte
 *  2. POST auth/token/verify {"challenge", "code"}                            → 201 amb el token
 *  3. DELETE auth/token (tanca la sessió) · POST auth/token/refresh (renova el token)
 *
 * El segon factor és el primer mètode de Config\Kpapy::$twoFactorMethods que l'usuari té
 * actiu. Sense cap (TOTP, correu...), el mètode 'web' mostra el codi al perfil web.
 */
class LoginController extends Controller
{
    protected KpapyConfig $kpapy;
    protected Responder $responder;

    protected function setup(): void
    {
        $config          = config('Kpapy');
        $this->kpapy     = $config instanceof KpapyConfig ? $config : new KpapyConfig();
        $this->responder = new Responder($this->kpapy, $this->response);

        helper('auth');
    }

    public function token(): ResponseInterface
    {
        return $this->handle(function (): ResponseInterface {
            $body     = $this->body();
            $email    = is_string($body['email'] ?? null) ? trim($body['email']) : '';
            $password = is_string($body['password'] ?? null) ? $body['password'] : '';
            $device   = is_string($body['device_name'] ?? null) && trim($body['device_name']) !== '' ? trim($body['device_name']) : 'App';
            $scopes   = is_array($body['scopes'] ?? null) ? TokenService::cleanScopes($body['scopes']) : [];

            if ($email === '' || $password === '') {
                throw ApiException::validation(array_filter(['email' => $email === '' ? ['required'] : null, 'password' => $password === '' ? ['required'] : null]));
            }

            // Límit per compte, a més del límit per IP del filtre
            if ($this->kpapy->rateLimitLogin > 0 && ! service('throttler')->check('kpapy-login-' . md5(strtolower($email)), $this->kpapy->rateLimitLogin, MINUTE)) {
                throw ApiException::tooManyRequests(service('throttler')->getTokenTime());
            }

            $user = $this->checkCredentials($email, $password);

            if ((method_exists($user, 'isBanned') && $user->isBanned()) || (isset($user->active) && ! $user->active)) {
                throw ApiException::forbidden(lang('Kpapy.auth.bannedUser'));
            }

            [$method, $provider] = $this->providerFor($user);

            $challenges = new Challenges($this->kpapy);
            $challenge  = $challenges->create($user->id, $method, $device, $this->request->getIPAddress(), $scopes);
            $message    = $provider->start($user, $challenge, $challenges);

            $data = [
                'challenge'  => $challenge['id'],
                'method'     => $method,
                'expires_in' => $this->kpapy->challengeLifetime,
                'message'    => $message,
            ];

            if ($method === 'web' && $this->kpapy->profileUrl !== '') {
                $data['profile_url'] = $this->kpapy->profileUrl;
            }

            Events::fire('kpapy.auth.challenge', ['user' => $user, 'method' => $method, 'device' => $device]);

            return $this->responder->item($data, [], [], 202);
        });
    }

    public function verify(): ResponseInterface
    {
        return $this->handle(function (): ResponseInterface {
            $body       = $this->body();
            $id         = is_string($body['challenge'] ?? null) ? $body['challenge'] : '';
            $code       = is_scalar($body['code'] ?? null) ? trim((string) $body['code']) : '';
            $challenges = new Challenges($this->kpapy);
            $challenge  = $challenges->find($id);

            if ($challenge === null || $code === '') {
                throw new ApiException(422, 'invalid-code', lang('Kpapy.auth.invalidCode'));
            }

            $user = auth()->getProvider()->findById($challenge['user_id']);

            if ($user === null || (method_exists($user, 'isBanned') && $user->isBanned())) {
                throw new ApiException(422, 'invalid-code', lang('Kpapy.auth.invalidCode'));
            }

            $provider = $this->provider($challenge['method']);

            if (! $provider->verify($user, $challenge, $code, $challenges)) {
                $pending = $challenges->fail($challenge['id']);
                Events::fire('kpapy.auth.failed', ['user' => $user, 'method' => $challenge['method']]);

                throw new ApiException(422, 'invalid-code', lang($pending ? 'Kpapy.auth.invalidCode' : 'Kpapy.auth.tooManyAttempts'));
            }

            $challenges->complete($challenge['id']);

            $scopes = json_decode((string) $challenge['scopes'], true) ?: [];
            $token  = (new TokenService($this->kpapy))->create($user, $challenge['device'], $scopes);

            Events::fire('kpapy.auth.login', ['user' => $user, 'token_id' => $token['id'], 'method' => $challenge['method']]);

            return $this->responder->item([
                'token'      => $token['token'],
                'token_type' => 'Bearer',
                'expires_at' => $token['expires_at'],
                'scopes'     => $token['scopes'],
            ], [], [], 201);
        });
    }

    public function logout(): ResponseInterface
    {
        return $this->handle(function (): ResponseInterface {
            $user  = Context::user() ?? throw ApiException::unauthorized();
            $token = Context::token();

            if ($token !== null) {
                $user->revokeAccessTokenBySecret($token->secret);
                Events::fire('kpapy.auth.logout', ['user' => $user, 'token_id' => $token->id]);
            }

            return $this->responder->noContent();
        });
    }

    public function refresh(): ResponseInterface
    {
        return $this->handle(function (): ResponseInterface {
            $user  = Context::user() ?? throw ApiException::unauthorized();
            $token = Context::token() ?? throw ApiException::unauthorized();
            $new   = (new TokenService($this->kpapy))->create($user, (string) $token->name, array_values((array) $token->scopes), null, ['kind' => TokenService::kindOf($token)]);

            $user->revokeAccessTokenBySecret($token->secret);

            return $this->responder->item([
                'token'      => $new['token'],
                'token_type' => 'Bearer',
                'expires_at' => $new['expires_at'],
                'scopes'     => $new['scopes'],
            ], [], [], 201);
        });
    }

    /**
     * Usuari si l'email i la contrasenya són correctes (sense iniciar cap sessió web).
     */
    protected function checkCredentials(string $email, string $password): object
    {
        $user      = auth()->getProvider()->findByCredentials(['email' => $email]);
        $passwords = service('passwords');

        // Mateix temps de resposta tant si l'usuari existeix com si no. La contrasenya fictícia
        // és hexadecimal: bcrypt rebutja el byte nul (ValueError) que random_bytes() pot generar.
        static $dummy = null;
        $hash = $user?->password_hash ?? ($dummy ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT));

        if (! $passwords->verify($password, (string) $hash) || $user === null) {
            Events::fire('kpapy.auth.failed', ['email' => $email, 'method' => 'password']);

            throw new ApiException(401, 'invalid-credentials', lang('Kpapy.auth.invalidCredentials'));
        }

        return $user;
    }

    /**
     * @return array{0: string, 1: TwoFactorProvider}
     */
    protected function providerFor(object $user): array
    {
        foreach ($this->kpapy->twoFactorMethods as $method) {
            $provider = $this->provider($method);

            if ($provider->isEnabledFor($user)) {
                return [$method, $provider];
            }
        }

        throw new ApiException(403, 'two-factor-required', lang('Kpapy.auth.twoFactorRequired'), [], [], array_filter(['profile_url' => $this->kpapy->profileUrl]));
    }

    protected function provider(string $method): TwoFactorProvider
    {
        $class    = $this->kpapy->twoFactorProviders[$method] ?? null;
        $provider = is_string($class) && class_exists($class) ? new $class() : null;

        if (! $provider instanceof TwoFactorProvider) {
            throw new RuntimeException(lang('Kpapy.exceptions.invalidProvider', [$method]));
        }

        return $provider;
    }

    /**
     * @return array<string, mixed>
     */
    protected function body(): array
    {
        if (! str_contains(strtolower($this->request->getHeaderLine('Content-Type')), 'json')) {
            throw ApiException::unsupportedMediaType();
        }

        try {
            $body = json_decode((string) $this->request->getBody(), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ApiException::badRequest(lang('Kpapy.invalidJson'));
        }

        return is_array($body) ? $body : throw ApiException::badRequest(lang('Kpapy.invalidJson'));
    }

    protected function handle(callable $action): ResponseInterface
    {
        $this->setup();

        try {
            return $action();
        } catch (ApiException $e) {
            return $this->responder->problem($e);
        } catch (Throwable $e) {
            return $this->responder->exception($e);
        }
    }
}
