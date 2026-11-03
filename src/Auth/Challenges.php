<?php

declare(strict_types=1);

namespace Kpapy\Auth;

use Kpapy\Config\Kpapy as KpapyConfig;
use Kpapy\Secret;

/**
 * Reptes de segon factor de l'inici de sessió de les apps (taula kpapy_login_challenges).
 *
 * El codi es desa amb un HMAC per verificar-lo. Al mètode 'web' també es desa xifrat,
 * perquè el perfil web el pugui mostrar al seu propietari.
 */
class Challenges
{
    protected const TABLE = 'kpapy_login_challenges';

    public function __construct(protected KpapyConfig $config)
    {
    }

    /**
     * @param list<string> $scopes
     *
     * @return array<string, mixed> El repte creat
     */
    public function create(int|string $userId, string $method, string $device, string $ip, array $scopes): array
    {
        $this->purgeExpired();

        $row = [
            'id'         => bin2hex(random_bytes(24)),
            'user_id'    => (int) $userId,
            'method'     => $method,
            'device'     => mb_substr($device, 0, 100),
            'ip_address' => mb_substr($ip, 0, 45),
            'scopes'     => json_encode(array_values($scopes)),
            'attempts'   => 0,
            'status'     => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', time() + $this->config->challengeLifetime),
        ];

        db_connect()->table(self::TABLE)->insert($row);

        return $row;
    }

    /**
     * Repte pendent i vigent, o null.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        if (! preg_match('/^[a-f0-9]{48}$/', $id)) {
            return null;
        }

        $row = db_connect()->table(self::TABLE)->where('id', $id)->get()->getRowArray();

        return $row !== null && $row['status'] === 'pending' && strtotime($row['expires_at']) >= time() ? $row : null;
    }

    /**
     * Genera un codi de 6 xifres per al repte i el retorna (correu i web).
     */
    public function issueCode(string $id, bool $displayable): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        db_connect()->table(self::TABLE)->where('id', $id)->update([
            'code_hash'   => $this->hash($code),
            'code_secret' => $displayable ? $this->encrypt($code) : null,
        ]);

        return $code;
    }

    /**
     * @param array<string, mixed> $challenge
     */
    public function codeMatches(array $challenge, string $code): bool
    {
        return is_string($challenge['code_hash']) && hash_equals($challenge['code_hash'], $this->hash(trim($code)));
    }

    /**
     * Compta un intent fallit; amb massa intents, el repte queda denegat. Retorna si encara és vàlid.
     */
    public function fail(string $id): bool
    {
        $db  = db_connect();
        $row = $db->table(self::TABLE)->where('id', $id)->get()->getRowArray();

        if ($row === null) {
            return false;
        }

        $attempts = (int) $row['attempts'] + 1;
        $status   = $attempts >= $this->config->challengeAttempts ? 'denied' : 'pending';

        $db->table(self::TABLE)->where('id', $id)->update(['attempts' => $attempts, 'status' => $status]);

        return $status === 'pending';
    }

    public function complete(string $id): void
    {
        db_connect()->table(self::TABLE)->where('id', $id)->update(['status' => 'verified', 'code_secret' => null]);
    }

    /**
     * L'usuari denega l'inici de sessió des del perfil web.
     */
    public function deny(string $id, int|string $userId): bool
    {
        $db = db_connect();
        $db->table(self::TABLE)->where('id', $id)->where('user_id', (int) $userId)->where('status', 'pending')
            ->update(['status' => 'denied', 'code_secret' => null]);

        return $db->affectedRows() > 0;
    }

    /**
     * Inicis de sessió pendents d'un usuari amb el codi en clar (mètode 'web'), per al perfil web.
     *
     * @return list<array{id: string, device: string, ip_address: string, created_at: string, expires_at: string, code: string}>
     */
    public function pendingFor(int|string $userId): array
    {
        $rows = db_connect()->table(self::TABLE)
            ->where('user_id', (int) $userId)->where('status', 'pending')->where('method', 'web')
            ->where('expires_at >=', date('Y-m-d H:i:s'))
            ->orderBy('created_at', 'desc')->get()->getResultArray();

        $result = [];

        foreach ($rows as $row) {
            $code = $this->decrypt((string) $row['code_secret']);

            if ($code !== null) {
                $result[] = [
                    'id' => $row['id'], 'device' => $row['device'], 'ip_address' => $row['ip_address'],
                    'created_at' => $row['created_at'], 'expires_at' => $row['expires_at'], 'code' => $code,
                ];
            }
        }

        return $result;
    }

    public function purgeExpired(): void
    {
        db_connect()->table(self::TABLE)->where('expires_at <', date('Y-m-d H:i:s', time() - 86400))->delete();
    }

    protected function hash(string $code): string
    {
        return hash_hmac('sha256', $code, Secret::key($this->config, 'api-challenge-hash'));
    }

    protected function encrypt(string $code): string
    {
        $iv     = random_bytes(12);
        $cipher = openssl_encrypt($code, 'aes-256-gcm', Secret::key($this->config, 'api-challenge-encrypt'), OPENSSL_RAW_DATA, $iv, $tag);

        return base64_encode($iv . $tag . $cipher);
    }

    protected function decrypt(string $value): ?string
    {
        $raw = base64_decode($value, true);

        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        $code = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', Secret::key($this->config, 'api-challenge-encrypt'), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        return $code === false ? null : $code;
    }
}
