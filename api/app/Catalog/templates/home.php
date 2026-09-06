<?php use VO\Support\Template; ob_start(); ?>
<h1>Catálogo</h1><nav class="chips"><?php foreach ($categories as $c): ?><a href="/categoria/<?= Template::e($c['slug']) ?>"><?= Template::e($c['name']) ?></a><?php endforeach; ?></nav>
<section class="grid"><?php foreach ($items as $item): ?><article class="card"><h2><a href="/producto/<?= Template::e($item['slug']) ?>"><?= Template::e($item['name']) ?></a></h2><p><?= Template::e($item['description'] ?? '') ?></p><strong><?= Template::e($item['display_price']) ?></strong></article><?php endforeach; ?></section>
<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
