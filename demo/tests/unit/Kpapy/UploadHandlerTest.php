<?php

namespace Tests\Kpapy;

use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Kpapy\Auth\Context;
use Kpapy\Resource;
use Kpapy\Storage\UploadHandler;
use Kpapy\Storage\UploadReader;
use Kpapy\Storage\UploadValidator;
use Kpapy\Test\ApiTestTrait;

/**
 * Pluggable storage of the upload fields (Kpapy\Storage\UploadHandler): store, validation,
 * public and signed URLs, download, replacement and deletion through the handler.
 *
 * @internal
 */
final class UploadHandlerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ApiTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private User $user;
    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();
        Context::reset();
        MemoryUploadHandler::$files = [];
        MemoryUploadHandler::$calls = [];

        $forge = \Config\Database::forge($this->DBGroup);
        $forge->dropTable('kp_files', true);
        $forge->addField([
            'id'    => ['type' => 'INTEGER', 'auto_increment' => true],
            'name'  => ['type' => 'VARCHAR', 'constraint' => 60],
            'file'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'photo' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'plain' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ])->addPrimaryKey('id')->createTable('kp_files');
        $this->db->table('kp_files')->insert(['name' => 'First', 'photo' => 'cdn/one.png', 'plain' => 'x.txt']);

        $users = auth()->getProvider();
        $users->save(new User(['username' => 'cel', 'email' => 'cel@example.com', 'password' => 'Secret-123-pass', 'active' => 1]));
        $this->user = $users->findById($users->getInsertID());

        $routes = service('routes');
        $routes->resetRoutes();
        Resource::routes($routes, 'api/v1/files', static fn () => (new Resource())
            ->setTable('kp_files')
            ->setResource('files')
            ->fieldType('file', 'upload', ['handler' => new MemoryUploadHandler(), 'bucket' => 'private'])
            ->fieldType('photo', 'image', ['handler' => PublicUploadHandler::class])
            ->fieldType('plain', 'upload', ['handler' => PublicUploadHandler::class, 'private' => true])
            ->render(), ['filter' => 'kpapy']);
        Resource::routes($routes, 'api/v1/broken', static fn () => (new Resource())
            ->setTable('kp_files')
            ->fieldType('file', 'upload', ['handler' => \stdClass::class])
            ->render(), ['filter' => 'kpapy']);
        $this->routes = $routes;

        $this->actingAsApi($this->user);
    }

    protected function tearDown(): void
    {
        $_FILES = [];
        if (method_exists(service('superglobals'), 'setFilesArray')) {
            service('superglobals')->setFilesArray([]);
        }
        if ($this->tmp !== '') {
            @unlink($this->tmp);
        }
        parent::tearDown();
    }

    private function upload(string $name, string $content): \CodeIgniter\Test\TestResponse
    {
        $this->tmp = tempnam(sys_get_temp_dir(), 'kp');
        file_put_contents($this->tmp, $content);
        $_FILES = ['file' => ['name' => $name, 'type' => 'text/plain', 'tmp_name' => $this->tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)]];

        if (method_exists(service('superglobals'), 'setFilesArray')) {
            service('superglobals')->setFilesArray($_FILES);   // CodeIgniter 4.7+
        }

        return $this->withHeaders($this->apiHeaders)->call('POST', 'api/v1/files/1/files/file');
    }

    public function testTheHandlerStoresReadsReplacesAndDeletes(): void
    {
        $response = $this->upload('note.txt', 'hello handler');
        $response->assertStatus(200);

        [$method, $field, $context] = MemoryUploadHandler::$calls[0];
        $this->assertSame(['store', 'file'], [$method, $field]);
        $this->assertSame('files', $context['resource']);
        $this->assertSame('kp_files', $context['table']);
        $this->assertSame('1', $context['id']);
        $this->assertSame('First', $context['record']['name']);
        $this->assertSame('private', $context['options']['bucket']);
        $this->assertSame('upload', $context['type']);
        $this->assertInstanceOf(\Kpapy\Config\Kpapy::class, $context['config']);
        $this->assertInstanceOf(Resource::class, $context['api']);

        $stored = (string) $this->db->table('kp_files')->where('id', 1)->get()->getRow()->file;
        $this->assertSame('hello handler', MemoryUploadHandler::$files[$stored]);

        // Private (url() = null) and readable: a signed URL served from read()
        $item = $this->json($this->getJson('api/v1/files/1'))['data'];
        $this->assertStringContainsString('/files/file?expires=', $item['file']);
        $path     = parse_url($item['file'], PHP_URL_PATH) . '?' . parse_url($item['file'], PHP_URL_QUERY);
        $download = $this->withHeaders($this->apiHeaders)->get(ltrim(substr($path, strpos($path, 'api/')), '/'));
        $download->assertStatus(200);
        $this->assertSame('hello handler', (string) $download->response()->getBody());

        // Public URL from the handler; private without a reader: no URL
        $this->assertSame('https://cdn.example.com/cdn/one.png', $item['photo']);
        $this->assertNull($item['plain']);

        // Replacing the file deletes the old one through the handler
        $this->upload('other.txt', 'second')->assertStatus(200);
        $this->assertArrayNotHasKey($stored, MemoryUploadHandler::$files);
        $this->assertContains(['delete', 'file', $stored], array_map(static fn (array $c): array => [$c[0], $c[1], $c[3] ?? null], MemoryUploadHandler::$calls));

        // Deleting the record deletes the file
        $this->deleteJson('api/v1/files/1')->assertStatus(204);
        $this->assertSame([], MemoryUploadHandler::$files);
    }

    public function testTheHandlerCanTakeOverTheValidation(): void
    {
        $response = $this->upload('bad.txt', 'x');
        $this->assertProblem($response, 422, 'validation');
        $this->assertSame(['Rejected by the handler.'], $this->json($response)['errors']['file']);
        $this->assertSame([], MemoryUploadHandler::$files);
    }

    public function testAnInvalidHandlerIsAnError(): void
    {
        $this->db->table('kp_files')->where('id', 1)->update(['file' => 'some.txt']);
        $this->getJson('api/v1/broken/1')->assertStatus(500);
    }
}

/**
 * Handler that keeps the files in memory.
 */
final class MemoryUploadHandler implements UploadHandler, UploadReader, UploadValidator
{
    /** @var array<string, string> */
    public static array $files = [];

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>, 3?: string}> */
    public static array $calls = [];

    public function store(UploadedFile $file, string $field, array $context): string
    {
        self::$calls[] = ['store', $field, $context];
        $key           = 'mem/' . bin2hex(random_bytes(4)) . '.txt';
        self::$files[$key] = (string) file_get_contents($file->getTempName());

        return $key;
    }

    public function delete(string $stored, string $field, array $context): void
    {
        self::$calls[] = ['delete', $field, $context, $stored];
        unset(self::$files[$stored]);
    }

    public function url(string $stored, string $field, array $context): ?string
    {
        return null;
    }

    public function read(string $stored, string $field, array $context): ?string
    {
        return self::$files[$stored] ?? null;
    }

    public function validate(UploadedFile $file, string $field, array $context): ?string
    {
        return str_starts_with($file->getClientName(), 'bad') ? 'Rejected by the handler.' : null;
    }
}

/**
 * Handler with public URLs (no reader).
 */
final class PublicUploadHandler implements UploadHandler
{
    public function store(UploadedFile $file, string $field, array $context): string
    {
        return 'cdn/' . $file->getRandomName();
    }

    public function delete(string $stored, string $field, array $context): void
    {
    }

    public function url(string $stored, string $field, array $context): ?string
    {
        return empty($context['options']['private']) ? 'https://cdn.example.com/' . $stored : null;
    }
}
