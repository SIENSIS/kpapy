<?php

declare(strict_types=1);

namespace Kpapy\Auth;

use CodeIgniter\HTTP\IncomingRequest;
use Kpapy\Http\ApiException;
use RuntimeException;

/**
 * Tokens d'accés de CodeIgniter Shield: Authorization: Bearer <token>.
 * Els abasts (scopes) del token limiten el que pot fer (Context::scopes()).
 */
class ShieldTokenAuthenticator implements Authenticator
{
    protected string $authenticator = 'tokens';

    public function authenticate(IncomingRequest $request): ?array
    {
        helper('auth');

        if (! function_exists('auth')) {
            throw new RuntimeException(lang('Kpapy.exceptions.shieldRequired'));
        }

        $header = $request->getHeaderLine(config('AuthToken')->authenticatorHeader[$this->authenticator] ?? 'Authorization');

        if ($header === '') {
            return null;
        }

        $auth   = auth($this->authenticator)->getAuthenticator();
        $result = $auth->attempt($this->credentials($header));

        if (! $result->isOK()) {
            throw ApiException::unauthorized((string) $result->reason());
        }

        $user  = $auth->getUser();
        $token = $user === null ? null : $this->currentToken($user);

        if ($user === null || (method_exists($user, 'isBanned') && $user->isBanned())) {
            throw ApiException::unauthorized();
        }

        $scopes = is_object($token) && isset($token->scopes) ? array_values((array) $token->scopes) : null;

        return ['user' => $user, 'scopes' => $scopes, 'token' => $token];
    }

    protected function currentToken(object $user): ?object
    {
        return method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
    }

    /**
     * @return array<string, string>
     */
    protected function credentials(string $header): array
    {
        return ['token' => trim((string) preg_replace('/^Bearer\s+/i', '', $header))];
    }
}
