<?php

declare(strict_types=1);

namespace Kpapy\Demo;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use CodeIgniter\Shield\Entities\User;
use Kpapy\Auth\TwoFactor\TotpProvider;
use Kpapy\Demo\Database\Seeds\DemoSeeder;
use RuntimeException;

/**
 * Installs and removes the demo without touching the project's files: tables
 * kpapy_demo_*, the demo users in Shield (and their tokens), the uploaded covers and
 * the marker file writable/kpapy-demo.json.
 *
 * The demo migrations are run directly (not through the migration history), so
 * uninstalling never rolls back the project's migrations.
 */
class Installer
{
    protected BaseConnection $db;

    public function __construct()
    {
        $this->db = db_connect();
        helper('auth');
    }

    /**
     * @return list<string>
     */
    public function existingTables(): array
    {
        return array_values(array_filter(Demo::TABLES, fn (string $table): bool => $this->db->tableExists($table)));
    }

    public function install(bool $allowProduction = false): void
    {
        if (! function_exists('auth') || ! $this->db->tableExists('users') || ! $this->db->tableExists('auth_identities')) {
            throw new RuntimeException('The demo needs CodeIgniter Shield installed and migrated (php spark shield:setup, php spark migrate --all).');
        }

        if (! $this->db->tableExists('kpapy_login_challenges')) {
            throw new RuntimeException('Run the kpapy migrations first: php spark migrate -n Kpapy');
        }

        if ($this->existingTables() !== []) {
            throw new RuntimeException('Some demo tables already exist. Use --force to reinstall.');
        }

        $users = $this->createUsers();

        // The marker is written first: the demo configuration reads the mask key from it
        Demo::writeMarker([
            'installed_at'     => date(DATE_ATOM),
            'allow_production' => $allowProduction,
            'mask_key'         => bin2hex(random_bytes(32)),
            'totp_secret'      => $this->randomBase32(32),
            'users'            => $users,
        ]);

        $this->createAndSeed($users);
    }

    public function reset(): void
    {
        $marker = Demo::marker();
        $this->dropTables();
        $this->removeFiles();
        $this->createAndSeed($marker['users'] ?? []);
    }

    public function uninstall(): void
    {
        $marker = Demo::marker();

        $this->dropTables();
        $this->removeFiles();
        $this->deleteUsers(array_values($marker['users'] ?? []));

        @unlink(Demo::markerFile());
    }

    /**
     * @param array<string, int> $users
     */
    protected function createAndSeed(array $users): void
    {
        foreach ($this->migrations() as $migration) {
            $migration->up();
        }

        $seeder          = new DemoSeeder(config('Database'));
        $seeder->userIds = $users;
        $seeder->run();
    }

    protected function dropTables(): void
    {
        foreach (array_reverse($this->migrations()) as $migration) {
            $migration->down();
        }
    }

    /**
     * @return array<string, int>
     */
    protected function createUsers(): array
    {
        $provider = auth()->getProvider();
        $ids      = [];

        foreach (Demo::USERS as $key => [$email, $username, $password]) {
            $existing = $provider->findByCredentials(['email' => $email]);

            if ($existing !== null) {
                $ids[$key] = (int) $existing->id;

                continue;
            }

            $provider->save(new User(['username' => $username, 'email' => $email, 'password' => $password, 'active' => 1]));
            $ids[$key] = (int) $provider->getInsertID();
        }

        return $ids;
    }

    /**
     * @param list<int> $ids
     */
    protected function deleteUsers(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->db->table('kpapy_login_challenges')->whereIn('user_id', $ids)->delete();
        auth()->getProvider()->delete($ids, true);
        $this->db->table('auth_identities')->whereIn('user_id', $ids)->delete();
    }

    protected function removeFiles(): void
    {
        $dir = WRITEPATH . 'uploads/kpapy-demo';

        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }

    /**
     * @return list<Migration>
     */
    protected function migrations(): array
    {
        $files = glob(__DIR__ . '/Database/Migrations/*.php') ?: [];
        sort($files);

        $migrations = [];

        foreach ($files as $file) {
            require_once $file;
            $class        = __NAMESPACE__ . '\\Database\\Migrations\\' . preg_replace('/^[\d-]+_/', '', basename($file, '.php'));
            $migrations[] = new $class();
        }

        return $migrations;
    }

    protected function randomBase32(int $length): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret   = '';

        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }

        return $secret;
    }
}
