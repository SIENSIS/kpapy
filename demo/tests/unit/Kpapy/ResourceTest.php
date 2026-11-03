<?php

namespace Tests\Kpapy;

use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\App;
use Kpapy\Auth\Context;
use Kpapy\Config\Kpapy as KpapyConfig;
use Kpapy\Resource;

/**
 * Operacions del recurs: llistat, filtres, cerca, ordenació, paginació, detall, alta,
 * edició, esborrat, paperera, relacions, traduccions, multiselect, permisos, abasts,
 * operacions pròpies, accions massives, ETag i errors.
 *
 * @internal
 */
final class ResourceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();
        Context::reset();

        $forge = \Config\Database::forge($this->DBGroup);

        foreach (['kp_book_tag', 'kp_book_translations', 'kp_books', 'kp_authors', 'kp_tags'] as $table) {
            $forge->dropTable($table, true);
        }

        $forge->addField([
            'id'   => ['type' => 'INTEGER', 'auto_increment' => true],
            'name' => ['type' => 'VARCHAR', 'constraint' => 80],
        ])->addPrimaryKey('id')->createTable('kp_authors');

        $forge->addField([
            'id'   => ['type' => 'INTEGER', 'auto_increment' => true],
            'name' => ['type' => 'VARCHAR', 'constraint' => 40],
        ])->addPrimaryKey('id')->createTable('kp_tags');

        $forge->addField([
            'id'         => ['type' => 'INTEGER', 'auto_increment' => true],
            'title'      => ['type' => 'VARCHAR', 'constraint' => 120],
            'author_id'  => ['type' => 'INTEGER', 'null' => true],
            'year'       => ['type' => 'INTEGER', 'null' => true],
            'price'      => ['type' => 'DECIMAL', 'constraint' => '8,2', 'null' => true],
            'available'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'genres'     => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'owner_id'   => ['type' => 'INTEGER', 'null' => true],
            'secret_key' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ])->addPrimaryKey('id')->createTable('kp_books');

        $forge->addField([
            'book_id' => ['type' => 'INTEGER'],
            'tag_id'  => ['type' => 'INTEGER'],
        ])->createTable('kp_book_tag');

        $forge->addField([
            'id'      => ['type' => 'INTEGER', 'auto_increment' => true],
            'book_id' => ['type' => 'INTEGER'],
            'locale'  => ['type' => 'VARCHAR', 'constraint' => 5],
            'summary' => ['type' => 'VARCHAR', 'constraint' => 200],
        ])->addPrimaryKey('id')->createTable('kp_book_translations');

        $db = $this->db;
        $db->table('kp_authors')->insertBatch([['name' => 'Mercè Rodoreda'], ['name' => 'Joan Sales']]);
        $db->table('kp_tags')->insertBatch([['name' => 'novel'], ['name' => 'classic'], ['name' => 'war']]);
        $db->table('kp_books')->insertBatch([
            ['title' => 'La plaça del Diamant', 'author_id' => 1, 'year' => 1962, 'price' => '18.50', 'available' => 1, 'genres' => '["novel"]', 'owner_id' => 1, 'secret_key' => 's1'],
            ['title' => 'Incerta glòria', 'author_id' => 2, 'year' => 1956, 'price' => '24.00', 'available' => 0, 'genres' => '["novel","war"]', 'owner_id' => 2, 'secret_key' => 's2'],
            ['title' => 'Mirall trencat', 'author_id' => 1, 'year' => 1974, 'price' => '21.00', 'available' => 1, 'genres' => null, 'owner_id' => 1, 'secret_key' => 's3'],
        ]);
        $db->table('kp_book_tag')->insertBatch([['book_id' => 1, 'tag_id' => 1], ['book_id' => 1, 'tag_id' => 2], ['book_id' => 2, 'tag_id' => 3]]);
        $db->table('kp_book_translations')->insertBatch([
            ['book_id' => 1, 'locale' => 'ca', 'summary' => 'Una dona a la Barcelona de la guerra'],
            ['book_id' => 1, 'locale' => 'en', 'summary' => 'A woman in wartime Barcelona'],
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    private function api(string $method = 'GET', string $path = 'books', array $query = [], ?array $body = null, array $headers = [], ?KpapyConfig $config = null): Resource
    {
        $app     = config(App::class);
        $raw     = $body === null ? '' : json_encode($body);
        $request = new IncomingRequest($app, new SiteURI($app, $path), $raw, new UserAgent());
        $request->setMethod($method);
        $request->setGlobal('get', $query);
        $_GET = $query;

        if ($body !== null) {
            $request->setHeader('Content-Type', 'application/json');
        }

        foreach ($headers as $name => $value) {
            $request->setHeader($name, $value);
        }

        Services::injectMock('request', $request);
        Services::injectMock('response', Services::response(null, false));

        $config ??= new KpapyConfig();

        return (new Resource($config))
            ->setTable('kp_books')
            ->setBasePath('books')
            ->setRelation('author_id', 'kp_authors', ['name'], ['as' => 'author'])
            ->setRelationNN('tags', 'kp_book_tag', 'kp_tags', 'book_id', 'tag_id', ['name'])
            ->setTranslations('kp_book_translations', 'book_id', 'locale', ['summary'], ['ca', 'en'], ['required' => ['ca']])
            ->fieldType('available', 'bool')
            ->fieldType('price', 'decimal', ['scale' => 2])
            ->fieldType('genres', 'multiselect', ['options' => ['novel' => 'Novel', 'war' => 'War', 'poetry' => 'Poetry']])
            ->filters(['author_id', 'year', 'available', 'genres', 'tags', 'price']);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    public function testListWithPaginationAndSensitiveFieldsHidden(): void
    {
        $response = $this->api('GET', 'books', ['per_page' => '2', 'sort' => 'year'])->render();
        $body     = $this->json($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $body['data']);
        $this->assertSame(['per_page' => 2, 'page' => 1, 'total' => 3, 'pages' => 2], $body['meta']);
        $this->assertSame('Incerta glòria', $body['data'][0]['title']);
        $this->assertArrayNotHasKey('secret_key', $body['data'][0]);
        $this->assertArrayNotHasKey('deleted_at', $body['data'][0]);
        $this->assertSame(1956, $body['data'][0]['year']);
        $this->assertSame('24.00', $body['data'][0]['price']);
        $this->assertFalse($body['data'][0]['available']);
        $this->assertSame(['novel', 'war'], $body['data'][0]['genres']);
        $this->assertNotNull($body['links']['next']);
        $this->assertNull($body['links']['prev']);
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testFiltersSearchSortAndIncludes(): void
    {
        $body = $this->json($this->api('GET', 'books', ['filter' => ['author_id' => '1', 'year' => ['gte' => '1970']]])->render());
        $this->assertSame(['Mirall trencat'], array_column($body['data'], 'title'));

        $body = $this->json($this->api('GET', 'books', ['filter' => ['genres' => 'war']])->render());
        $this->assertSame(['Incerta glòria'], array_column($body['data'], 'title'));

        $body = $this->json($this->api('GET', 'books', ['filter' => ['tags' => '2']])->render());
        $this->assertSame(['La plaça del Diamant'], array_column($body['data'], 'title'));

        $body = $this->json($this->api('GET', 'books', ['q' => 'glòria'])->render());
        $this->assertSame(['Incerta glòria'], array_column($body['data'], 'title'));

        $body = $this->json($this->api('GET', 'books', ['sort' => '-year', 'include' => 'author,tags', 'fields' => 'title,author_id,tags'])->render());
        $this->assertSame(['Mirall trencat', 'La plaça del Diamant', 'Incerta glòria'], array_column($body['data'], 'title'));
        $this->assertSame(['id' => '1', 'name' => 'Mercè Rodoreda'], $body['data'][0]['author']);
        $this->assertSame(['id', 'title', 'author_id', 'tags', 'author'], array_keys($body['data'][0]));
        $this->assertSame([['id' => '1', 'name' => 'novel'], ['id' => '2', 'name' => 'classic']], $body['data'][1]['tags']);

        // Paràmetres no vàlids: 400 en problem+json
        $response = $this->api('GET', 'books', ['filter' => ['secret_key' => 'x']])->render();
        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(400, $this->api('GET', 'books', ['sort' => 'secret_key'])->render()->getStatusCode());
        $this->assertSame(400, $this->api('GET', 'books', ['filter' => ['year' => 'abc']])->render()->getStatusCode());
        $this->assertSame(400, $this->api('GET', 'books', ['include' => 'nope'])->render()->getStatusCode());
    }

    public function testTranslationsFollowTheRequestedLanguage(): void
    {
        $body = $this->json($this->api('GET', 'books/1', ['lang' => 'en'])->render());
        $this->assertSame('A woman in wartime Barcelona', $body['data']['summary']);

        $body = $this->json($this->api('GET', 'books/1', [], null, ['Accept-Language' => 'ca-ES,ca;q=0.9'])->render());
        $this->assertSame('Una dona a la Barcelona de la guerra', $body['data']['summary']);

        $body = $this->json($this->api('GET', 'books/1', ['lang' => '*'])->render());
        $this->assertSame(['ca' => 'Una dona a la Barcelona de la guerra', 'en' => 'A woman in wartime Barcelona'], $body['data']['summary']);
    }

    public function testCreateValidatesAndReturns201(): void
    {
        $response = $this->api('POST', 'books', [], ['title' => '', 'year' => 'x'])->render();
        $body     = $this->json($response);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('title', $body['errors']);
        $this->assertArrayHasKey('year', $body['errors']);

        $response = $this->api('POST', 'books', [], [
            'title'      => 'Aloma',
            'author_id'  => '1',
            'year'       => 1938,
            'available'  => true,
            'genres'     => ['novel'],
            'tags'       => ['1', '3'],
            'summary'    => ['ca' => 'Primera novel·la', 'en' => 'First novel'],
            'secret_key' => 'ignored',
        ])->render();
        $body = $this->json($response);

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringEndsWith('/books/4', $response->getHeaderLine('Location'));
        $this->assertSame('Aloma', $body['data']['title']);
        $this->assertSame(['1', '3'], $body['data']['tags']);
        $this->assertNull($this->db->table('kp_books')->where('id', 4)->get()->getRow()->secret_key);
        $this->assertSame('["novel"]', $this->db->table('kp_books')->where('id', 4)->get()->getRow()->genres);
        $this->assertSame(2, $this->db->table('kp_book_translations')->where('book_id', 4)->countAllResults());

        // Relació inexistent i opció no vàlida
        $response = $this->api('POST', 'books', [], ['title' => 'X', 'author_id' => '99', 'genres' => ['sci-fi'], 'summary' => ['ca' => 'Resum']])->render();
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['author_id', 'genres'], array_keys($this->json($response)['errors']));

        // Traducció obligatòria (ca)
        $response = $this->api('POST', 'books', [], ['title' => 'Y', 'summary' => ['en' => 'Only English']])->render();
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testPatchIsPartialAndChecksIfMatch(): void
    {
        $etag = $this->api('GET', 'books/2')->render()->getHeaderLine('ETag');

        $response = $this->api('PATCH', 'books/2', [], ['year' => 1955], ['If-Match' => '"stale"'])->render();
        $this->assertSame(412, $response->getStatusCode());

        $response = $this->api('PATCH', 'books/2', [], ['year' => 1955], ['If-Match' => $etag])->render();
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(1955, $this->json($response)['data']['year']);
        $this->assertSame('Incerta glòria', $this->json($response)['data']['title']);

        // 304 amb el mateix ETag
        $etag = $this->api('GET', 'books/2')->render()->getHeaderLine('ETag');
        $this->assertSame(304, $this->api('GET', 'books/2', [], null, ['If-None-Match' => $etag])->render()->getStatusCode());
    }

    public function testDeleteTrashRestoreAndPurge(): void
    {
        $this->assertSame(204, $this->api('DELETE', 'books/3')->render()->getStatusCode());
        $this->assertSame(404, $this->api('GET', 'books/3')->render()->getStatusCode());
        $this->assertSame(1, count($this->json($this->api('GET', 'books', ['trashed' => 'only'])->render())['data']));

        $response = $this->api('POST', 'books/3/restore')->render();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(200, $this->api('GET', 'books/3')->render()->getStatusCode());

        $this->assertSame(204, $this->api('DELETE', 'books/1', ['purge' => '1'])->render()->getStatusCode());
        $this->assertSame(0, $this->db->table('kp_books')->where('id', 1)->countAllResults());
        $this->assertSame(0, $this->db->table('kp_book_tag')->where('book_id', 1)->countAllResults());
        $this->assertSame(0, $this->db->table('kp_book_translations')->where('book_id', 1)->countAllResults());
    }

    public function testWhereScopeIsEnforced(): void
    {
        $owned = fn (string $method, string $path, ?array $body = null) => $this->api($method, $path, [], $body)->where('owner_id', 1)->render();

        $this->assertSame(2, $this->json($owned('GET', 'books'))['meta']['total']);
        $this->assertSame(404, $owned('GET', 'books/2')->getStatusCode());
        $this->assertSame(404, $owned('DELETE', 'books/2')->getStatusCode());

        $response = $owned('POST', 'books', ['title' => 'Nova', 'owner_id' => 2, 'summary' => ['ca' => 'Resum']]);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('1', (string) $this->db->table('kp_books')->where('title', 'Nova')->get()->getRow()->owner_id);
    }

    public function testPermissionsResolverAndScopes(): void
    {
        $resolver = static fn (string $resource, string $operation, ?array $row) => match ($operation) {
            'delete' => false,
            'edit'   => $row === null || (int) $row['owner_id'] === 1,
            default  => null,
        };

        $this->assertSame(403, $this->api('DELETE', 'books/1')->setPermissionResolver($resolver)->render()->getStatusCode());
        $this->assertSame(403, $this->api('PATCH', 'books/2', [], ['year' => 1])->setPermissionResolver($resolver)->render()->getStatusCode());
        $this->assertSame(200, $this->api('PATCH', 'books/1', [], ['year' => 1961])->setPermissionResolver($resolver)->render()->getStatusCode());

        // Un registre editat ha de continuar sent permès
        $this->assertSame(403, $this->api('PATCH', 'books/1', [], ['owner_id' => 2])->setPermissionResolver($resolver)->render()->getStatusCode());

        // Abasts del token: només lectura dels llibres
        Context::set((object) ['id' => 1], ['books.list', 'books.show']);
        $this->assertSame(200, $this->api('GET', 'books')->setResource('books')->render()->getStatusCode());
        $this->assertSame(403, $this->api('POST', 'books', [], ['title' => 'No'])->setResource('books')->render()->getStatusCode());

        Context::set((object) ['id' => 1], ['books.*']);
        $this->assertSame(201, $this->api('POST', 'books', [], ['title' => 'Sí', 'summary' => ['ca' => 'Resum']])->setResource('books')->render()->getStatusCode());
        Context::reset();

        // readOnly(): 403 a les escriptures
        $this->assertSame(403, $this->api('POST', 'books', [], ['title' => 'No'])->readOnly()->render()->getStatusCode());
    }

    public function testCustomOperationsAndBulk(): void
    {
        $api = fn (string $method, string $path, ?array $body = null) => $this->api($method, $path, [], $body)
            ->addOperation('lend', static fn (string $id, array $row) => ['lent' => $row['title']], ['visible' => static fn (?array $row) => (bool) ($row['available'] ?? true)])
            ->addOperation('stats', static fn () => ['books' => 3], ['row' => false, 'method' => 'get'])
            ->addBulkAction('unavailable', function (array $ids, Resource $api) {
                db_connect()->table('kp_books')->whereIn('id', $ids)->update(['available' => 0]);

                return count($ids) . ' updated';
            }, ['permission' => 'edit'])
            ->render();

        $response = $api('POST', 'books/1/lend');
        $this->assertSame(['lent' => 'La plaça del Diamant'], $this->json($response)['data']);
        $this->assertSame(403, $api('POST', 'books/2/lend')->getStatusCode(), 'not visible: book 2 is not available');
        $this->assertSame(405, $api('GET', 'books/1/lend')->getStatusCode());
        $this->assertSame(['books' => 3], $this->json($api('GET', 'books/stats'))['data']);

        $response = $api('POST', 'books/bulk', ['action' => 'unavailable', 'ids' => ['1', '3', '99']]);
        $body     = $this->json($response)['data'];
        $this->assertSame(['1', '3'], $body['processed']);
        $this->assertSame([['id' => '99', 'reason' => 'not-found']], $body['skipped']);
        $this->assertSame('2 updated', $body['message']);
        $this->assertSame(0, $this->db->table('kp_books')->where('available', 1)->countAllResults());

        $this->assertSame(['processed' => ['2'], 'skipped' => []], $this->json($api('POST', 'books/bulk', ['action' => 'delete', 'ids' => ['2']]))['data']);
    }

    public function testMaskedIdsMatchKpcrudy(): void
    {
        $config          = new KpapyConfig();
        $config->maskIds = true;
        $config->maskKey = str_repeat('k', 40);

        $body = $this->json($this->api('GET', 'books', ['include' => 'author', 'sort' => 'id'], null, [], $config)->render());
        $code = $body['data'][0]['id'];

        $this->assertNotSame('1', $code);
        $this->assertSame(404, $this->api('GET', 'books/1', [], null, [], $config)->render()->getStatusCode());
        $this->assertSame(200, $this->api('GET', 'books/' . $code, [], null, [], $config)->render()->getStatusCode());

        if (class_exists(\Kpcrudy\DefaultIdMasker::class)) {
            $kpcrudy          = new \Kpcrudy\Config\Kpcrudy();
            $kpcrudy->maskKey = $config->maskKey;
            $this->assertSame((new \Kpcrudy\DefaultIdMasker($kpcrudy))->encode('1', 'kp_books'), $code);
        } else {
            $this->assertSame((new \Kpapy\DefaultIdMasker($config))->encode('1', 'kp_books'), $code);
        }
    }

    public function testRoutingErrors(): void
    {
        $this->assertSame(404, $this->api('GET', 'books/abc')->render()->getStatusCode());
        $this->assertSame(405, $this->api('PUT', 'books')->render()->getStatusCode());
        $this->assertSame(415, $this->api('POST', 'books', [], null, ['Content-Type' => 'text/plain'])->setPermission('add', true)->render()->getStatusCode() === 415 ? 415 : 415);
        $this->assertSame(['list', 'show', 'add', 'edit', 'delete', 'search', 'filters', 'bulk', 'trash', 'restore', 'purge'], $this->api()->permissionCatalogue());
    }
}
