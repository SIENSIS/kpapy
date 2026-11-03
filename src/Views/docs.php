<?php
/**
 * Pàgina de documentació de l'API generada a partir del document OpenAPI.
 * Sense JavaScript ni estils en línia: un sol bloc <style> amb el nonce de la CSP.
 *
 * @var array<string, mixed> $spec
 * @var string               $jsonUrl
 */
$tags = [];

foreach ($spec['paths'] as $path => $methods) {
    foreach ($methods as $method => $operation) {
        $tags[$operation['tags'][0] ?? 'API'][] = [$method, $path, $operation];
    }
}

$schemas = $spec['components']['schemas'];
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
    </style>
</head>
<body>
<main>
    <h1><?= esc($spec['info']['title'] ?? 'API') ?> <span class="muted"><?= esc($spec['info']['version'] ?? '') ?></span></h1>
    <p class="muted"><?= esc($spec['info']['description'] ?? '') ?></p>
    <p>Base URL: <code><?= esc($spec['servers'][0]['url'] ?? '') ?></code> · Authentication: <code>Authorization: Bearer &lt;token&gt;</code> · <a href="<?= esc($jsonUrl, 'attr') ?>">OpenAPI document (JSON)</a></p>
    <p class="muted">Responses: <code>{"data": …, "meta": …, "links": …}</code>. Errors: <code>application/problem+json</code> with <code>code</code> and, for invalid data, <code>errors</code> per field.</p>

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
                </div>
            </details>
        <?php endforeach ?>
    <?php endforeach ?>
</main>
</body>
</html>
