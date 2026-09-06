<?php use VO\Support\Template; ob_start(); ?>
<h1>Resultados de búsqueda</h1><p>Buscaste: <strong><?= Template::e($q) ?></strong></p>
<section class="grid"><?php foreach ($items as $item): ?><article class="card"><h2><a href="/producto/<?= Template::e($item['slug']) ?>"><?= Template::e($item['name']) ?></a></h2><p><?= Template::e($item['description'] ?? '') ?></p><strong><?= Template::e($item['display_price']) ?></strong></article><?php endforeach; ?></section>
<?php if (!$items): ?><p>No encontramos productos visibles.</p><?php endif; ?>
<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
