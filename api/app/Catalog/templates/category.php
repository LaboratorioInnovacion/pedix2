<?php use VO\Support\Template; ob_start(); ?>
<p><a href="/">← Volver</a></p><h1><?= Template::e($category['name']) ?></h1><p><?= Template::e($category['description'] ?? '') ?></p>
<nav class="chips"><?php foreach ($categories as $c): ?><a href="/categoria/<?= Template::e($c['slug']) ?>"><?= Template::e($c['name']) ?></a><?php endforeach; ?></nav>
<section class="grid"><?php foreach ($items as $item): ?><article class="card"><h2><a href="/producto/<?= Template::e($item['slug']) ?>"><?= Template::e($item['name']) ?></a></h2><p><?= Template::e($item['description'] ?? '') ?></p><strong><?= Template::e($item['display_price']) ?></strong></article><?php endforeach; ?></section>
<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
