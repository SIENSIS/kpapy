<?php

declare(strict_types=1);

namespace Kpapy\Http;

use CodeIgniter\HTTP\ResponseInterface;
use Kpapy\Config\Kpapy as KpapyConfig;
use Throwable;

/**
 * Construeix les respostes JSON de l'API: el sobre {"data", "meta", "links"}, els errors
 * application/problem+json i les capçaleres comunes.
 */
class Responder
{
    public function __construct(protected KpapyConfig $config, protected ?ResponseInterface $response = null)
    {
        $this->response ??= service('response');
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $links
     * @param array<string, mixed> $meta
     */
    public function item(array $item, array $links = [], array $meta = [], int $status = 200): ResponseInterface
    {
        if (! $this->config->envelope) {
            return $this->json($item, $status);
        }

        $keys = $this->config->envelopeKeys;
        $body = [$keys['data'] => $item];

        if ($meta !== []) {
            $body[$keys['meta']] = $meta;
        }

        if ($links !== []) {
            $body[$keys['links']] = $links;
        }

        return $this->json($body, $status);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>       $meta
     * @param array<string, mixed>       $links
     */
    public function collection(array $items, array $meta, array $links): ResponseInterface
    {
        $keys = $this->config->envelopeKeys;

        return $this->json([$keys['data'] => $items, $keys['meta'] => $meta, $keys['links'] => $links]);
    }

    public function noContent(): ResponseInterface
    {
        return $this->headers($this->response->setStatusCode(204)->setBody(''));
    }

    public function json(mixed $body, int $status = 200): ResponseInterface
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        return $this->headers($this->response
            ->setStatusCode($status)
            ->setContentType('application/json', 'UTF-8')
            ->setBody($json));
    }

    public function problem(ApiException $e): ResponseInterface
    {
        $title = lang('Kpapy.problems.' . $e->type);

        $body = [
            'type'   => $this->config->problemTypeBase !== '' ? rtrim($this->config->problemTypeBase, '/') . '/' . $e->type : 'about:blank',
            'title'  => str_starts_with($title, 'Kpapy.') ? $e->type : $title,
            'status' => $e->status,
        ];

        if ($e->getMessage() !== '') {
            $body['detail'] = $e->getMessage();
        }

        $body['code'] = $e->type;
        $body += $e->extra;

        if ($e->errors !== []) {
            $body['errors'] = $e->errors;
        }

        $this->json($body, $e->status)->setContentType('application/problem+json', 'UTF-8');

        foreach ($e->headers as $name => $value) {
            $this->response->setHeader($name, $value);
        }

        return $this->response;
    }

    /**
     * Error inesperat: 500 sense detalls fora de development.
     */
    public function exception(Throwable $e): ResponseInterface
    {
        log_message('error', '[kpapy] {class}: {message} in {file}:{line}', [
            'class' => $e::class, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(),
        ]);

        $detail = ENVIRONMENT === 'development' ? $e::class . ': ' . $e->getMessage() : '';

        return $this->problem(new ApiException(500, 'server-error', $detail));
    }

    protected function headers(ResponseInterface $response): ResponseInterface
    {
        $response->setHeader('X-Content-Type-Options', 'nosniff');

        if (! $response->hasHeader('Cache-Control') || $response->getHeaderLine('Cache-Control') === 'no-store, max-age=0, no-cache') {
            // Dades privades: el client pot desar-les i revalidar-les amb l'ETag, cap intermediari
            $response->setHeader('Cache-Control', 'private, no-cache');
        }

        return $response;
    }
}
