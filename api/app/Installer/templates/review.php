<?php use VO\Installer\Template; ob_start(); ?>
<h1>Revisar e instalar</h1><div class="review"><p><b>Comercio:</b> <?= Template::e($data['business_name']) ?></p><p><b>Sucursal:</b> <?= Template::e($data['branch_name']) ?></p><p><b>Administrador:</b> <?= Template::e($data['admin_email']) ?></p></div>
<form method="post" action="?step=run"><input type="hidden" name="csrf" value="<?= Template::e($csrf) ?>"><button class="btn">Instalar ahora</button></form>
<?php $content=ob_get_clean(); require __DIR__.'/layout.php'; ?>
