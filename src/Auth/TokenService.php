<?php

declare(strict_types=1);

namespace Kpapy\Auth;

use CodeIgniter\I18n\Time;
use CodeIgniter\Shield\Models\UserIdentityModel;
use Kpapy\Config\Kpapy as KpapyConfig;
use RuntimeException;

/**
 * Tokens d'accés de Shield dels usuaris: per a la pàgina de perfil de l'aplicació web
 * (crear, llistar i revocar tokens personals) i per a l'inici de sessió de les apps.
 *
 *     $service = new TokenService();
 *     ['token' => $raw] = $service->create(auth()->user(), 'Integració ERP', ['news-posts.list'], 90 * DAY);
 *     $service->create($user, 'Integració ERP', [], null, ['kind' => 'personal']);   // token marcat
 *
 * La marca ('kind') es desa a la columna secret2 de la identitat de Shield (auth_identities),
 * que Shield no fa servir als tokens d'accés.
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
     * Opcions: 'kind' (string, màxim 255 caràcters) marca el token (p. ex. 'personal' o 'app');
     * es desa a la columna secret2 de auth_identities i es pot filtrar amb list($user, $kind).
     *
     * @param list<string>         $scopes
     * @param array<string, mixed> $options
     *
     * @return array{token: string, id: int, name: string, scopes: list<string>, expires_at: string|null, kind: string|null}
     */
    public function create(object $user, string $name, array $scopes = [], ?int $lifetime = null, array $options = []): array
    {
        $scopes   = $scopes === [] ? $this->config->defaultScopes : self::cleanScopes($scopes);
        $lifetime ??= $this->config->tokenLifetime;
        $expires  = $lifetime > 0 ? Time::now()->addSeconds($lifetime) : null;
        $token    = $user->generateAccessToken(mb_substr(trim($name) === '' ? 'API' : trim($name), 0, 64), $scopes, $expires);
        $kind     = isset($options['kind']) && is_scalar($options['kind']) && (string) $options['kind'] !== '' ? mb_substr((string) $options['kind'], 0, 255) : null;

        if ($kind !== null) {
            model(UserIdentityModel::class)->builder()->where('id', $token->id)->update(['secret2' => $kind]);
            $token->secret2 = $kind;
        }

        return [
            'token'      => (string) $token->raw_token,
            'id'         => (int) $token->id,
            'name'       => (string) $token->name,
            'scopes'     => array_values((array) $token->scopes),
            'expires_at' => $expires?->format(DATE_ATOM),
            'kind'       => $kind,
        ];
    }

    /**
     * Marca d'un token (opció 'kind' de create()), o null.
     */
    public static function kindOf(object $token): ?string
    {
        $kind = $token->secret2 ?? null;

        return is_string($kind) && $kind !== '' ? $kind : null;
    }

    /**
     * Tokens de l'usuari, sense el secret. Amb $kind, només els tokens d'aquesta marca.
     * 'last_ip' és la darrera IP (null si Config\Kpapy::$trackTokenIps és false o no s'ha fet servir).
     *
     * @return list<array<string, mixed>>
     */
    public function list(object $user, ?string $kind = null): array
    {
        $current = Context::token();
        $result  = [];
        $tokens  = array_values(array_filter($user->accessTokens(), static fn (object $t): bool => $kind === null || self::kindOf($t) === $kind));
        $ips     = $this->config->trackTokenIps ? (new TokenUsage())->lastIps(array_map(static fn (object $t): int => (int) $t->id, $tokens)) : [];

        foreach ($tokens as $token) {
            $result[] = [
                'id'           => (int) $token->id,
                'name'         => (string) $token->name,
                'scopes'       => array_values((array) $token->scopes),
                'last_used_at' => $token->last_used_at?->format(DATE_ATOM),
                'expires_at'   => isset($token->expires) && $token->expires !== null ? $token->expires->format(DATE_ATOM) : null,
                'created_at'   => $token->created_at?->format(DATE_ATOM),
                'current'      => $current !== null && (int) $current->id === (int) $token->id,
                'kind'         => self::kindOf($token),
                'last_ip'      => $ips[(int) $token->id] ?? null,
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

        if ($this->config->trackTokenIps) {
            (new TokenUsage())->forget($id);
        }

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
