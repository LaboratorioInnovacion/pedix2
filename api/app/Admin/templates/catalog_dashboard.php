<?php use VO\Support\Template; ob_start(); ?>
<section class="top"><div><p class="eyebrow"><?= Template::e($user['business_name'] ?? '') ?></p><h1>Catálogo</h1><p class="muted">Administrá categorías, productos y disponibilidad por sucursal.</p></div><a class="btn" href="/admin/productos">Productos</a></section>
<section class="grid"><article class="card"><h2>Categorías</h2><p><?= count($categories) ?> cargadas</p><a href="/admin/categorias">Administrar categorías</a></article><article class="card"><h2>Productos</h2><p><?= count($items) ?> cargados</p><a href="/admin/productos">Administrar productos</a></article></section>
<?php $content = ob_get_clean(); $title = 'Catálogo'; require __DIR__ . '/layout.php'; ?>
