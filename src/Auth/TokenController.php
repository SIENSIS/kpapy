<?php

declare(strict_types=1);

namespace Kpapy\Auth;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Kpapy\Config\Kpapy as KpapyConfig;
use Kpapy\Http\ApiException;
use Kpapy\Http\Responder;

/**
 * Tokens de l'usuari autenticat: GET me/tokens i DELETE me/tokens/{id}.
 * Els tokens nous es creen al perfil web (TokenService), no des de l'API: així un token
 * filtrat no en pot crear d'altres.
 *
 * Un token amb abasts limitats necessita l'abast Config\Kpapy::$tokensScope ("tokens.manage")
 * per fer servir aquestes rutes (Config\Kpapy::$tokensScopeRequired).
 */
class TokenController extends Controller
{
    public function index(): ResponseInterface
    {
        $responder = $this->responder();
        $user      = Context::user();

        if ($user === null) {
            return $responder->problem(ApiException::unauthorized());
        }

        if (! $this->canManageTokens()) {
            return $responder->problem(ApiException::forbidden(lang('Kpapy.auth.tokensScope', [$this->kpapy()->tokensScope])));
        }

        $tokens = (new TokenService())->list($user);

        return $responder->collection($tokens, ['total' => count($tokens)], []);
    }

    public function delete(string $id = ''): ResponseInterface
    {
        $responder = $this->responder();
        $user      = Context::user();

        if ($user === null) {
            return $responder->problem(ApiException::unauthorized());
        }

        if (! $this->canManageTokens()) {
            return $responder->problem(ApiException::forbidden(lang('Kpapy.auth.tokensScope', [$this->kpapy()->tokensScope])));
        }

        if (! ctype_digit($id) || ! (new TokenService())->revoke($user, (int) $id)) {
            return $responder->problem(ApiException::notFound());
        }

        return $responder->noContent();
    }

    /**
     * El token de la petició pot llistar i revocar els tokens de l'usuari: sense token (sessió
     * web o autenticador sense abasts), amb abasts il·limitats ("*"), amb l'abast
     * Config\Kpapy::$tokensScope o el comodí del seu prefix ("tokens.*").
     */
    protected function canManageTokens(): bool
    {
        $config = $this->kpapy();
        $scopes = Context::scopes();

        if (! $config->tokensScopeRequired || ! $config->checkScopes || $scopes === null) {
            return true;
        }

        $scope      = $config->tokensScope;
        $candidates = ['*', $scope];

        if (($dot = strrpos($scope, '.')) !== false) {
            $candidates[] = substr($scope, 0, $dot) . '.*';
        }

        return array_intersect($candidates, $scopes) !== [];
    }

    protected function responder(): Responder
    {
        return new Responder($this->kpapy(), $this->response);
    }

    protected function kpapy(): KpapyConfig
    {
        $config = config('Kpapy');

        return $config instanceof KpapyConfig ? $config : new KpapyConfig();
    }
}
