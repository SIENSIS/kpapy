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

        if (! ctype_digit($id) || ! (new TokenService())->revoke($user, (int) $id)) {
            return $responder->problem(ApiException::notFound());
        }

        return $responder->noContent();
    }

    protected function responder(): Responder
    {
        $config = config('Kpapy');

        return new Responder($config instanceof KpapyConfig ? $config : new KpapyConfig(), $this->response);
    }
}
