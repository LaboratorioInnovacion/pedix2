<?php use VO\Support\Template; ob_start(); ?>
<h1>Repartos</h1>
<?php if ($flashOk): ?><section class="card"><p><strong><?= Template::e($flashOk) ?></strong></p></section><?php endif; ?>
<?php if ($flashErr): ?><section class="card"><p class="error"><?= Template::e($flashErr) ?></p></section><?php endif; ?>
<section class="card"><h2>Zonas de envío</h2>
<form method="post" action="/admin/delivery/zonas"><input type="hidden" name="csrf" value="<?= Template::e($csrf) ?>">
<label>Sucursal <select name="branch_id"><?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= Template::e($b['name']) ?></option><?php endforeach; ?></select></label>
<label>Nombre <input type="text" name="name" required></label>
<label>Términos de cobertura (separados por coma o barra) <input type="text" name="match_terms" required placeholder="palermo, colegiales"></label>
<label>Tarifa al cliente (centavos) <input type="number" name="customer_rate_cents" min="0" step="1" required></label>
<label>Pago al repartidor (centavos) <input type="number" name="driver_payout_cents" min="0" step="1" required></label>
<button class="btn">Crear zona</button></form>
<?php foreach ($zones as $z): $za = (int)$z['is_active'] === 1; ?>
<article><p><strong><?= Template::e($z['name']) ?></strong> · <?= Template::e($z['branch_name']) ?> · <span class="muted"><?= Template::e($z['match_terms']) ?></span> · $ <?= number_format((int)$z['customer_rate_cents']/100,2,',','.') ?> / $ <?= number_format((int)$z['driver_payout_cents']/100,2,',','.') ?> · <strong><?= $za ? 'Activa' : 'Inactiva' ?></strong></p>
<form method="post" action="/admin/delivery/zonas/<?= (int)$z['id'] ?>"><input type="hidden" name="csrf" value="<?= Template::e($csrf) ?>"><input type="hidden" name="name" value="<?= Template::e($z['name']) ?>"><input type="hidden" name="match_terms" value="<?= Template::e($z['match_terms']) ?>"><label>Tarifa (centavos) <input type="number" name="customer_rate_cents" min="0" step="1" value="<?= (int)$z['customer_rate_cents'] ?>" required></label><label>Pago repartidor (centavos) <input type="number" name="driver_payout_cents" min="0" step="1" value="<?= (int)$z['driver_payout_cents'] ?>" required></label><button class="btn secondary">Guardar tarifa</button></form>
<form method="post" action="/admin/delivery/zonas/<?= (int)$z['id'] ?>/estado"><input type="hidden" name="csrf" value="<?= Template::e($csrf) ?>"><button class="btn secondary"><?= $za ? 'Desactivar' : 'Activar' ?></button></form>
<form method="post" action="/admin/delivery/zonas/<?= (int)$z['id'] ?>/eliminar"><input type="hidden" name="csrf" value="<?= Template::e($csrf) ?>"><button class="btn secondary">Eliminar</button></form>
</article>
<?php endforeach; if (!$zones): ?><p class="muted">Todavía no hay zonas de envío.</p><?php endif; ?></section>
<section class="card"><h2>Repartidores</h2>
<form method="post" action="/admin/delivery/repartidores"><input type="hidden" name="csrf" value="<?= Template::e($csrf) ?>">
<label>Nombre <input type="text" name="name" required></label>
<label>Teléfono <input type="text" name="phone"></label>
<fieldset><legend>Sucursales</legend><?php foreach ($branches as $b): ?><label><input type="checkbox" name="branches[]" value="<?= (int)$b['id'] ?>"> <?= Template::e($b['name']) ?></label><?php endforeach; ?></fieldset>
<button class="btn">Crear repartidor</button></form>
<?php foreach ($persons as $p): $pa = (int)$p['is_active'] === 1; $linked = array_map('intval', explode(',', (string)$p['branch_ids'])); ?>
<article><p><strong><?= Template::e($p['name']) ?></strong> · <?= Template::e($p['phone'] ?? 'sin teléfono') ?> · <strong><?= $pa ? 'Activo' : 'Inactivo' ?></strong></p>
<form method="post" action="/admin/delivery/repartidores/<?= (int)$p['id'] ?>"><input type="hidden" name="csrf" value="<?= Template::e($csrf) ?>"><label>Nombre <input type="text" name="name" value="<?= Template::e($p['name']) ?>" required></label><label>Teléfono <input type="text" name="phone" value="<?= Template::e($p['phone'] ?? '') ?>"></label><fieldset><legend>Sucursales</legend><?php foreach ($branches as $b): ?><label><input type="checkbox" name="branches[]" value="<?= (int)$b['id'] ?>"<?= in_array((int)$b['id'], $linked, true) ? ' checked' : '' ?>> <?= Template::e($b['name']) ?></label><?php endforeach; ?></fieldset><button class="btn secondary">Guardar repartidor</button></form>
<form method="post" action="/admin/delivery/repartidores/<?= (int)$p['id'] ?>/estado"><input type="hidden" name="csrf" value="<?= Template::e($csrf) ?>"><button class="btn secondary"><?= $pa ? 'Desactivar' : 'Activar' ?></button></form>
</article>
<?php endforeach; if (!$persons): ?><p class="muted">Todavía no hay repartidores.</p><?php endif; ?></section>
<?php $content = ob_get_clean(); $title = 'Repartos'; require __DIR__ . '/layout.php'; ?>
