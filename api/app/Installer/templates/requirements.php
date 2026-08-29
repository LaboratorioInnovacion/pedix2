<?php use VO\Installer\Template; ob_start(); ?>
<h1>Instalación de Vender Online</h1><p class="lead">Primero verificamos que el hosting esté listo.</p>
<?php if (!empty($blocked)): ?><p class="error">Hay requisitos pendientes. Corregilos antes de continuar.</p><?php endif; ?>
<ul><?php foreach ($requirements as $r): ?><li class="<?= $r[1]?'ok':'bad' ?>"><?= $r[1]?'✓':'×' ?> <?= Template::e($r[0]) ?></li><?php endforeach; ?></ul>
<form method="post"><input type="hidden" name="csrf" value="<?= Template::e($csrf) ?>"><button class="btn">Continuar</button></form>
<?php $content=ob_get_clean(); require __DIR__.'/layout.php'; ?>
