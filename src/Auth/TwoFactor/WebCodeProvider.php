<?php

declare(strict_types=1);

namespace Kpapy\Auth\TwoFactor;

use Kpapy\Auth\Challenges;

/**
 * Per als usuaris sense cap altre segon factor: el codi apareix al perfil de
 * l'aplicació web (Challenges::pendingFor() i la vista Kpapy\Views\pending_logins).
 * Tenir una sessió web oberta és el segon factor.
 */
class WebCodeProvider implements TwoFactorProvider
{
    public function isEnabledFor(object $user): bool
    {
        return true;
    }

    public function start(object $user, array $challenge, Challenges $challenges): string
    {
        $challenges->issueCode($challenge['id'], true);

        return lang('Kpapy.auth.useWeb');
    }

    public function verify(object $user, array $challenge, string $code, Challenges $challenges): bool
    {
        return $challenges->codeMatches($challenge, $code);
    }
}
