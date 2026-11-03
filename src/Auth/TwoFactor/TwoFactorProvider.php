<?php

declare(strict_types=1);

namespace Kpapy\Auth\TwoFactor;

use Kpapy\Auth\Challenges;

/**
 * Mètode de segon factor de l'inici de sessió de les apps.
 */
interface TwoFactorProvider
{
    /**
     * Si l'usuari té aquest mètode actiu.
     */
    public function isEnabledFor(object $user): bool;

    /**
     * Comença el repte (p. ex. envia el codi per correu). Retorna un missatge per a l'app.
     *
     * @param array<string, mixed> $challenge
     */
    public function start(object $user, array $challenge, Challenges $challenges): string;

    /**
     * Comprova el codi que ha escrit l'usuari.
     *
     * @param array<string, mixed> $challenge
     */
    public function verify(object $user, array $challenge, string $code, Challenges $challenges): bool;
}
