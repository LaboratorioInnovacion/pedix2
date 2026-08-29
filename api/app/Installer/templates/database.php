<?php use VO\Installer\Template; ob_start(); ?>
<h1>Conexión a la base de datos</h1><p class="lead">Estos datos no se muestran en pantalla si hay un error.</p><?php if (!empty($error)): ?><p class="error"><?= Template::e($error) ?></p><?php endif; ?>
<form method="post" class="grid two"><input type="hidden" name="csrf" value="<?= Template::e($csrf) ?>"><label>Host<input name="host" value="127.0.0.1"></label><label>Puerto<input name="port" value="3306"></label><label>Base de datos<input name="name" required></label><label>Usuario<input name="user" value="root"></label><label>Contraseña<input name="password" type="password"></label><div><button class="btn">Probar conexión</button></div></form>
<?php $content=ob_get_clean(); require __DIR__.'/layout.php'; ?>
