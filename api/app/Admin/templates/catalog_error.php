<?php use VO\Support\Template; ob_start(); ?>
<section class="card"><h1>Error</h1><p class="error"><?= Template::e($message ?? 'No se pudo completar la operación.') ?></p><p><a href="/admin/catalogo">Volver al catálogo</a></p><input type="hidden" name="csrf" value="<?= Template::e($csrf) ?>"></section>
<?php $content = ob_get_clean(); $title = 'Catálogo'; require __DIR__ . '/layout.php'; ?>
