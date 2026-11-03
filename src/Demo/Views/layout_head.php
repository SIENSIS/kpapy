<?php
/**
 * @var string $title
 */
$nonce = csp_style_nonce();
?>
<!doctype html>
<html lang="en" data-bs-theme="auto">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-header" content="<?= esc(config('Security')->headerName, 'attr') ?>">
    <meta name="csrf-hash" content="<?= esc(csrf_hash(), 'attr') ?>">
    <title><?= esc($title) ?> · kpapy demo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style <?= $nonce ?>>
        pre.kp-response { max-height: 60vh; overflow: auto; font-size: .85rem; }
        .kp-code { font-size: 2.5rem; letter-spacing: .3em; }
        .kp-path-prefix { font-family: var(--bs-font-monospace); }
    </style>
</head>
<body class="bg-body-tertiary">
<nav class="navbar bg-dark mb-4" data-bs-theme="dark">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="<?= site_url('kpapy-demo') ?>">kpapy <span class="text-body-secondary fw-normal">demo</span></a>
        <div class="d-flex gap-3 small">
            <a class="link-light" href="<?= site_url('kpapy-demo/api/v1/docs') ?>">API docs</a>
            <a class="link-light" href="<?= site_url('kpapy-demo/api/v1/docs/openapi.json') ?>">openapi.json</a>
            <a class="link-light" href="<?= site_url('kpapy-demo/profile/reader') ?>">Reader's web profile</a>
            <a class="link-light" href="<?= site_url('kpapy-demo/profile/admin') ?>">Admin's web profile</a>
        </div>
    </div>
</nav>
