# Tasks: Order Operations Board (vo-operations)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~730 (Unit A ~350, Unit B ~380) |
| 400-line budget risk | Medium |
| Chained PRs recommended | Yes |
| Suggested split | Unit A (service+schema) → Unit B (admin UI) |
| Delivery strategy | ask-on-risk |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: Medium

## Unit A — Core service, schema, stock consume (~350 lines)

Covers specs: operations O1–O3, O5–O7; inventory-stock R6.

- [x] 1.1 Create `api/database/migrations/008_operations.sql.php` with the exact DDL from design (enum MODIFY + DROP/ADD CHECK `chk_stock_movements_reason`); nothing else. Ref R6. *(deviation: `DROP CONSTRAINT` instead of `DROP CHECK` — MariaDB 10.4 rejects MySQL-8 `DROP CHECK` syntax)*
- [x] 1.2 Update migration-list assertions in `tests/BaselineSchemaTest.php:12`, `tests/CatalogSchemaTest.php:13`, `tests/OrdersSchemaTest.php:16` to include `'008 operations'`. Verify: `D:\xampp\php\php.exe tools/run-tests.php --filter BaselineSchemaTest,CatalogSchemaTest,OrdersSchemaTest`.
- [x] 1.3 Add `StockService::consume(businessId, branchId, orderId, items)` — guarded UPDATE decreasing stock+reserved, movement `consume_accepted` only on success. Ref R6.
- [x] 1.4 Add `OrderRepository::lockById`, `findStaleForExpiry(hours)`, `boardRows(userId, branchId)` helpers. Ref O1, O4, O7.
- [x] 1.5 Create `api/app/Orders/OrderOperationsService.php`: `transition()` in one transaction — lock row, `OrderStateMap` legality, `hasPermission()` map (accept/reject/cancel/prepare/mark_ready/prepare), cancel requires non-empty reason, audit `orders.{target}` (from,to,reason,request_id,refund_required). Ref O1–O3, O5.
- [x] 1.6 Wire side effects in `transition()`: accept→consume; reject→release_rejected; cancel→release_cancelled + cancel `pending`/`pending_verification` payment; approved payment stays + `refund_required=true`. Ref O5, O6.
- [x] 1.7 Add `acceptFromPayment(orderId)` (no wrapping transaction, system actor, consume, audit) and change `PaymentService::autoAcceptOrder` to route through it. Ref O6.
- [x] 1.8 Add `expireStaleLazy()`: TTL from `business_settings` `orders.order_expiry_hours` (default 24), `pending`/`change_proposed` → expired + `release_expired` + linked payment cancel + audit; returns count. Ref O7.
- [x] 1.9 Trigger sweep in `OrdersAdminController::listing` and `OrderPageController::show` (try/catch beside payment sweep). Ref O7. *(implemented in Unit B batch per orchestrator)*
- [x] 1.10 Write `tests/OperationsServiceTest.php`: legality chain + illegal rejection; permission-gating (no mutation + audit path); consume idempotent (double accept); auto-accept consumes; cancel reason-required/release/payment-cancel/approved-stays+refund flag; reject release; sweep stale/fresh + release + payment cancel; `consume_accepted` insert ok / `manual` still rejected. Verify: `D:\xampp\php\php.exe tools/run-tests.php --filter OperationsServiceTest,PaymentServiceTest,StockMovementTest`.

## Unit B — Admin UI, wiring, HTTP tests (~380 lines)

Covers specs: operations O1–O5, O7 (board triggers); admin-shell scope-guard mod + 3 added requirements.

- [x] 2.1 Create `api/app/Admin/templates/operacion.php`: Spanish sections Pendiente/Cambio propuesto/Aceptado/En preparación/Listo; cards with number, branch, item count, total, age; CSRF POST buttons per legal status; branch filter; manual refresh + `?auto=1` 30s meta-refresh. Ref O4.
- [x] 2.2 Create `api/app/Admin/OperationsAdminController.php`: GET `/admin/operacion` (orders.view gate, boardRows, sweep trigger); POST `/admin/operacion/{id}/{accion}` mapping to `OrderOperationsService::transition` with per-action permission, CSRF, redirect back; 403 `authz.denied`; 419 CSRF; 422 missing reason. Ref O1–O5, O7.
- [x] 2.3 Wire `AdminController`: `isOperationsPath()` + instantiation; add "Operación" card to `templates/dashboard.php`. Ref admin-shell nav + scope guard.
- [x] 2.4 Extend `templates/orders_detail.php`: legal transition buttons + cancel-with-reason form; keep `orders_list.php` button-free. Ref admin-shell transition actions.
- [x] 2.5 Write `tests/AdminOperationsHttpTest.php` (pattern: `AdminOrdersHttpTest`): board 200 renders sections/cards/buttons; branch filter; `?auto=1` meta tag; 403 guard + `authz.denied`; 419 CSRF reject; transition POST happy → redirect + audit row; cancel without reason → 422; cancel with reason → cancelled + payment cancelled; detail buttons render; list has no Aceptar/Rechazar. Verify: `D:\xampp\php\php.exe tools/run-tests.php --filter AdminOperationsHttpTest,AdminOrdersHttpTest,AdminHttpTest`.

## Phase 3 — Verification

- [ ] 3.1 Contract check: every requirement (O1–O7, R6, admin-shell deltas) has ≥1 passing test or explicit HTTP assertion.
- [ ] 3.2 Serial full-suite run with extended timeout (HTTP tests boot servers); suite green on scratch DBs `vo_ops9_test_<rand>` only.
