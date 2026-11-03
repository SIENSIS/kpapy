<?php

declare(strict_types=1);

namespace Kpapy\Http;

use RuntimeException;

/**
 * Error de l'API que es converteix en una resposta application/problem+json (RFC 9457).
 *
 *     throw ApiException::notFound();
 *     throw ApiException::validation(['title' => ['The Title field is required.']]);
 *     throw new ApiException(409, 'conflict', 'The book is on loan.');
 */
class ApiException extends RuntimeException
{
    /**
     * @param string                       $type    Identificador curt del tipus (not-found, validation...)
     * @param array<string, list<string>>  $errors  Errors per camp (validació)
     * @param array<string, string>        $headers Capçaleres addicionals (Allow, Retry-After...)
     * @param array<string, mixed>         $extra   Membres addicionals del problema
     */
    public function __construct(
        public readonly int $status,
        public readonly string $type,
        string $detail = '',
        public readonly array $errors = [],
        public readonly array $headers = [],
        public readonly array $extra = [],
    ) {
        parent::__construct($detail, $status);
    }

    public static function badRequest(string $detail = ''): static
    {
        return new static(400, 'bad-request', $detail);
    }

    public static function unauthorized(string $detail = ''): static
    {
        return new static(401, 'unauthorized', $detail, [], ['WWW-Authenticate' => 'Bearer']);
    }

    public static function forbidden(string $detail = ''): static
    {
        return new static(403, 'forbidden', $detail);
    }

    public static function notFound(string $detail = ''): static
    {
        return new static(404, 'not-found', $detail);
    }

    /**
     * @param list<string> $allow
     */
    public static function methodNotAllowed(array $allow): static
    {
        return new static(405, 'method-not-allowed', '', [], ['Allow' => implode(', ', $allow)]);
    }

    public static function conflict(string $detail = ''): static
    {
        return new static(409, 'conflict', $detail);
    }

    public static function preconditionFailed(string $detail = ''): static
    {
        return new static(412, 'precondition-failed', $detail);
    }

    public static function preconditionRequired(): static
    {
        return new static(428, 'precondition-required');
    }

    public static function unsupportedMediaType(): static
    {
        return new static(415, 'unsupported-media-type');
    }

    /**
     * @param array<string, list<string>|string> $errors
     */
    public static function validation(array $errors, string $detail = ''): static
    {
        $normalised = [];

        foreach ($errors as $field => $messages) {
            $normalised[(string) $field] = array_values((array) $messages);
        }

        return new static(422, 'validation', $detail, $normalised);
    }

    public static function tooManyRequests(int $retryAfter): static
    {
        return new static(429, 'too-many-requests', '', [], ['Retry-After' => (string) max(1, $retryAfter)]);
    }
}
