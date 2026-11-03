<?php
/**
 * "Web profile" of a demo user.
 *
 * @var string                    $who
 * @var string                    $email
 * @var array<string, mixed>|null $totp
 * @var list<array<string, string>> $challenges
 */
?>
<?= view('Kpapy\Demo\Views\layout_head', ['title' => 'Web profile']) ?>
<main class="container pb-5">
    <h1 class="h3">Web profile of <?= esc($email) ?></h1>
    <p class="text-body-secondary">In a real application this is the user's profile page, after signing in to the website. Reload the page to update it.</p>

    <?php if ($totp !== null): ?>
        <div class="card mb-4">
            <div class="card-header">Authenticator app (simulated)</div>
            <div class="card-body">
                <p>This user signs in from apps with an authenticator app (TOTP). This is the code the app would show now:</p>
                <div class="kp-code font-monospace"><?= esc($totp['code']) ?></div>
                <p class="small text-body-secondary mb-0">Valid for <?= (int) $totp['seconds'] ?> more seconds. Secret (to add it to a real app): <code><?= esc($totp['secret']) ?></code></p>
            </div>
        </div>
    <?php endif ?>

    <div class="card">
        <div class="card-header">Pending app sign-ins</div>
        <div class="card-body">
            <?php if ($challenges === []): ?>
                <p class="mb-0 text-body-secondary">There are no pending sign-ins. Users without two-factor authentication confirm their app sign-ins here.</p>
            <?php else: ?>
                <ul class="list-group">
                    <?php foreach ($challenges as $challenge): ?>
                        <li class="list-group-item d-flex flex-wrap gap-3 align-items-center">
                            <div class="flex-grow-1">
                                <div><strong><?= esc($challenge['device']) ?></strong> · <?= esc($challenge['ip_address']) ?></div>
                                <div class="small text-body-secondary">Requested <?= esc($challenge['created_at']) ?></div>
                            </div>
                            <details>
                                <summary class="btn btn-sm btn-outline-primary">Show code</summary>
                                <div class="kp-code font-monospace"><?= esc($challenge['code']) ?></div>
                            </details>
                            <form method="post" action="<?= site_url('kpapy-demo/profile/' . $who . '/deny/' . $challenge['id']) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" type="submit">It was not me</button>
                            </form>
                        </li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        </div>
    </div>
</main>
</body>
</html>
