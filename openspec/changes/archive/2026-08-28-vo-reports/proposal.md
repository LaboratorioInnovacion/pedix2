# Proposal: vo-reports — Reports & Dashboard V1 (Phase 12)

## Intent
Spec section 21 ("Dashboard y reportes") mandates basic sales reports and dashboard metrics; section 3.2 lists both as `/admin/` pages. Today `/admin/reportes` does not exist (dashboard card shows "Módulo pendiente") and the dashboard is a placeholder grid with no business metrics. This closes Core V1 Phase 12: filterable sales reporting with spec-mandated CSV export and the Dashboard V1 metric block.

## Scope
### In Scope
- `GET /admin/reportes`: "Ventas" report gated by `reports.view` (already seeded), branch-scoped via `user_branches`.
- Filters (exact spec set): fecha (presets hoy/7d/30d/custom range), sucursal, producto, categoría, medio de pago, modalidad, estado.
- Results: cantidad de pedidos, venta bruta, descuentos, delivery cobrado, remuneración delivery, venta neta operativa; daily breakdown rows within range.
- CSV export of the same filtered dataset (`text/csv`, UTF-8 BOM) — spec-mandated.
- Dashboard V1 block on `/admin/`: ventas de hoy, pedidos de hoy, ticket promedio, counts (nuevos, preparando, listos, buscando delivery, en camino, cancelados, rechazados), stock bajo.
- Dashboard "Reportes" nav card links to `/admin/reportes`; denials audited as `authz.denied`.

### Out of Scope
- "Reportes Pro" (spec line 1415), PDF, charts/JS graphing libs (server-rendered tables only), scheduled/email reports, real-time analytics, cache/summary tables, data warehouse, saved custom reports, per-driver payout reports, stock-consumed report (not named in section 21).

## Capabilities
### New Capabilities
- `reports`: Sales report page, filters/metrics/CSV export, Dashboard V1 metrics, permission + branch-scope rules.

### Modified Capabilities
- `admin-shell`: "Reportes" dashboard card links to `/admin/reportes`; scope-guard lists the reports page as spec-governed (like operacion/notificaciones).

## Approach
Read-only SQL aggregates on read (no cache tables; V1 data volume small, indexes already exist in migration 006). `ReportsRepository` (summary/daily/dashboard queries; `EXISTS` subqueries for product/category filters to avoid double-counting order money) + `ReportsService` (filter validation, presets, canonical metric formulas: neta = bruta − descuentos + delivery cobrado − remuneración delivery). SQL dates only (consistent with existing code). **No migration** — `idx_orders_created_at`, `idx_orders_branch`, `idx_order_items_order` etc. already exist.

## Affected Areas
| Area | Impact | Description |
|------|--------|-------------|
| `api/app/Admin/ReportsAdminController.php` | New | Report page + CSV stream |
| `api/app/Reports/{ReportsRepository,ReportsService}.php` | New | Read-only aggregates + validation |
| `api/app/Admin/templates/{reportes,dashboard}.php` | Modified/New | Report UI + metrics block (Spanish) |
| `api/app/Admin/AdminController.php` | Modified | Routing (`/admin/reportes`, CSV path) |
| `tests/Reports*Test.php` | New | Repository + HTTP tests |

## Risks
| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Product/category filter double-counts money | Med | EXISTS subqueries, tested with seeded scenarios |
| Dashboard delivery-state mapping ambiguous ("buscando delivery"/"en camino") | Med | Pin exact mapping in design phase |
| Default status set distorts "ventas" | Low | Assumption below; estado filter available |

## Rollback Plan
Pure additive read-only feature; revert routing lines + delete new files. No schema/data changes, so rollback is trivial and lossless.

## Dependencies
- None external. Uses existing orders/payments/delivery/stock schema and `reports.view` permission.

## Success Criteria
- [ ] `/admin/reportes` renders filtered totals + daily breakdown for scoped branches; CSV downloads with same filters.
- [ ] Without `reports.view`: 403 + `authz.denied` audit entry.
- [ ] Dashboard shows all 11 spec metrics scoped to user branches.
- [ ] Full suite green (150 existing + new tests); no migration added.

## Proposal Question Round
Not applicable (auto mode). Assumptions to confirm in spec phase: (A1) default report excludes `cancelled/rejected/expired` orders; (A2) "medio de pago" filter uses `orders.payment_method` snapshot; (A3) dashboard low-stock = tracked `branch_items` at/below threshold (threshold pinned in design).
