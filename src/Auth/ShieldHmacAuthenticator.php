<?php

declare(strict_types=1);

namespace Kpapy\Auth;

/**
 * Tokens HMAC-SHA256 de CodeIgniter Shield (peticions signades, per a integracions
 * entre servidors): Authorization: HMAC-SHA256 <clau>:<signatura>.
 */
class ShieldHmacAuthenticator extends ShieldTokenAuthenticator
{
    protected string $authenticator = 'hmac';

    protected function currentToken(object $user): ?object
    {
        return method_exists($user, 'currentHmacToken') ? $user->currentHmacToken() : null;
    }

    protected function credentials(string $header): array
    {
        return ['token' => $header, 'body' => (string) service('request')->getBody()];
    }
}
