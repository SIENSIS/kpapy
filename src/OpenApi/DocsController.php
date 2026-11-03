<?php

declare(strict_types=1);

namespace Kpapy\OpenApi;

use CodeIgniter\Controller;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Router\RouteCollection;
use Kpapy\Config\Kpapy as KpapyConfig;

/**
 * Documentació de l'API: el document OpenAPI (JSON) i una pàgina HTML per llegir-lo.
 * Fora de producció, o en producció si Config\Kpapy::$docsInProduction.
 *
 *     DocsController::routes($routes, 'api/v1/docs');   // /api/v1/docs i /api/v1/docs/openapi.json
 */
class DocsController extends Controller
{
    /**
     * @param array<string, mixed> $options Opcions de les rutes (p. ex. un filtre d'administradors)
     */
    public static function routes(RouteCollection $routes, string $path = 'api/v1/docs', array $options = []): void
    {
        $path = trim($path, '/');

        $routes->get($path, '\\' . static::class . '::page', $options);
        $routes->get($path . '/openapi.json', '\\' . static::class . '::spec', $options);
    }

    public function spec(): ResponseInterface
    {
        $this->guard();

        return $this->response->setJSON((new Generator($this->kpapy()))->generate($this->prefix()));
    }

    public function page(): string
    {
        $this->guard();

        $spec = (new Generator($this->kpapy()))->generate($this->prefix());

        return view('Kpapy\Views\docs', ['spec' => $spec, 'jsonUrl' => current_url() . '/openapi.json'], ['saveData' => false]);
    }

    protected function guard(): void
    {
        if (ENVIRONMENT === 'production' && ! $this->kpapy()->docsInProduction) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    /**
     * Només les rutes que comencen per aquest camí (null = totes).
     */
    protected function prefix(): ?string
    {
        return null;
    }

    protected function kpapy(): KpapyConfig
    {
        $config = config('Kpapy');

        return $config instanceof KpapyConfig ? $config : new KpapyConfig();
    }
}
