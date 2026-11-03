<?php

declare(strict_types=1);

namespace Kpapy\Auth\TwoFactor;

use Kpapy\Auth\Challenges;

/**
 * Codis d'una aplicació d'autenticació (TOTP, RFC 6238: Google Authenticator, Aegis...).
 *
 * kpapy no sap on desa cada aplicació el secret TOTP dels usuaris: estén aquesta classe
 * i redefineix secretFor() (i, si en tens, verifyRecoveryCode()), i posa-la a
 * Config\Kpapy::$twoFactorProviders['totp']. Sense secret, el mètode no està actiu.
 */
class TotpProvider implements TwoFactorProvider
{
    protected int $digits = 6;
    protected int $period = 30;
    protected int $window = 1;

    /**
     * Secret TOTP (base32) de l'usuari, o null si no té el mètode activat.
     */
    protected function secretFor(object $user): ?string
    {
        return null;
    }

    /**
     * Codi de recuperació d'un sol ús (l'aplicació l'ha de marcar com a usat).
     */
    protected function verifyRecoveryCode(object $user, string $code): bool
    {
        return false;
    }

    /**
     * Darrer pas de temps acceptat per a l'usuari (per refusar codis reutilitzats).
     * Redefineix-lo junt amb rememberStep() per desar-lo.
     */
    protected function lastStep(object $user): ?int
    {
        return null;
    }

    protected function rememberStep(object $user, int $step): void
    {
    }

    public function isEnabledFor(object $user): bool
    {
        return $this->secretFor($user) !== null;
    }

    public function start(object $user, array $challenge, Challenges $challenges): string
    {
        return lang('Kpapy.auth.useApp');
    }

    public function verify(object $user, array $challenge, string $code, Challenges $challenges): bool
    {
        $code   = preg_replace('/\s+/', '', $code) ?? '';
        $secret = $this->secretFor($user);

        if ($secret !== null && preg_match('/^\d{' . $this->digits . '}$/', $code)) {
            $current = intdiv(time(), $this->period);
            $last    = $this->lastStep($user);

            for ($step = $current - $this->window; $step <= $current + $this->window; $step++) {
                if (($last === null || $step > $last) && hash_equals($this->codeAt($secret, $step), $code)) {
                    $this->rememberStep($user, $step);

                    return true;
                }
            }
        }

        return $this->verifyRecoveryCode($user, $code);
    }

    /**
     * Codi TOTP d'un pas de temps (HMAC-SHA1, RFC 4226 / 6238).
     */
    public function codeAt(string $secret, int $step): string
    {
        $key    = self::base32Decode($secret);
        $hash   = hash_hmac('sha1', pack('N*', 0, $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value  = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** $this->digits)), $this->digits, '0', STR_PAD_LEFT);
    }

    public static function base32Decode(string $value): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $value    = strtoupper(rtrim(preg_replace('/\s+/', '', $value) ?? '', '='));
        $bits     = '';

        foreach (str_split($value) as $char) {
            $index = strpos($alphabet, $char);

            if ($index === false) {
                return '';
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr((int) bindec($byte));
            }
        }

        return $bytes;
    }
}
