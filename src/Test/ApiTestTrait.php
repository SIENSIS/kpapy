<?php

declare(strict_types=1);

namespace Kpapy\Test;

use CodeIgniter\Test\TestResponse;
use Kpapy\Auth\TokenService;

/**
 * Ajudes per provar una API de kpapy amb el FeatureTestTrait de CodeIgniter.
 *
 *     final class BooksApiTest extends CIUnitTestCase
 *     {
 *         use DatabaseTestTrait, FeatureTestTrait, ApiTestTrait;
 *
 *         public function testReaderCannotCreate(): void
 *         {
 *             $this->actingAsApi($reader, ['books.list']);
 *             $response = $this->postJson('api/v1/books', ['title' => 'X']);
 *             $this->assertProblem($response, 403, 'forbidden');
 *         }
 *     }
 */
trait ApiTestTrait
{
    /**
     * @var array<string, string>
     */
    protected array $apiHeaders = [];

    /**
     * Les peticions següents porten un token de l'usuari amb aquests abasts.
     *
     * @param list<string> $scopes
     */
    protected function actingAsApi(object $user, array $scopes = ['*']): static
    {
        $token            = (new TokenService())->create($user, 'Test', $scopes, HOUR);
        $this->apiHeaders = ['Authorization' => 'Bearer ' . $token['token'], 'Accept' => 'application/json'];

        return $this;
    }

    protected function getJson(string $path, array $query = []): TestResponse
    {
        return $this->withHeaders($this->apiHeaders)->get($path . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function postJson(string $path, array $body = []): TestResponse
    {
        return $this->withHeaders($this->apiHeaders)->withBodyFormat('json')->post($path, $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function patchJson(string $path, array $body = []): TestResponse
    {
        return $this->withHeaders($this->apiHeaders)->withBodyFormat('json')->patch($path, $body);
    }

    protected function deleteJson(string $path): TestResponse
    {
        return $this->withHeaders($this->apiHeaders)->delete($path);
    }

    /**
     * Cos JSON de la resposta com a array.
     *
     * @return array<string, mixed>
     */
    protected function json(TestResponse $response): array
    {
        return json_decode((string) $response->response()->getBody(), true) ?? [];
    }

    /**
     * La resposta és un error problem+json amb aquest estat i codi.
     */
    protected function assertProblem(TestResponse $response, int $status, ?string $code = null): void
    {
        $this->assertSame($status, $response->response()->getStatusCode());
        $this->assertStringContainsString('application/problem+json', $response->response()->getHeaderLine('Content-Type'));

        if ($code !== null) {
            $this->assertSame($code, $this->json($response)['code'] ?? null);
        }
    }
}
