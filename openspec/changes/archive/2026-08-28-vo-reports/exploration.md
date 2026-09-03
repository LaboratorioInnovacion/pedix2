# Exploration: vo-reports (Phase 12 — Reportes)

## Current State
- Phases 1–11 complete: 150/150 tests green, 21 main specs. No report or dashboard-metrics code exists.
- Dashboard (`GET /admin/`, `AdminController::dashboard()` + `templates/dashboard.php`) is a placeholder card grid; the "Reportes" card already exists with `href=null` ("Módulo pendiente").
- Master spec **section 21 "Dashboard y reportes"** (authoritative, quoted):
  - **Dashboard V1**: "ventas de hoy; pedidos de hoy; ticket promedio; nuevos; preparando; listos; buscando delivery; en camino; cancelados; rechazados; stock bajo."
  - **Reportes V1** — "Filtros por: fecha; sucursal; producto; categoría; medio de pago; modalidad; estado. Resultados: cantidad de pedidos; venta bruta; descuentos; delivery cobrado; remuneración delivery; venta neta operativa. Exportación CSV."
  - Section 3.2 lists `dashboard` and `reportes` as `/admin/` pages. Section 38: "reportes básicos" is Core V1; "reportes Pro" (line 1415) is explicitly out of scope.
- **CSV export is spec-mandated** ("Exportación CSV") — not optional.

## Data Sources (verified in migrations)
- `orders`: money columns `gross_items_cents`, `item_promotions_cents`, `order_promotions_cents`, `coupon_discount_cents`, `payment_discount_cents`, `merchandise_total_cents`, `delivery_fee_cents`, `grand_total_cents`; snapshot columns `delivery_payout_cents` (migration 009), `payment_method`, `fulfillment`, `status`, `branch_id`, `created_at`.
- `order_items`: `item_id`, `branch_id`, `quantity`, `line_total_cents`; `order_item_modifiers` for detail.
- `order_discounts`: `kind/type/code/amount_cents` rows per order (breakdown source).
- `payments`: `method ENUM('transfer','mercadopago')`, `state`, `amount_cents` (idx on order_id/state).
- `stock_movements`: reason ENUM incl. `adjustment`; `branch_items.stock_quantity/stock_mode` for low stock.
- **Indexes already exist** (migration 006): `idx_orders_created_at`, `idx_orders_branch`, `idx_orders_status`, `idx_order_items_order`, `idx_order_items_item`. **No migration 011 needed** — pure read feature.

## Patterns to Follow
- Guard: `PermissionGuard->requirePermission('reports.view')` (seeded in `InstallerSeeder::PERMISSIONS` since Phase 2) else 403 + `authz.denied` audit (same as NotificationsAdminController).
- Branch scope: ALWAYS `JOIN user_branches ub ON ub.branch_id=X.branch_id AND ub.user_id=?` (every admin user gets user_branches rows via InstallerSeeder); branch dropdown via `SELECT b.id,b.name FROM branches b JOIN user_branches ...` (DeliveryAdminController::branches pattern).
- Routing: `AdminController::handle()` dispatch + `isXPath()` matcher; template via `Template::e()` escaping, Spanish copy, `layout.php`.
- Tests: `AdminOrdersHttpTest` pattern — scratch DB `vo_<change>_test_<rand>`, MigrationRunner + InstallerSeeder, InstallerTestServer harness, serial.

## Approaches
1. **Direct SQL aggregates on read (recommended)** — `ReportsRepository` with GROUP BY queries; no cache tables.
   - Pros: zero schema changes, small V1 data volume, shared-hosting safe, matches spec (basic reports).
   - Cons: full scans on huge ranges (not a V1 concern; indexes mitigate).
   - Effort: Low.
2. **Cache/summary tables populated on order events** — pre-aggregated per day.
   - Pros: O(1) reads at scale.
   - Cons: schema + invalidation complexity, spec doesn't demand it, overbuild for V1.
   - Effort: High.

## Key Implementation Notes
- Product/category filters MUST use `EXISTS` subqueries (not JOINs) to avoid duplicating order-level money sums.
- Venta bruta = `SUM(merchandise_total_cents)`; Descuentos = `SUM(item+order+coupon+payment discounts)`; Delivery cobrado = `SUM(delivery_fee_cents)`; Remuneración delivery = `SUM(delivery_payout_cents)`; Venta neta operativa = bruta − descuentos + delivery − payout; Pedidos = `COUNT(DISTINCT orders.id)`.
- Daily granularity via `GROUP BY DATE(created_at)` (SQL dates, consistent with existing code).
- CSV: stream `text/csv` with UTF-8 BOM (Excel/es-AR), same filters as page.

## Recommendation
Approach 1. V1 report set = exactly spec section 21: one "Ventas" report (7 spec filters → 6 result metrics + daily breakdown rows) + CSV export + Dashboard V1 metrics block on `/admin/`.

## Risks
- Product/category filter JOIN duplication inflating money sums (mitigated: EXISTS).
- Dashboard metric mapping (e.g. "buscando delivery", "en camino") needs delivery-state mapping pinned in design.
- Cancelled/rejected/expired orders counting as "ventas" by default would distort totals (assumption to confirm).

## Ready for Proposal
Yes.
