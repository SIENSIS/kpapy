<?php

declare(strict_types=1);

namespace Kpapy\Auth;

use CodeIgniter\Router\RouteCollection;

/**
 * Rutes d'autenticació de l'API.
 *
 *     AuthRoutes::register($routes, 'api/v1');                       // API (sense CSRF)
 *     AuthRoutes::web($routes, 'profile', ['filter' => 'session']);  // perfil web (amb CSRF)
 */
final class AuthRoutes
{
    /**
     * @param array<string, mixed> $options Opcions addicionals de les rutes
     */
    public static function register(RouteCollection $routes, string $prefix = 'api/v1', array $options = []): void
    {
        $prefix = trim($prefix, '/');
        $login  = ['filter' => 'kpapy:login'] + $options;
        $auth   = ['filter' => 'kpapy'] + $options;

        $routes->post($prefix . '/auth/token', '\Kpapy\Auth\LoginController::token', $login);
        $routes->post($prefix . '/auth/token/verify', '\Kpapy\Auth\LoginController::verify', $login);
        $routes->delete($prefix . '/auth/token', '\Kpapy\Auth\LoginController::logout', $auth);
        $routes->post($prefix . '/auth/token/refresh', '\Kpapy\Auth\LoginController::refresh', $auth);
        $routes->get($prefix . '/me/tokens', '\Kpapy\Auth\TokenController::index', $auth);
        $routes->delete($prefix . '/me/tokens/(:num)', '\Kpapy\Auth\TokenController::delete/$1', $auth);

        foreach ([$prefix . '/auth/token', $prefix . '/auth/token/verify', $prefix . '/auth/token/refresh', $prefix . '/me/tokens', $prefix . '/me/tokens/(:num)'] as $path) {
            $routes->options($path, static fn () => service('response')->setStatusCode(204), $login);
        }
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
