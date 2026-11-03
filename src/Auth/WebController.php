<?php

declare(strict_types=1);

namespace Kpapy\Auth;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RedirectResponse;
use Kpapy\Config\Kpapy as KpapyConfig;

/**
 * Accions del perfil web (sessió de Shield, amb CSRF): denegar un inici de sessió
 * pendent d'una app. Ruta: AuthRoutes::web($routes, 'profile', ['filter' => 'session']).
 */
class WebController extends Controller
{
    public function deny(string $id = ''): RedirectResponse
    {
        helper('auth');

        $userId = auth()->id();

        if ($userId !== null && (new Challenges($this->config()))->deny($id, $userId)) {
            Events::fire('kpapy.auth.denied', ['user_id' => $userId, 'challenge' => $id]);
        }

        return redirect()->back();
    }

    protected function config(): KpapyConfig
    {
        $config = config('Kpapy');

        return $config instanceof KpapyConfig ? $config : new KpapyConfig();
    }
}
