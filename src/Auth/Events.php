<?php

declare(strict_types=1);

namespace Kpapy\Auth;

/**
 * Esdeveniments d'autenticació per al registre d'activitat de l'aplicació:
 * kpapy.auth.challenge, kpapy.auth.login, kpapy.auth.failed, kpapy.auth.logout,
 * kpapy.auth.denied.
 *
 *     Events::on('kpapy.auth.login', static fn (array $data) => log_message('info', ...));
 */
final class Events
{
    /**
     * @param array<string, mixed> $data
     */
    public static function fire(string $name, array $data): void
    {
        \CodeIgniter\Events\Events::trigger($name, $data);
    }
}
