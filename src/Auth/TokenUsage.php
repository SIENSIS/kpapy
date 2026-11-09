<?php

declare(strict_types=1);

namespace Kpapy\Auth;

/**
 * Darrera adreça IP de cada token (taula kpapy_token_usage, opcional: Config\Kpapy::$trackTokenIps).
 *
 * El filtre 'kpapy' crida record() a cada petició autenticada amb un token. Per no escriure a
 * cada petició, la fila només s'actualitza quan canvia la IP o ha passat més d'un minut.
 */
class TokenUsage
{
    public const TABLE = 'kpapy_token_usage';

    public function record(int $tokenId, int|string|null $userId, string $ip): void
    {
        $db  = db_connect();
        $ip  = mb_substr($ip, 0, 45);
        $now = date('Y-m-d H:i:s');
        $row = $db->table(self::TABLE)->where('token_id', $tokenId)->get()->getRowArray();

        if ($row === null) {
            $db->table(self::TABLE)->insert(['token_id' => $tokenId, 'user_id' => $userId === null ? null : (int) $userId, 'ip_address' => $ip, 'used_at' => $now]);

            return;
        }

        if ($row['ip_address'] !== $ip || strtotime((string) $row['used_at']) < time() - 60) {
            $db->table(self::TABLE)->where('token_id', $tokenId)->update(['ip_address' => $ip, 'used_at' => $now]);
        }
    }

    /**
     * Darrera IP de cada token.
     *
     * @param list<int> $tokenIds
     *
     * @return array<int, string> id del token => IP
     */
    public function lastIps(array $tokenIds): array
    {
        if ($tokenIds === []) {
            return [];
        }

        $rows = db_connect()->table(self::TABLE)->select('token_id, ip_address')->whereIn('token_id', $tokenIds)->get()->getResultArray();

        return array_column($rows, 'ip_address', 'token_id');
    }

    public function forget(int $tokenId): void
    {
        db_connect()->table(self::TABLE)->where('token_id', $tokenId)->delete();
    }
}
