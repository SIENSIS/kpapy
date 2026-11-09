<?php

namespace Tests\Kpapy;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Kpapy\Auth\AuthRoutes;
use Kpapy\Auth\Challenges;
use Kpapy\Auth\Context;
use Kpapy\Auth\TokenService;
use Kpapy\Auth\TwoFactor\TotpProvider;
use Kpapy\Config\Kpapy as KpapyConfig;
use Kpapy\Resource;

/**
 * Autenticació amb tokens de Shield, abasts, inici de sessió de les apps amb el codi del
 * perfil web i amb TOTP, renovació i tancament de sessió, CORS i límit de peticions.
 *
 * @internal
 */
final class AuthTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Context::reset();
        service('throttler')->remove('kpapy-ip' . md5('0.0.0.0') . '-login');

        $forge = \Config\Database::forge($this->DBGroup);
        $forge->dropTable('kp_notes', true);
        $forge->addField([
            'id'    => ['type' => 'INTEGER', 'auto_increment' => true],
            'title' => ['type' => 'VARCHAR', 'constraint' => 80],
        ])->addPrimaryKey('id')->createTable('kp_notes');
        $this->db->table('kp_notes')->insert(['title' => 'Hello']);

        $users = auth()->getProvider();
        $users->save(new User(['username' => 'ana', 'email' => 'ana@example.com', 'password' => 'Secret-123-pass', 'active' => 1]));
        $this->user = $users->findById($users->getInsertID());

        $routes = service('routes');
        $routes->resetRoutes();
        AuthRoutes::register($routes, 'api/v1');
        Resource::routes($routes, 'api/v1/notes', static fn () => (new Resource())->setTable('kp_notes')->setResource('notes')->render(), ['filter' => 'kpapy']);
        Resource::routes($routes, 'api/v1/public-notes', static fn () => (new Resource())->setTable('kp_notes')->readOnly()->render(), ['filter' => 'kpapy:public']);
        $this->routes = $routes;
    }

    private function login(string $password = 'Secret-123-pass', array $scopes = []): array
    {
        $response = $this->withBodyFormat('json')->post('api/v1/auth/token', ['email' => 'ana@example.com', 'password' => $password, 'device_name' => 'Phone', 'scopes' => $scopes]);

        return [$response->response()->getStatusCode(), json_decode($response->response()->getBody() ?: "{}", true)];
    }

    public function testLoginWithTheWebCodeAndUseTheToken(): void
    {
        [$status, $body] = $this->login('wrong-password');
        $this->assertSame(401, $status);
        $this->assertSame('invalid-credentials', $body['code']);

        [$status, $body] = $this->login();
        $this->assertSame(202, $status);
        $this->assertSame('web', $body['data']['method'], 'without 2FA, the code is shown in the web profile');

        $pending = (new Challenges(new KpapyConfig()))->pendingFor($this->user->id);
        $this->assertCount(1, $pending);
        $this->assertSame('Phone', $pending[0]['device']);

        $wrong = $this->withBodyFormat('json')->post('api/v1/auth/token/verify', ['challenge' => $body['data']['challenge'], 'code' => '000000' === $pending[0]['code'] ? '111111' : '000000']);
        $wrong->assertStatus(422);

        $ok    = $this->withBodyFormat('json')->post('api/v1/auth/token/verify', ['challenge' => $body['data']['challenge'], 'code' => $pending[0]['code']]);
        $token = json_decode($ok->response()->getBody(), true)['data'];
        $ok->assertStatus(201);
        $this->assertSame('Bearer', $token['token_type']);
        $this->assertSame([], (new Challenges(new KpapyConfig()))->pendingFor($this->user->id), 'the challenge is used');

        // El repte no es pot reutilitzar
        $this->withBodyFormat('json')->post('api/v1/auth/token/verify', ['challenge' => $body['data']['challenge'], 'code' => $pending[0]['code']])->assertStatus(422);

        // Amb el token
        $this->get('api/v1/notes')->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Bearer ' . $token['token']])->get('api/v1/notes')->assertStatus(200);
        $this->withHeaders(['Authorization' => 'Bearer not-a-token'])->get('api/v1/notes')->assertStatus(401);
        $this->get('api/v1/public-notes')->assertStatus(200);

        // Llista de tokens, renovació i tancament de sessió
        $list = json_decode($this->withHeaders(['Authorization' => 'Bearer ' . $token['token']])->get('api/v1/me/tokens')->response()->getBody(), true);
        $this->assertTrue($list['data'][0]['current']);

        $refreshed = json_decode($this->withHeaders(['Authorization' => 'Bearer ' . $token['token']])->post('api/v1/auth/token/refresh')->response()->getBody(), true)['data']['token'];
        $this->withHeaders(['Authorization' => 'Bearer ' . $token['token']])->get('api/v1/notes')->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Bearer ' . $refreshed])->delete('api/v1/auth/token')->assertStatus(204);
        $this->withHeaders(['Authorization' => 'Bearer ' . $refreshed])->get('api/v1/notes')->assertStatus(401);
    }

    public function testDeniedFromTheWebProfile(): void
    {
        [, $body] = $this->login();
        $pending  = (new Challenges(new KpapyConfig()))->pendingFor($this->user->id);

        // The web profile view shows the device, the code and a deny form
        $html = html_entity_decode(AuthRoutes::pendingLogins($this->user->id, 'profile'));
        $this->assertStringContainsString('Phone', $html);
        $this->assertStringContainsString(chunk_split($pending[0]['code'], 3, ' '), $html);
        $this->assertStringContainsString('/profile/api-logins/' . $pending[0]['id'] . '/deny', $html);

        $this->assertTrue((new Challenges(new KpapyConfig()))->deny($pending[0]['id'], $this->user->id));
        $this->withBodyFormat('json')->post('api/v1/auth/token/verify', ['challenge' => $body['data']['challenge'], 'code' => $pending[0]['code']])->assertStatus(422);
    }

    public function testTokenScopesLimitTheOperations(): void
    {
        $token = (new TokenService())->create($this->user, 'Read only', ['notes.list', 'notes.show'])['token'];
        $auth  = ['Authorization' => 'Bearer ' . $token];

        $this->withHeaders($auth)->get('api/v1/notes')->assertStatus(200);
        $this->withHeaders($auth)->withBodyFormat('json')->post('api/v1/notes', ['title' => 'New'])->assertStatus(403);
        $this->assertSame(['notes.list', 'notes.show'], TokenService::cleanScopes(['notes.list', 'NOT VALID', 'notes.show']));
    }

    public function testTotpCodes(): void
    {
        $provider = new class () extends TotpProvider {
            protected function secretFor(object $user): ?string
            {
                return 'JBSWY3DPEHPK3PXP';
            }
        };

        // Vector de prova de l'RFC 6238 (secret "12345678901234567890", pas 1)
        $rfc = new TotpProvider();
        $this->assertSame('287082', $rfc->codeAt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1));

        $challenges = new Challenges(new KpapyConfig());
        $code       = $provider->codeAt('JBSWY3DPEHPK3PXP', intdiv(time(), 30));

        $this->assertTrue($provider->isEnabledFor($this->user));
        $this->assertTrue($provider->verify($this->user, [], $code, $challenges));
        $this->assertFalse($provider->verify($this->user, [], '123456' === $code ? '654321' : '123456', $challenges));
    }

    public function testRateLimitAndCors(): void
    {
        $config                 = config('Kpapy');
        $config->rateLimitLogin = 2;
        $config->corsOrigins    = ['https://app.example.com'];

        $this->login('bad');
        $this->login('bad');
        [$status] = $this->login('bad');
        $this->assertSame(429, $status);

        $response = $this->withHeaders(['Origin' => 'https://app.example.com'])->get('api/v1/public-notes');
        $this->assertSame('https://app.example.com', $response->response()->getHeaderLine('Access-Control-Allow-Origin'));

        $response = $this->withHeaders(['Origin' => 'https://evil.example.com'])->get('api/v1/public-notes');
        $this->assertSame('', $response->response()->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testCustomLoginAndTokenControllers(): void
    {
        $routes = service('routes');
        $routes->resetRoutes();
        AuthRoutes::register($routes, 'api/v2', [], CustomLoginController::class, CustomTokenController::class);

        $this->assertSame('\\' . CustomLoginController::class . '::token', $routes->getRoutes('POST')['api/v2/auth/token']);
        $this->assertSame('\\' . CustomLoginController::class . '::refresh', $routes->getRoutes('POST')['api/v2/auth/token/refresh']);
        $this->assertSame('\\' . CustomTokenController::class . '::index', $routes->getRoutes('GET')['api/v2/me/tokens']);

        // From the configuration
        $config                  = config('Kpapy');
        $config->loginController = CustomLoginController::class;

        try {
            $routes->resetRoutes();
            AuthRoutes::register($routes, 'api/v3');
            $this->assertSame('\\' . CustomLoginController::class . '::verify', $routes->getRoutes('POST')['api/v3/auth/token/verify']);
            $this->assertSame('\\Kpapy\\Auth\\TokenController::index', $routes->getRoutes('GET')['api/v3/me/tokens']);
        } finally {
            $config->loginController = \Kpapy\Auth\LoginController::class;
        }

        $this->expectException(\InvalidArgumentException::class);
        AuthRoutes::register($routes, 'api/v4', [], \stdClass::class);
    }
}

final class CustomLoginController extends \Kpapy\Auth\LoginController
{
}

final class CustomTokenController extends \Kpapy\Auth\TokenController
{
}
