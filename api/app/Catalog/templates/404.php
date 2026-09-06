<?php ob_start(); ?>
<h1>No encontrado</h1><p>El producto o la categoría no está disponible.</p><p><a href="/">Volver al catálogo</a></p>
<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
