<?php

declare(strict_types=1);

namespace Kpapy\Auth;

use CodeIgniter\HTTP\IncomingRequest;

/**
 * Autentica una petició de l'API. Retorna null si la petició no porta credencials
 * (el filtre respon 401) o llança Kpapy\Http\ApiException si les credencials no són vàlides.
 */
interface Authenticator
{
    /**
     * @return array{user: object, scopes: list<string>|null, token: object|null}|null
     */
    public function authenticate(IncomingRequest $request): ?array;
}
