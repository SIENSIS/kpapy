<?php
/**
 * Pàgina de documentació de l'API generada a partir del document OpenAPI.
 * Sense codi ni estils en línia: un sol bloc <style> amb el nonce de la CSP i, amb l'opció
 * "Prova-ho", un script extern (try-it.js) que envia les peticions des dels formularis.
 *
 * @var array<string, mixed> $spec
 * @var string               $jsonUrl
 * @var bool                 $tryIt
 * @var string               $scriptUrl
 */
use Kpapy\OpenApi\Example;

$tags = [];

foreach ($spec['paths'] as $path => $methods) {
    foreach ($methods as $method => $operation) {
        $tags[$operation['tags'][0] ?? 'API'][] = [$method, $path, $operation];
    }
}

$schemas = $spec['components']['schemas'];
$tryIt   = $tryIt ?? false;
$auth    = null;

foreach ($spec['paths'] as $path => $methods) {
    if (str_ends_with($path, '/auth/token') && isset($methods['post'], $spec['paths'][$path . '/verify']['post'])) {
        $auth = $path;
    }
}

$example = static function (array $operation) use ($schemas): string {
    $schema = $operation['requestBody']['content']['application/json']['schema'] ?? null;

    return is_array($schema) ? (string) json_encode(Example::fromSchema($schema, $schemas), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
};
$nonce   = function_exists('csp_style_nonce') ? csp_style_nonce() : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($spec['info']['title'] ?? 'API') ?> · API documentation</title>
    <style <?= $nonce ?>>
        :root { color-scheme: light dark; --fg: #1f2328; --muted: #59636e; --bg: #fff; --card: #f6f8fa; --border: #d1d9e0; }
        @media (prefers-color-scheme: dark) { :root { --fg: #e6edf3; --muted: #9198a1; --bg: #0d1117; --card: #151b23; --border: #3d444d; } }
        body { font: 15px/1.5 system-ui, sans-serif; color: var(--fg); background: var(--bg); margin: 0; }
        main { max-width: 1100px; margin: 0 auto; padding: 24px 16px 64px; }
        h1 { margin: 0 0 4px; } h2 { margin-top: 40px; border-bottom: 1px solid var(--border); padding-bottom: 6px; }
        .muted { color: var(--muted); }
        details { background: var(--card); border: 1px solid var(--border); border-radius: 8px; margin: 8px 0; }
        summary { cursor: pointer; padding: 10px 12px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .m { font: 600 12px/1 ui-monospace, monospace; padding: 5px 8px; border-radius: 4px; color: #fff; min-width: 52px; text-align: center; }
        .get { background: #0969da; } .post { background: #1a7f37; } .patch, .put { background: #9a6700; } .delete { background: #cf222e; }
        code, .path { font-family: ui-monospace, monospace; }
        .body { padding: 0 16px 12px; overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; font-size: 14px; } td, th { text-align: left; padding: 4px 8px; border-top: 1px solid var(--border); vertical-align: top; }
        input, select, textarea, button { font: inherit; color: var(--fg); background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: 5px 8px; }
        textarea { font-family: ui-monospace, monospace; font-size: 13px; width: 100%; box-sizing: border-box; min-height: 120px; }
        button { cursor: pointer; background: var(--card); } button.primary { background: #0969da; border-color: #0969da; color: #fff; }
        .auth { position: sticky; top: 0; z-index: 1; background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; margin: 16px 0; }
        .auth form, .row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin: 6px 0; }
        .auth input[name=token] { flex: 1 1 320px; font-family: ui-monospace, monospace; }
        .try { border-top: 1px dashed var(--border); margin-top: 12px; padding-top: 8px; }
        .try label { display: flex; flex-direction: column; gap: 2px; font-size: 13px; color: var(--muted); }
        .try label input, .try label select { min-width: 160px; color: var(--fg); }
        .fields { display: flex; gap: 8px; flex-wrap: wrap; margin: 6px 0; }
        .result { margin-top: 8px; } .result pre { background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: 10px; overflow: auto; max-height: 420px; margin: 4px 0 0; font-size: 13px; }
        .status { font: 600 13px ui-monospace, monospace; } .ok { color: #1a7f37; } .ko { color: #cf222e; }
        .note { font-size: 13px; }
        [hidden] { display: none !important; }
    </style>
</head>
<body>
<main>
    <h1><?= esc($spec['info']['title'] ?? 'API') ?> <span class="muted"><?= esc($spec['info']['version'] ?? '') ?></span></h1>
    <p class="muted"><?= esc($spec['info']['description'] ?? '') ?></p>
    <p>Base URL: <code><?= esc($spec['servers'][0]['url'] ?? '') ?></code> · Authentication: <code>Authorization: Bearer &lt;token&gt;</code> · <a href="<?= esc($jsonUrl, 'attr') ?>">OpenAPI document (JSON)</a></p>
    <p class="muted">Responses: <code>{"data": …, "meta": …, "links": …}</code>. Errors: <code>application/problem+json</code> with <code>code</code> and, for invalid data, <code>errors</code> per field.</p>

    <?php if ($tryIt): ?>
        <section class="auth" data-kp-auth data-server="<?= esc($spec['servers'][0]['url'] ?? '', 'attr') ?>">
            <form data-kp-token-form>
                <strong>Try it</strong>
                <input name="token" type="password" autocomplete="off" spellcheck="false" placeholder="Access token (Bearer). Empty: anonymous requests" aria-label="Access token">
                <button type="button" data-kp-show-token>Show</button>
                <button type="button" data-kp-forget>Forget</button>
                <?php if ($auth !== null): ?><button type="button" data-kp-open-login>Sign in…</button><?php endif ?>
            </form>
            <?php if ($auth !== null): ?>
                <form data-kp-login data-path="<?= esc($auth, 'attr') ?>" hidden>
                    <input name="email" type="email" autocomplete="username" placeholder="Email" required>
                    <input name="password" type="password" autocomplete="current-password" placeholder="Password" required>
                    <button class="primary" type="submit">Sign in (step 1)</button>
                </form>
                <form data-kp-verify data-path="<?= esc($auth . '/verify', 'attr') ?>" hidden>
                    <span class="note" data-kp-challenge></span>
                    <input name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="Code" required>
                    <button class="primary" type="submit">Verify (step 2)</button>
                </form>
            <?php endif ?>
            <div class="note muted" data-kp-auth-status>The token is kept only in this tab's memory. Requests go through the same permissions, token scopes and rate limits as any other client.</div>
        </section>
    <?php endif ?>

    <?php foreach ($tags as $tag => $operations): ?>
        <h2><?= esc($tag) ?></h2>
        <?php foreach ($operations as [$method, $path, $operation]): ?>
            <details>
                <summary><span class="m <?= esc($method, 'attr') ?>"><?= esc(strtoupper($method)) ?></span> <span class="path"><?= esc($path) ?></span> <span class="muted"><?= esc($operation['summary'] ?? '') ?></span></summary>
                <div class="body">
                    <?php if (! empty($operation['parameters'])): ?>
                        <table>
                            <tr><th>Parameter</th><th>In</th><th>Description</th></tr>
                            <?php foreach ($operation['parameters'] as $parameter): ?>
                                <tr><td><code><?= esc($parameter['name']) ?></code></td><td><?= esc($parameter['in']) ?></td><td><?= esc($parameter['description'] ?? ($parameter['schema']['type'] ?? '')) ?></td></tr>
                            <?php endforeach ?>
                        </table>
                    <?php endif ?>
                    <?php $ref = $operation['requestBody']['content']['application/json']['schema']['$ref'] ?? null ?>
                    <?php if ($ref !== null): $name = substr($ref, strrpos($ref, '/') + 1) ?>
                        <p><strong>Body</strong> (<?= esc($name) ?>)</p>
                        <table>
                            <?php foreach ($schemas[$name]['properties'] ?? [] as $field => $schema): ?>
                                <tr><td><code><?= esc($field) ?></code><?= in_array($field, $schemas[$name]['required'] ?? [], true) ? ' *' : '' ?></td><td><?= esc(is_array($schema) ? ($schema['type'] ?? 'mixed') . (isset($schema['format']) ? ' (' . $schema['format'] . ')' : '') : 'mixed') ?></td><td><?= esc(is_array($schema) ? implode(', ', (array) ($schema['enum'] ?? $schema['items']['enum'] ?? [])) : '') ?></td></tr>
                            <?php endforeach ?>
                        </table>
                    <?php endif ?>
                    <p class="muted">Responses: <?= esc(implode(', ', array_keys($operation['responses'] ?? []))) ?></p>
                    <?php if ($tryIt): ?>
                        <?php
                        $parameters = $operation['parameters'] ?? [];
                        $hasIfMatch = in_array('If-Match', array_column($parameters, 'name'), true);

                        if (! $hasIfMatch && in_array($method, ['patch', 'put', 'delete'], true)) {
                            $parameters[] = ['name' => 'If-Match', 'in' => 'header', 'schema' => ['type' => 'string']];
                        }
                        $withBody = in_array($method, ['post', 'patch', 'put'], true);
                        ?>
                        <form class="try" data-kp-try data-method="<?= esc(strtoupper($method), 'attr') ?>" data-path="<?= esc($path, 'attr') ?>">
                            <div class="fields">
                                <?php foreach ($parameters as $parameter): ?>
                                    <label><?= esc($parameter['name']) ?><?= ($parameter['required'] ?? false) ? ' *' : '' ?>
                                        <?php if (isset($parameter['schema']['enum'])): ?>
                                            <select name="<?= esc($parameter['in'] . ':' . $parameter['name'], 'attr') ?>">
                                                <option value=""></option>
                                                <?php foreach ($parameter['schema']['enum'] as $value): ?><option><?= esc((string) $value) ?></option><?php endforeach ?>
                                            </select>
                                        <?php else: ?>
                                            <input name="<?= esc($parameter['in'] . ':' . $parameter['name'], 'attr') ?>"<?= ($parameter['required'] ?? false) ? ' required' : '' ?><?= ($parameter['schema']['type'] ?? '') === 'integer' ? ' inputmode="numeric"' : '' ?>>
                                        <?php endif ?>
                                    </label>
                                <?php endforeach ?>
                                <label>Other query parameters
                                    <input name="extra" placeholder="e.g. purge=1&amp;lang=ca">
                                </label>
                            </div>
                            <?php if ($withBody): ?>
                                <label>JSON body<textarea name="body" spellcheck="false"><?= esc($example($operation)) ?></textarea></label>
                            <?php endif ?>
                            <div class="row">
                                <button class="primary" type="submit">Send</button>
                                <button type="button" data-kp-curl>Copy as curl</button>
                            </div>
                            <div class="result" data-kp-result hidden>
                                <div><span class="status" data-kp-status></span> <span class="muted note" data-kp-headers></span></div>
                                <pre data-kp-body></pre>
                            </div>
                        </form>
                    <?php endif ?>
                </div>
            </details>
        <?php endforeach ?>
    <?php endforeach ?>
</main>
<?php if ($tryIt): ?>
    <script type="module" src="<?= esc($scriptUrl, 'attr') ?>"></script>
<?php endif ?>
</body>
</html>
