# Reports Specification

## Purpose

Defines Phase 12 "Ventas" reporting and the Dashboard V1 metrics block (master spec section 21): one filterable sales report at `/admin/reportes` with CSV export, and eleven business metrics on `/admin/`. Read-only; no migration (indexes exist since migration 006).

## Requirements

### Requirement R1: Ventas report page and permission guard

`GET /admin/reportes` MUST render a server-rendered Spanish "Ventas" report for authenticated users holding `reports.view`. The page MUST show the filter form, the six result metrics, and the daily breakdown. Access without `reports.view` MUST return 403 and MUST write an `authz.denied` audit entry naming `reports.view`. Unauthenticated requests MUST redirect to `/admin/login`.

#### Scenario: Authorized user sees the report

- GIVEN an authenticated admin with `reports.view`
- WHEN `GET /admin/reportes` is requested
- THEN the response is 200 with Spanish metric labels, the filter form, and the daily table.

#### Scenario: Denial is audited

- GIVEN an authenticated admin without `reports.view`
- WHEN `GET /admin/reportes` is requested
- THEN the response is 403 and an `authz.denied` audit entry records `reports.view`.

#### Scenario: Anonymous is redirected

- GIVEN no authenticated session
- WHEN `GET /admin/reportes` is requested
- THEN the response redirects to `/admin/login`.

### Requirement R2: Date range filters and presets

The report MUST offer date filtering with presets `hoy`, `7d`, `30d`, and a custom `from`/`to` range; requesting the page without a preset MUST default to the last 30 days. Dates MUST be validated as `Y-m-d` with `from <= to` and a maximum span of 366 days. An invalid or reversed range MUST fall back to the default preset instead of erroring.

#### Scenario: Custom range is honored

- GIVEN valid `from` and `to` 10 days apart
- WHEN the report is requested
- THEN only orders created in that inclusive range are aggregated.

#### Scenario: Invalid range falls back

- GIVEN `from` after `to`
- WHEN the report is requested
- THEN the page renders with the default preset applied and no error.

### Requirement R3: Dimension filters

The report MUST filter by: sucursal (branch), producto (catalog item), categoría (category), medio de pago (payment method snapshot `orders.payment_method`), modalidad (`pickup`/`delivery`), and estado (order status). Product and categoría filters MUST use `EXISTS` subqueries over `order_items` so order-level money is counted exactly once. Estado MUST use the order-status allowlist and, when set, MUST replace the default status set. Unknown values for estado, modalidad, or non-numeric sucursal/producto/categoría identifiers MUST be ignored (filter absent).

#### Scenario: Product filter does not double-count money

- GIVEN one order with two lines of the same product and known cents
- WHEN the report filters by that product
- THEN order totals equal the order's exact amounts once.

#### Scenario: Estado overrides the default set

- GIVEN `estado=cancelled` is selected
- WHEN the report runs
- THEN only cancelled orders are aggregated.

#### Scenario: Invalid values are ignored

- GIVEN `estado=nope` or `producto=abc`
- WHEN the report runs
- THEN those filters are absent and no error occurs.

### Requirement R4: Result metrics and default status set

The report MUST show exactly six metrics in integer cents: cantidad de pedidos (`COUNT` of orders); venta bruta (`SUM(gross_items_cents)`); descuentos (`SUM` of item + order-promotions + coupon + payment discount cents); delivery cobrado (`SUM(delivery_fee_cents)`); remuneración delivery (`SUM(delivery_payout_cents)`); venta neta operativa = bruta − descuentos + delivery cobrado − remuneración delivery. The default status set MUST include every order status except `rejected`, `cancelled`, and `expired` (decision A1); an explicit estado filter replaces it.

#### Scenario: Exact metric cents

- GIVEN seeded orders across days, statuses, payment methods, with discounts, delivery fees, and payouts
- WHEN the report runs unfiltered for their range
- THEN each metric equals the hand-computed exact cents.

#### Scenario: Default set excludes dead orders

- GIVEN orders in `completed` and `rejected` states in range
- WHEN the report runs without estado
- THEN only the non-excluded orders contribute to metrics.

#### Scenario: Neta identity holds

- GIVEN any filtered dataset
- WHEN metrics are computed
- THEN venta neta operativa equals bruta − descuentos + delivery cobrado − remuneración delivery exactly.

### Requirement R5: Daily breakdown

The report MUST include one breakdown row per calendar date inside the range with the same six metrics per day, ordered by date ascending. An empty result MUST render an explicit Spanish "no data" message, not an error.

#### Scenario: Per-day rows match the range

- GIVEN orders on two different dates
- WHEN the report runs
- THEN two daily rows appear with per-day cents summing to the totals.

#### Scenario: Empty dataset renders message

- GIVEN no orders in range
- WHEN the report runs
- THEN the page shows the Spanish empty-state message.

### Requirement R6: CSV export

`GET /admin/reportes/csv` MUST export the same filtered dataset with the same guard, audit, and branch scoping as the page. The response MUST be `text/csv`, UTF-8 with BOM, semicolon-separated, with a Spanish header row, one row per day, and a final `TOTAL` row; the filename MUST be `ventas_<from>_<to>.csv` in `YYYYMMDD` form.

#### Scenario: CSV downloads with filters applied

- GIVEN the page shows filtered results
- WHEN `/admin/reportes/csv` is requested with the same query string
- THEN the body starts with the UTF-8 BOM, contains the header row and the filtered daily rows plus TOTAL.

#### Scenario: CSV export denies like the page

- GIVEN a user without `reports.view`
- WHEN `/admin/reportes/csv` is requested
- THEN the response is 403 and an `authz.denied` audit entry is written.

### Requirement R7: Branch scoping

Every report, CSV, and dashboard query MUST scope orders via `JOIN user_branches` on the current user. The sucursal selector MUST list only the user's branches. Selecting a branch outside the user's scope MUST yield zero rows, never another branch's data.

#### Scenario: Scoped user sees only own branches

- GIVEN two branches with orders and a user scoped to one
- WHEN the report runs unfiltered
- THEN only the scoped branch's orders contribute.

#### Scenario: Out-of-scope branch yields nothing

- GIVEN a user scoped to branch A only
- WHEN the report filters `sucursal` = branch B
- THEN the result is empty.

### Requirement R8: Dashboard V1 metrics block

`GET /admin/` MUST render a business metrics block scoped to the user's branches with exactly: ventas de hoy (`SUM(grand_total_cents)` over today's orders in the R4 default status set); pedidos de hoy (count of the same set); ticket promedio (ventas ÷ pedidos, 0 when there are no orders); and current counts of nuevos (`pending`), preparando (`in_progress`), listos (`ready`), buscando delivery (orders with a `deliveries` row in `pending` or `assigned` state), en camino (`deliveries` row in `picked_up`), cancelados (`cancelled`), rechazados (`rejected`), and stock bajo (scoped `branch_items` rows with `stock_mode='simple'` and `stock_quantity <= 0`). The live status counts carry no date filter.

#### Scenario: Dashboard shows the eleven metrics

- GIVEN seeded orders across statuses, one delivery assigned, one picked up, and one depleted simple-stock item
- WHEN the dashboard renders
- THEN each of the eleven metrics shows the exact seeded value.

#### Scenario: Dashboard metrics respect scope

- GIVEN a user scoped to branch A with orders only in branch B
- WHEN the dashboard renders
- THEN all metrics are zero for that user.
