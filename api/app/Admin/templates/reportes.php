<?php use VO\Support\Template; use VO\Reports\ReportsService; ob_start(); ?>
<h1>Reportes de ventas</h1><p class="muted">Métricas del período con desglose por día. Los valores de filtro inválidos se ignoran.</p>
<form method="get" action="/admin/reportes" class="card">
<label>Período <select name="preset"><option value="hoy"<?= $filters['preset'] === 'hoy' ? ' selected' : '' ?>>Hoy</option><option value="7d"<?= $filters['preset'] === '7d' ? ' selected' : '' ?>>Últimos 7 días</option><option value="30d"<?= $filters['preset'] === '30d' ? ' selected' : '' ?>>Últimos 30 días</option><option value="custom"<?= $filters['preset'] === 'custom' ? ' selected' : '' ?>>Personalizado</option></select></label>
<label>Desde <input type="date" name="from" value="<?= Template::e($filters['from']) ?>"></label>
<label>Hasta <input type="date" name="to" value="<?= Template::e($filters['to']) ?>"></label>
<label>Sucursal <select name="branch_id"><option value="">Todas</option><?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>"<?= $filters['branchId'] === (int)$b['id'] ? ' selected' : '' ?>><?= Template::e($b['name']) ?></option><?php endforeach; ?></select></label>
<label>Producto (ID) <input type="number" name="producto" min="1" value="<?= $filters['productId'] !== null ? (int)$filters['productId'] : '' ?>"></label>
<label>Categoría (ID) <input type="number" name="categoria" min="1" value="<?= $filters['categoryId'] !== null ? (int)$filters['categoryId'] : '' ?>"></label>
<label>Medio de pago <input type="text" name="medio" maxlength="64" value="<?= Template::e($filters['paymentMethod'] ?? '') ?>"></label>
<label>Modalidad <select name="modalidad"><option value="">Todas</option><option value="pickup"<?= $filters['fulfillment'] === 'pickup' ? ' selected' : '' ?>>Retiro en local</option><option value="delivery"<?= $filters['fulfillment'] === 'delivery' ? ' selected' : '' ?>>Envío a domicilio</option></select></label>
<label>Estado <select name="estado"><option value="">Todos los activos</option><?php foreach ($statuses as $st => $label): ?><option value="<?= Template::e($st) ?>"<?= $filters['status'] === $st ? ' selected' : '' ?>><?= Template::e($label) ?></option><?php endforeach; ?></select></label>
<button class="btn">Filtrar</button>
<a class="btn secondary" href="<?= Template::e($csvHref) ?>">Exportar CSV</a>
</form>
<section class="grid">
<?php foreach ($labels as $key => $label): $value = $key === 'orders' ? (string)(int)$totals[$key] : ReportsService::money((int)$totals[$key]); ?><article class="card"><h2><?= Template::e($label) ?></h2><p><?= Template::e($value) ?></p></article><?php endforeach; ?>
</section>
<section class="card">
<?php if (!$rows): ?><p>No hay ventas en el período seleccionado.</p>
<?php else: ?>
<table><thead><tr><th>Fecha</th><?php foreach ($labels as $label): ?><th><?= Template::e($label) ?></th><?php endforeach; ?></tr></thead>
<tbody><?php foreach ($rows as $r): ?><tr><td><?= Template::e($r['date']) ?></td><td><?= (int)$r['orders'] ?></td><td><?= ReportsService::money((int)$r['bruta']) ?></td><td><?= ReportsService::money((int)$r['descuentos']) ?></td><td><?= ReportsService::money((int)$r['delivery_cobrado']) ?></td><td><?= ReportsService::money((int)$r['remuneracion']) ?></td><td><?= ReportsService::money((int)$r['neta']) ?></td></tr><?php endforeach; ?></tbody>
<tfoot><tr><td>TOTAL</td><td><?= (int)$totals['orders'] ?></td><td><?= ReportsService::money((int)$totals['bruta']) ?></td><td><?= ReportsService::money((int)$totals['descuentos']) ?></td><td><?= ReportsService::money((int)$totals['delivery_cobrado']) ?></td><td><?= ReportsService::money((int)$totals['remuneracion']) ?></td><td><?= ReportsService::money((int)$totals['neta']) ?></td></tr></tfoot>
</table>
<?php endif; ?>
</section>
<?php $content = ob_get_clean(); $title = 'Reportes de ventas'; require __DIR__ . '/layout.php'; ?>
