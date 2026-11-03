<?php

declare(strict_types=1);

namespace Kpapy\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Kpapy\Auth\TokenService;
use Throwable;

/**
 * php spark kpapy:token <email|id> [--name] [--scopes a,b] [--days 90]
 */
class Token extends BaseCommand
{
    protected $group       = 'Kpapy';
    protected $name        = 'kpapy:token';
    protected $description = 'Creates an access token for a user (e.g. for a server-to-server integration).';
    protected $usage       = 'kpapy:token <email|id> [options]';
    protected $arguments   = ['user' => 'Email or id of the user'];
    protected $options     = [
        '--name'   => 'Name of the token (default: Console)',
        '--scopes' => 'Comma-separated scopes: resource.operation, resource.* or * (default: *)',
        '--days'   => 'Days until it expires (0 = never; default: the configured token lifetime)',
    ];

    public function run(array $params)
    {
        helper('auth');

        $key = $params[0] ?? '';

        if ($key === '' || ! function_exists('auth')) {
            CLI::error('Usage: php spark kpapy:token <email|id> (requires CodeIgniter Shield).');

            return EXIT_ERROR;
        }

        $provider = auth()->getProvider();
        $user     = ctype_digit($key) ? $provider->findById((int) $key) : $provider->findByCredentials(['email' => $key]);

        if ($user === null) {
            CLI::error("User \"{$key}\" not found.");

            return EXIT_ERROR;
        }

        $scopes = array_filter(array_map('trim', explode(',', (string) (CLI::getOption('scopes') ?: '*'))));
        $days   = CLI::getOption('days');

        try {
            $token = (new TokenService())->create($user, (string) (CLI::getOption('name') ?: 'Console'), array_values($scopes), is_numeric($days) ? (int) $days * DAY : null);
        } catch (Throwable $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write('Token for ' . ($user->email ?? $user->id) . ' (copy it now, it will not be shown again):', 'green');
        CLI::write($token['token']);
        CLI::write('Scopes:  ' . implode(', ', $token['scopes']));
        CLI::write('Expires: ' . ($token['expires_at'] ?? 'never'));

        return EXIT_SUCCESS;
    }
}
