<?php

declare(strict_types=1);

namespace Kpapy\Auth;

use CodeIgniter\Router\RouteCollection;

/**
 * Rutes d'autenticació de l'API.
 *
 *     AuthRoutes::register($routes, 'api/v1');                       // API (sense CSRF)
 *     AuthRoutes::register($routes, 'api/v1', [], MyLoginController::class);   // un altre controlador
 *     AuthRoutes::web($routes, 'profile', ['filter' => 'session']);  // perfil web (amb CSRF)
 */
final class AuthRoutes
{
    /**
     * @param array<string, mixed> $options         Opcions addicionals de les rutes
     * @param class-string|null    $loginController Controlador de l'inici de sessió (subclasse de
     *                                              LoginController); null: Config\Kpapy::$loginController
     * @param class-string|null    $tokenController Controlador de me/tokens (subclasse de TokenController);
     *                                              null: Config\Kpapy::$tokenController
     */
    public static function register(RouteCollection $routes, string $prefix = 'api/v1', array $options = [], ?string $loginController = null, ?string $tokenController = null): void
    {
        $config = config('Kpapy');
        $config = $config instanceof \Kpapy\Config\Kpapy ? $config : new \Kpapy\Config\Kpapy();
        $login  = '\\' . self::controller($loginController ?? $config->loginController, LoginController::class);
        $tokens = '\\' . self::controller($tokenController ?? $config->tokenController, TokenController::class);

        $prefix = trim($prefix, '/');
        $public = ['filter' => 'kpapy:login'] + $options;
        $auth   = ['filter' => 'kpapy'] + $options;

        $routes->post($prefix . '/auth/token', $login . '::token', $public);
        $routes->post($prefix . '/auth/token/verify', $login . '::verify', $public);
        $routes->delete($prefix . '/auth/token', $login . '::logout', $auth);
        $routes->post($prefix . '/auth/token/refresh', $login . '::refresh', $auth);
        $routes->get($prefix . '/me/tokens', $tokens . '::index', $auth);
        $routes->delete($prefix . '/me/tokens/(:num)', $tokens . '::delete/$1', $auth);

        foreach ([$prefix . '/auth/token', $prefix . '/auth/token/verify', $prefix . '/auth/token/refresh', $prefix . '/me/tokens', $prefix . '/me/tokens/(:num)'] as $path) {
            $routes->options($path, static fn () => service('response')->setStatusCode(204), $public);
        }
    }

    /**
     * Nom de classe validat (sense la barra inicial): ha de ser $base o una subclasse.
     */
    private static function controller(string $class, string $base): string
    {
        $class = ltrim($class, '\\');

        if (! is_a($class, $base, true)) {
            throw new \InvalidArgumentException(lang('Kpapy.exceptions.invalidController', [$class, $base]));
        }

        return $class;
    }

    /**
     * Ruta del perfil web per denegar un inici de sessió pendent (POST <prefix>/api-logins/{id}/deny).
     *
     * @param array<string, mixed> $options
     */
    public static function web(RouteCollection $routes, string $prefix = 'profile', array $options = []): void
    {
        $routes->post(trim($prefix, '/') . '/api-logins/(:segment)/deny', '\Kpapy\Auth\WebController::deny/$1', $options);
    }

    /**
     * HTML de la llista d'inicis de sessió pendents per al perfil web de l'usuari.
     */
    public static function pendingLogins(int|string $userId, string $denyBase = 'profile'): string
    {
        $config = config('Kpapy');
        $config = $config instanceof \Kpapy\Config\Kpapy ? $config : new \Kpapy\Config\Kpapy();

        return view('Kpapy\Views\pending_logins', [
            'challenges' => (new Challenges($config))->pendingFor($userId),
            'denyBase'   => trim($denyBase, '/'),
        ], ['saveData' => false]);
    }
}
