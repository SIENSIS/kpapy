<?php

namespace Kpapy\Demo\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Kpapy\Auth\Challenges;
use Kpapy\Auth\TokenService;
use Kpapy\Demo\Demo;
use Kpapy\Demo\Libraries\DemoTotp;

/**
 * Web pages of the demo: the API console and the "web profile" of the demo users, where
 * the sign-in codes appear. Only for trying the library: anyone can get a demo token here.
 */
class Console extends Controller
{
    public function index(): string
    {
        return view('Kpapy\Demo\Views\console', [
            'api'   => site_url(Demo::API),
            'users' => Demo::USERS,
        ]);
    }

    /**
     * The JavaScript of the console, as an external module (strict CSP: no inline code).
     */
    public function script(): ResponseInterface
    {
        return $this->response
            ->setHeader('Content-Type', 'text/javascript; charset=UTF-8')
            ->setHeader('Cache-Control', 'no-cache')
            ->setBody((string) file_get_contents(__DIR__ . '/../Views/console.js'));
    }

    /**
     * POST token/{admin|reader}: a token for a demo user, with all its permissions or only
     * reading books ({"scopes": "read"}).
     */
    public function token(string $who): ResponseInterface
    {
        $id = Demo::userId($who) ?? throw PageNotFoundException::forPageNotFound();

        helper('auth');

        $user   = auth()->getProvider()->findById($id) ?? throw PageNotFoundException::forPageNotFound();
        $body   = $this->request->getJSON(true) ?? [];
        $scopes = ($body['scopes'] ?? 'all') === 'read' ? ['library-books.list', 'library-books.show'] : ['*'];
        $token  = (new TokenService(Demo::config()))->create($user, 'Demo console', $scopes, DAY);

        return $this->response->setJSON([
            'token'  => $token['token'],
            'user'   => $user->email,
            'scopes' => $token['scopes'],
            'csrf'   => csrf_hash(),
        ]);
    }

    /**
     * The "web profile" of a demo user: pending sign-ins with their codes (reader) or the
     * current code of its authenticator app (admin).
     */
    public function profile(string $who): string
    {
        $id = Demo::userId($who) ?? throw PageNotFoundException::forPageNotFound();

        $totp = null;

        if ($who === 'admin' && isset(Demo::marker()['totp_secret'])) {
            $provider = new DemoTotp();
            $totp     = [
                'code'    => $provider->codeAt(Demo::marker()['totp_secret'], intdiv(time(), 30)),
                'seconds' => 30 - time() % 30,
                'secret'  => Demo::marker()['totp_secret'],
            ];
        }

        return view('Kpapy\Demo\Views\profile', [
            'who'        => $who,
            'email'      => Demo::USERS[$who][0],
            'totp'       => $totp,
            'challenges' => (new Challenges(Demo::config()))->pendingFor($id),
        ]);
    }

    public function deny(string $who, string $challenge): RedirectResponse
    {
        $id = Demo::userId($who) ?? throw PageNotFoundException::forPageNotFound();

        (new Challenges(Demo::config()))->deny($challenge, $id);

        return redirect()->to(Demo::url('profile/' . $who));
    }
}
