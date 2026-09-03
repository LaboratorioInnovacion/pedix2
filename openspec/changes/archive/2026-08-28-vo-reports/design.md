# Design: vo-reports — Reports & Dashboard V1 (Phase 12)

## Technical Approach

Pure read-only SQL aggregates on read (exploration option 1): `ReportsRepository` (summary/daily/dashboard queries) → `ReportsService` (validation, presets, canonical money math) → `ReportsAdminController` (`/admin/reportes` + `/admin/reportes/csv`) → `reportes.php` template + dashboard metrics block. Follows the exact controller/template/guard patterns of `NotificationsAdminController` and `OrdersAdminController`. No migration — `idx_orders_created_at`, `idx_orders_branch`, `idx_orders_status`, `idx_order_items_order`, `idx_order_items_item` already exist (migration 006).

## Pinned Assumptions (authoritative for apply)

| Pin | Decision |
|-----|----------|
| A1 default status set | `orders.status` = all 9 ENUM values EXCEPT `rejected`, `cancelled`, `expired` → `{pending, change_proposed, accepted, in_progress, ready, completed}`. Explicit `estado` filter replaces the default. |
| A2 medio de pago | Filter uses `orders.payment_method` snapshot column (values seen in practice: `transfer`, `mercadopago`; NULL when unpaid — filter only applies when non-empty). |
| A3 stock bajo | `branch_items` with `stock_mode='simple'` AND `stock_quantity <= 0` in scoped branches; count of rows. No threshold setting exists in the codebase and the master spec names no value — "at/below zero" is the only non-arbitrary pin; an arbitrary threshold (e.g. ≤5) would invent business policy. |
| Bruta column | **Venta bruta = `SUM(gross_items_cents)`**, NOT `merchandise_total_cents`. Correction to exploration.md line 38: `PricingService` computes `merchandise_total_cents = gross − item − order − coupon − payment` (line 29: `$merchandise = $afterCoupon - $paymentDiscount`), i.e. it is ALREADY net of all four discount buckets. Using it as bruta would double-subtract and go negative. With `gross_items_cents`, the canonical formula holds arithmetically: neta = bruta − descuentos + delivery − payout ≡ `grand_total_cents − delivery_payout_cents`. |
| Dashboard delivery mapping | "buscando delivery" = orders with `deliveries.state IN ('pending','assigned')`; "en camino" = `deliveries.state='picked_up'`. Partitions every non-terminal delivery state; terminal states (`delivered/failed/cancelled`) count nowhere. `uq_deliveries_order` guarantees no fan-out. |
| Dashboard money | ventas de hoy = `SUM(grand_total_cents)` for `DATE(created_at)=CURDATE()` + A1 set + scope; pedidos de hoy = COUNT of same set; ticket promedio = intdiv(ventas, pedidos), 0 when pedidos=0. Live status counts (nuevos…rechazados) carry NO date filter. |

## Architecture Decisions

| Decision | Choice | Alternatives | Rationale |
|----------|--------|--------------|-----------|
| Aggregation | SQL `GROUP BY` on read | Cache/summary tables | Spec mandates basic reports; V1 volume small; shared-hosting safe; rollback trivial (proposal) |
| Product/category filters | `EXISTS (SELECT 1 FROM order_items …)` | JOIN order_items | JOIN fans out one order row per matching line and multiplies money sums |
| Money math | Integer cents in PHP from SQL `COALESCE(SUM(...),0)`; neta computed in `ReportsService` | Floats, SQL expressions | Project rule: money = integer cents; service keeps canonical formula in one place |
| Date filtering | `o.created_at >= ? AND o.created_at < ? + INTERVAL 1 DAY` (SQL dates) | UNIX timestamps | Consistent with existing code (SQL dates everywhere) |
| Branch scope | Always `JOIN user_branches ub ON ub.branch_id=o.branch_id AND ub.user_id=?`; optional `AND o.branch_id=?` | business_id filter | Existing pattern in every admin query; out-of-scope branch → natural zero rows |
| CSV encoding | `text/csv; charset=utf-8`, BOM `\xEF\xBB\xBF`, semicolon separator, `*.csv` attachment | comma, no BOM | es-AR Excel compatibility; spec mandates CSV |
| Invalid filter handling | Silently ignore invalid values (allowlists) | 400 error | Matches notifications log pattern (`isset(STATES[$state])` else ''); read page must never hard-fail |

## Data Flow

```
GET /admin/reportes(|/csv)?query
  └→ ReportsAdminController  (auth → PermissionGuard 'reports.view' → audited 403)
       └→ ReportsService.parseFilters($_GET, userBranches)   # presets/validation → Filters DTO
            └→ ReportsRepository.summary(filters|dashboard())  # scoped SQL, EXISTS, GROUP BY
                 └→ array<int, row>  (integer cents)
       └→ template reportes.php / CSV stream (fpassthru of php://temp)
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `api/app/Reports/ReportsRepository.php` | Create | `summary(Filters): array` (single GROUP BY date query, 6 metric expressions), `dashboard(userId): array` (11 metrics, 2–3 queries) |
| `api/app/Reports/ReportsService.php` | Create | `parseFilters()` (presets hoy/7d/30d/custom, Y-m-d validation, from<=to, span ≤ 366, allowlists, default 30d), `neta()` canonical formula, branch list |
| `api/app/Admin/ReportsAdminController.php` | Create | `handle($method,$path)`; guard `reports.view` + audited deny; page + CSV stream (same filters) |
| `api/app/Admin/templates/reportes.php` | Create | Spanish filter form (preset select, from/to, branch select, producto/categoría ids, medio, modalidad, estado), 6 metric cards, daily table, CSV link |
| `api/app/Admin/templates/dashboard.php` | Modify | Metrics block (11 Spanish cards/values) + "Reportes" card gets `href=/admin/reportes` |
| `api/app/Admin/AdminController.php` | Modify | `isReportsPath()` + dispatch |
| `tests/ReportsRepositoryTest.php` | Create | Seeded exact-cents scenarios (see Testing) |
| `tests/ReportsAdminHttpTest.php` | Create | HTTP guard/CSV/dashboard/nav scenarios |

## Interfaces / Contracts

```php
// ReportsService::Filters (readonly array shape)
['preset'=>'hoy|7d|30d|custom','from'=>'Y-m-d','to'=>'Y-m-d',
 'branchId'=>?int,'productId'=>?int,'categoryId'=>?int,
 'paymentMethod'=>?string,'fulfillment'=>?'pickup'|'delivery','status'=>?string]
// ReportsRepository::summary returns rows: date, orders, bruta, descuentos,
// delivery_cobrado, remuneracion, neta — all int cents; totals = last-row rollup by service.
// CSV: headers "Fecha;Pedidos;Venta bruta;Descuentos;Delivery cobrado;Remuneración delivery;Venta neta operativa"
//      + daily rows + "TOTAL;..." row; filename ventas_YYYYMMDD-YYYYMMDD.csv.
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Repository (Unit A) | Exact cents per metric; daily rows; EXISTS no-double-count (2 same-product lines); default-set exclusion (rejected/cancelled/expired out; change_proposed in); estado override; product/category/payment/fulfillment/branch filter effects; scope isolation; empty range | `ReportsRepositoryTest`: scratch DB `vo_rep12_test_<rand>`, MigrationRunner + InstallerSeeder, seed 2 branches × 3 days × statuses with discounts/fees/payouts; serial; `D:\xampp\php\php.exe` |
| HTTP (Unit B) | 403 + `authz.denied` (page and CSV); login redirect; filters reflected in body; CSV BOM + headers + TOTAL + filename; dashboard 11 metrics incl. delivery buckets and stock bajo; "Reportes" card href; scoped visibility | `ReportsAdminHttpTest` on `InstallerTestServer` (AdminOrdersHttpTest pattern); revoke `reports.view` for deny case |
| Suite | No regressions (150 existing + new) | Full suite serial in chunks |

## Threat Matrix

N/A — no routing outside the existing admin front controller, no shell/subprocess, no VCS/PR automation, no executable-file classification, no process integration. Same boundary as prior admin-page phases.

## Migration / Rollout

No migration required. Rollback: revert `AdminController` routing lines + dashboard template, delete new files. No schema/data changes — lossless.

## Open Questions

None. All proposal assumptions A1–A3 pinned above.
