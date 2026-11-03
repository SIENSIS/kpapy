<?php

declare(strict_types=1);

namespace Kpapy\Demo;

use CodeIgniter\Router\RouteCollection;
use Kpapy\Auth\AuthRoutes;
use Kpapy\Resource;
use RuntimeException;

/**
 * Names, routes and settings of the kpapy demo: URLs under /kpapy-demo, tables prefixed
 * with kpapy_demo_, demo users created in Shield (removed on uninstall).
 */
final class Demo
{
    public const PREFIX = 'kpapy-demo';

    public const API = self::PREFIX . '/api/v1';

    /**
     * Demo tables, in creation order.
     */
    public const TABLES = ['kpapy_demo_authors', 'kpapy_demo_tags', 'kpapy_demo_books', 'kpapy_demo_book_tag', 'kpapy_demo_book_translations', 'kpapy_demo_loans'];

    /**
     * Demo users: key => [email, username, password, role].
     */
    public const USERS = [
        'admin'  => ['admin@kpapy.demo', 'kpapy-admin', 'kpapy-demo-admin', 'admin'],
        'reader' => ['reader@kpapy.demo', 'kpapy-reader', 'kpapy-demo-reader', 'reader'],
    ];

    public static function markerFile(): string
    {
        return WRITEPATH . 'kpapy-demo.json';
    }

    public static function isInstalled(): bool
    {
        return is_file(self::markerFile());
    }

    /**
     * @return array<string, mixed>
     */
    public static function marker(): array
    {
        $marker = self::isInstalled() ? json_decode((string) @file_get_contents(self::markerFile()), true) : null;

        return is_array($marker) ? $marker : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function writeMarker(array $data): void
    {
        $temp = self::markerFile() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($temp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (! @rename($temp, self::markerFile())) {
            @unlink($temp);

            throw new RuntimeException('Cannot write the demo marker ' . self::markerFile());
        }
    }

    /**
     * Anyone can get a token for the demo users from the console page, so the demo never
     * runs in production unless it was installed with --allow-production.
     */
    public static function isEnabled(): bool
    {
        return self::isInstalled() && (ENVIRONMENT !== 'production' || (self::marker()['allow_production'] ?? false) === true);
    }

    /**
     * Id of a demo user ('admin', 'reader'), or null.
     */
    public static function userId(string $key): ?int
    {
        $id = self::marker()['users'][$key] ?? null;

        return is_int($id) ? $id : null;
    }

    /**
     * Role of a demo user id: 'admin', 'reader' or null (not a demo user).
     */
    public static function roleOf(int|string|null $id): ?string
    {
        foreach (self::marker()['users'] ?? [] as $key => $userId) {
            if ((string) $userId === (string) $id) {
                return self::USERS[$key][3] ?? null;
            }
        }

        return null;
    }

    public static function config(): DemoConfig
    {
        static $config = null;

        return $config ??= new DemoConfig();
    }

    /**
     * Registers the demo routes (src/Config/Routes.php). Does nothing if the demo is not
     * installed or not enabled.
     */
    public static function routes(RouteCollection $routes): void
    {
        if (! self::isEnabled()) {
            return;
        }

        $api = ['filter' => 'kpapy'];

        // API resources (Bearer token)
        Resource::routes($routes, self::API . '/books', '\Kpapy\Demo\Controllers\Api::books', $api + ['as' => 'kpapy-demo-books']);
        Resource::routes($routes, self::API . '/authors', '\Kpapy\Demo\Controllers\Api::authors', $api + ['as' => 'kpapy-demo-authors']);
        Resource::routes($routes, self::API . '/tags', '\Kpapy\Demo\Controllers\Api::tags', $api);
        Resource::routes($routes, self::API . '/my/loans', '\Kpapy\Demo\Controllers\Api::loans', $api);

        // Public, read-only catalogue (no token)
        Resource::routes($routes, self::API . '/public/books', '\Kpapy\Demo\Controllers\Api::publicBooks', ['filter' => 'kpapy:public']);

        // Login for apps (with the demo's own two-factor settings) and tokens
        $routes->post(self::API . '/auth/token', '\Kpapy\Demo\Controllers\DemoLogin::token', ['filter' => 'kpapy:login']);
        $routes->post(self::API . '/auth/token/verify', '\Kpapy\Demo\Controllers\DemoLogin::verify', ['filter' => 'kpapy:login']);
        $routes->delete(self::API . '/auth/token', '\Kpapy\Demo\Controllers\DemoLogin::logout', $api);
        $routes->post(self::API . '/auth/token/refresh', '\Kpapy\Demo\Controllers\DemoLogin::refresh', $api);
        $routes->get(self::API . '/me/tokens', '\Kpapy\Auth\TokenController::index', $api);
        $routes->delete(self::API . '/me/tokens/(:num)', '\Kpapy\Auth\TokenController::delete/$1', $api);

        // Documentation
        \Kpapy\Demo\Controllers\DemoDocs::routes($routes, self::API . '/docs');

        // Web pages of the demo: console and the "web profile" of the demo users
        $routes->get(self::PREFIX, '\Kpapy\Demo\Controllers\Console::index');
        $routes->get(self::PREFIX . '/console.js', '\Kpapy\Demo\Controllers\Console::script');
        $routes->post(self::PREFIX . '/token/(:segment)', '\Kpapy\Demo\Controllers\Console::token/$1');
        $routes->get(self::PREFIX . '/profile/(:segment)', '\Kpapy\Demo\Controllers\Console::profile/$1');
        $routes->post(self::PREFIX . '/profile/(:segment)/deny/(:segment)', '\Kpapy\Demo\Controllers\Console::deny/$1/$2');
    }

    public static function url(string $path = ''): string
    {
        return site_url(rtrim(self::PREFIX . '/' . ltrim($path, '/'), '/'));
    }
}
