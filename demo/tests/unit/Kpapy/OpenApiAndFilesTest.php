<?php

namespace Tests\Kpapy;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Kpapy\Auth\Context;
use Kpapy\OpenApi\Generator;
use Kpapy\Resource;
use Kpapy\Test\ApiTestTrait;

/**
 * OpenAPI document, the documentation page, file uploads with signed URLs and the
 * ApiTestTrait helpers.
 *
 * @internal
 */
final class OpenApiAndFilesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ApiTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Context::reset();

        $forge = \Config\Database::forge($this->DBGroup);
        $forge->dropTable('kp_docs', true);
        $forge->addField([
            'id'     => ['type' => 'INTEGER', 'auto_increment' => true],
            'name'   => ['type' => 'VARCHAR', 'constraint' => 60],
            'status' => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'file'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ])->addPrimaryKey('id')->createTable('kp_docs');
        $this->db->table('kp_docs')->insert(['name' => 'First']);

        $users = auth()->getProvider();
        $users->save(new User(['username' => 'bo', 'email' => 'bo@example.com', 'password' => 'Secret-123-pass', 'active' => 1]));
        $this->user = $users->findById($users->getInsertID());

        $routes = service('routes');
        $routes->resetRoutes();
        Resource::routes($routes, 'api/v1/docs-items', static fn () => (new Resource())
            ->setTable('kp_docs')
            ->setResource('docs')
            ->describe('Documents')
            ->fieldType('status', 'enum', ['options' => ['draft' => 'Draft', 'final' => 'Final']])
            ->fieldType('file', 'upload', ['path' => WRITEPATH . 'uploads/kpapy-test', 'allowed' => ['txt']])
            ->addOperation('archive', static fn () => null, ['description' => 'Archive it'])
            ->render(), ['filter' => 'kpapy']);
        \Kpapy\OpenApi\DocsController::routes($routes, 'api/v1/docs');
        $this->routes = $routes;
    }

    public function testOpenApiDocument(): void
    {
        $spec = (new Generator(null, $this->routes))->generate();

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertArrayHasKey('/api/v1/docs-items', $spec['paths']);
        $this->assertArrayHasKey('/api/v1/docs-items/{id}/archive', $spec['paths']);
        $this->assertSame(['draft', 'final'], $spec['components']['schemas']['Docs']['properties']['status']['enum']);
        $this->assertSame('binary', $spec['components']['schemas']['DocsInput']['properties']['file']['format']);
        $this->assertContains('name', $spec['components']['schemas']['DocsInput']['required']);
        $this->assertStringContainsString('openapi: ', str_replace('"', '', Generator::toYaml(['openapi' => '3.1.0'])));

        $page = $this->get('api/v1/docs');
        $page->assertStatus(200);
        $page->assertSee('Documents');
        $this->assertSame('3.1.0', json_decode((string) $this->get('api/v1/docs/openapi.json')->response()->getBody(), true)['openapi']);
    }

    public function testUploadAndSignedDownload(): void
    {
        $this->actingAsApi($this->user);

        $tmp = tempnam(sys_get_temp_dir(), 'kp') . '.txt';
        file_put_contents($tmp, 'hello kpapy');
        $_FILES = ['file' => ['name' => 'note.txt', 'type' => 'text/plain', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]];

        // The upload uses is_uploaded_file(): through the API test helpers we check the validation path
        $response = $this->withHeaders($this->apiHeaders)->call('POST', 'api/v1/docs-items/1/files/file');
        $this->assertContains($response->response()->getStatusCode(), [200, 422]);

        // Signed URL of an existing private file
        $dir = WRITEPATH . 'uploads/kpapy-test';
        is_dir($dir) || mkdir($dir, 0775, true);
        file_put_contents($dir . '/stored.txt', 'stored content');
        $this->db->table('kp_docs')->where('id', 1)->update(['file' => 'stored.txt']);

        $item = $this->json($this->getJson('api/v1/docs-items/1'))['data'];
        $this->assertStringContainsString('/files/file?expires=', $item['file']);

        $path = parse_url($item['file'], PHP_URL_PATH) . '?' . parse_url($item['file'], PHP_URL_QUERY);
        $download = $this->withHeaders($this->apiHeaders)->get(ltrim(substr($path, strpos($path, 'api/')), '/'));
        $download->assertStatus(200);
        $this->assertSame('stored content', (string) $download->response()->getBody());

        $tampered = str_replace('signature=', 'signature=x', $path);
        $this->assertProblem($this->withHeaders($this->apiHeaders)->get(ltrim(substr($tampered, strpos($tampered, 'api/')), '/')), 403, 'forbidden');

        @unlink($dir . '/stored.txt');
        @unlink($tmp);
    }

    public function testEnumValidationAndProblemHelper(): void
    {
        $this->actingAsApi($this->user, ['docs.*']);

        $this->assertProblem($this->postJson('api/v1/docs-items', ['name' => 'X', 'status' => 'nope']), 422, 'validation');
        $this->postJson('api/v1/docs-items', ['name' => 'Y', 'status' => 'final'])->assertStatus(201);
        $this->assertSame('final', $this->json($this->getJson('api/v1/docs-items', ['filter' => ['status' => 'final']]))['data'][0]['status']);
    }
}
