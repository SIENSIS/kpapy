<?php

namespace Kpapy\Demo\Libraries;

use Kpapy\Auth\TwoFactor\TotpProvider;
use Kpapy\Demo\Demo;

/**
 * TOTP for the demo admin. The secret is stored in the demo marker; its profile page
 * shows the current code, as an authenticator app would.
 */
class DemoTotp extends TotpProvider
{
    protected function secretFor(object $user): ?string
    {
        return (string) $user->id === (string) Demo::userId('admin') ? (Demo::marker()['totp_secret'] ?? null) : null;
    }
}
