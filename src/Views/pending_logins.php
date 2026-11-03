<?php
/**
 * Inicis de sessió pendents de les apps (mètode 'web'): AuthRoutes::pendingLogins().
 * Marques Bootstrap 5; sense JavaScript ni estils en línia (CSP). El codi es mostra
 * dins un <details>, perquè no quedi a la vista per defecte.
 *
 * @var list<array{id: string, device: string, ip_address: string, created_at: string, expires_at: string, code: string}> $challenges
 * @var string $denyBase
 */
?>
<section class="kpapy-pending-logins">
    <h2 class="h5"><?= esc(lang('Kpapy.auth.pendingTitle')) ?></h2>
    <?php if ($challenges === []): ?>
        <p class="text-body-secondary"><?= esc(lang('Kpapy.auth.pendingNone')) ?></p>
    <?php else: ?>
        <p><?= esc(lang('Kpapy.auth.pendingIntro')) ?></p>
        <ul class="list-group mb-3">
            <?php foreach ($challenges as $challenge): ?>
                <li class="list-group-item d-flex flex-wrap align-items-center gap-3">
                    <div class="flex-grow-1">
                        <div><strong><?= esc(lang('Kpapy.auth.pendingDevice')) ?>:</strong> <?= esc($challenge['device']) ?> · <?= esc($challenge['ip_address']) ?></div>
                        <div class="small text-body-secondary"><?= esc(lang('Kpapy.auth.pendingWhen')) ?>: <?= esc($challenge['created_at']) ?></div>
                    </div>
                    <details>
                        <summary class="btn btn-sm btn-outline-primary"><?= esc(lang('Kpapy.auth.pendingShow')) ?></summary>
                        <div class="fs-3 font-monospace mt-2" aria-label="<?= esc(lang('Kpapy.auth.pendingCode'), 'attr') ?>"><?= esc(chunk_split($challenge['code'], 3, ' ')) ?></div>
                    </details>
                    <form method="post" action="<?= esc(site_url($denyBase . '/api-logins/' . $challenge['id'] . '/deny'), 'attr') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger"><?= esc(lang('Kpapy.auth.pendingDeny')) ?></button>
                    </form>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
</section>
