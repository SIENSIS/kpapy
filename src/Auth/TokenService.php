<?php

declare(strict_types=1);

namespace Kpapy\Auth;

use CodeIgniter\I18n\Time;
use Kpapy\Config\Kpapy as KpapyConfig;
use RuntimeException;

/**
 * Tokens d'accés de Shield dels usuaris: per a la pàgina de perfil de l'aplicació web
 * (crear, llistar i revocar tokens personals) i per a l'inici de sessió de les apps.
 *
 *     $service = new TokenService();
 *     ['token' => $raw] = $service->create(auth()->user(), 'Integració ERP', ['news-posts.list'], 90 * DAY);
 */
class TokenService
{
    protected KpapyConfig $config;

    public function __construct(?KpapyConfig $config = null)
    {
        $config ??= config('Kpapy');
        $this->config = $config instanceof KpapyConfig ? $config : new KpapyConfig();

        helper('auth');

        if (! function_exists('auth')) {
            throw new RuntimeException(lang('Kpapy.exceptions.shieldRequired'));
        }
    }

    /**
     * Crea un token. El valor en clar només es pot veure ara.
     *
     * @param list<string> $scopes
     *
     * @return array{token: string, id: int, name: string, scopes: list<string>, expires_at: string|null}
     */
    public function create(object $user, string $name, array $scopes = [], ?int $lifetime = null): array
    {
        $scopes   = $scopes === [] ? $this->config->defaultScopes : self::cleanScopes($scopes);
        $lifetime ??= $this->config->tokenLifetime;
        $expires  = $lifetime > 0 ? Time::now()->addSeconds($lifetime) : null;
        $token    = $user->generateAccessToken(mb_substr(trim($name) === '' ? 'API' : trim($name), 0, 64), $scopes, $expires);

        return [
            'token'      => (string) $token->raw_token,
            'id'         => (int) $token->id,
            'name'       => (string) $token->name,
            'scopes'     => array_values((array) $token->scopes),
            'expires_at' => $expires?->format(DATE_ATOM),
        ];
    }

    /**
     * Tokens de l'usuari, sense el secret.
     *
     * @return list<array<string, mixed>>
     */
    public function list(object $user): array
    {
        $current = Context::token();
        $result  = [];

        foreach ($user->accessTokens() as $token) {
            $result[] = [
                'id'           => (int) $token->id,
                'name'         => (string) $token->name,
                'scopes'       => array_values((array) $token->scopes),
                'last_used_at' => $token->last_used_at?->format(DATE_ATOM),
                'expires_at'   => isset($token->expires) && $token->expires !== null ? $token->expires->format(DATE_ATOM) : null,
                'created_at'   => $token->created_at?->format(DATE_ATOM),
                'current'      => $current !== null && (int) $current->id === (int) $token->id,
            ];
        }

        return $result;
    }

    public function revoke(object $user, int $id): bool
    {
        $token = $user->getAccessTokenById($id);

        if ($token === null) {
            return false;
        }

        $user->revokeAccessTokenBySecret($token->secret);

        return true;
    }

    /**
     * Abasts vàlids: "*", "recurs.*" o "recurs.operació".
     *
     * @param list<mixed> $scopes
     *
     * @return list<string>
     */
    public static function cleanScopes(array $scopes): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($s): string => is_string($s) ? trim($s) : '', $scopes),
            static fn (string $s): bool => $s === '*' || preg_match('/^[a-z0-9][a-z0-9_.-]*\.(\*|[a-z][a-z0-9_-]*)$/', $s) === 1,
        )));
    }
}
