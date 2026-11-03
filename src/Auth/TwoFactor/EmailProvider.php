<?php

declare(strict_types=1);

namespace Kpapy\Auth\TwoFactor;

use Kpapy\Auth\Challenges;

/**
 * Codi de 6 xifres per correu electrònic.
 *
 * Per defecte està actiu per als usuaris si Shield té el segon factor per correu
 * (Config\Auth::$actions['login'] = Email2FA). Una aplicació on cada usuari tria el
 * mètode pot estendre aquesta classe i redefinir isEnabledFor().
 */
class EmailProvider implements TwoFactorProvider
{
    public function isEnabledFor(object $user): bool
    {
        $actions = config('Auth')->actions ?? [];

        return ($actions['login'] ?? null) === \CodeIgniter\Shield\Authentication\Actions\Email2FA::class && ! empty($user->email);
    }

    public function start(object $user, array $challenge, Challenges $challenges): string
    {
        $code    = $challenges->issueCode($challenge['id'], false);
        $minutes = (int) ceil((strtotime($challenge['expires_at']) - time()) / 60);
        $email   = service('email');

        $email->setTo((string) $user->email)
            ->setSubject(lang('Kpapy.auth.emailSubject'))
            ->setMessage(lang('Kpapy.auth.emailBody', [$code, $minutes]));

        if (! $email->send(false)) {
            log_message('error', '[kpapy] The sign-in code could not be emailed: {error}', ['error' => $email->printDebugger(['headers'])]);
        }

        return lang('Kpapy.auth.codeSent');
    }

    public function verify(object $user, array $challenge, string $code, Challenges $challenges): bool
    {
        return $challenges->codeMatches($challenge, $code);
    }
}
