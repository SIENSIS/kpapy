<?php

declare(strict_types=1);

namespace Kpapy\Auth;

/**
 * Usuari i abasts (scopes) de la petició de l'API, que desa el filtre 'kpapy'.
 *
 * scopes() és null quan la petició no porta token (rutes públiques o sessió web):
 * aleshores només compten els permisos de l'usuari.
 */
final class Context
{
    private static ?object $user = null;

    /** @var list<string>|null */
    private static ?array $scopes = null;

    private static ?object $token = null;

    /**
     * @param list<string>|null $scopes
     */
    public static function set(?object $user, ?array $scopes = null, ?object $token = null): void
    {
        self::$user   = $user;
        self::$scopes = $scopes === null ? null : array_values($scopes);
        self::$token  = $token;
    }

    public static function reset(): void
    {
        self::set(null);
    }

    public static function user(): ?object
    {
        return self::$user;
    }

    /**
     * @return list<string>|null
     */
    public static function scopes(): ?array
    {
        return self::$scopes;
    }

    /**
     * Token d'accés de la petició (Shield AccessToken), si n'hi ha.
     */
    public static function token(): ?object
    {
        return self::$token;
    }
}
