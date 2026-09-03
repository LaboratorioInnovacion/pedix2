# Proposal: Order Operations Board (Phase 9)

## Intent

Orders stop at `pending` after creation: no operator can accept, reject, prepare, complete, or cancel them, stock stays reserved forever on abandoned carts, and pending orders never expire. Phase 9 delivers the operational core from master spec sections 11–13 and 29: an operator board with audited state transitions, stock consume/release wiring, and lazy expiry — without workers.

## Scope

### In Scope
- `OrderOperationsService`: single gateway for legal transitions (accept, reject, prepare-start, mark-ready, complete, cancel) with per-action permission map, branch scope, audit, and stock side effects.
- Stock lifecycle completion: `consume` on accept (migration 008 adds `consume_accepted` movement reason); `release_cancelled`/`release_expired` wired to cancel/expire via existing `StockService::release`.
- Lazy expiry sweep (`pending`/`change_proposed` older than configured hours → `expired` + stock release), triggered on board/orders/public reads, mirroring `PaymentService::expireStaleLazy`.
- `/admin/operacion` board: server-rendered Spanish sections per active status with POST action buttons (CSRF), branch filter, manual refresh + optional `?auto=1` meta-refresh.
- Transition buttons on order cards; cancellation requires a reason (audited).
- Payment interaction on cancel: cancel `pending`/`pending_verification` payment; `approved` payments stay approved with audit flag `refund_required`.

### Out of Scope
- `change_proposed` propose/modify flow (needs Phase 11 OpenWA client confirmation); board only shows such orders.
- Independent preparation state machine (sec 12) — deferred to Phase 10 where `handed_over` matters.
- Refund automation, delivery assignment, notifications, ticket/comanda printing, order editing, kitchen hardware, JS polling.

## Capabilities

### New Capabilities
- `operations`: operator board, transition execution rules, cancellation semantics, lazy expiry.

### Modified Capabilities
- `inventory-stock`: new consume-on-accept requirement (reason `consume_accepted`, migration 008 enum extension).
- `admin-shell`: operation board entry in dashboard navigation; scope guard adjusted so the operation board is spec-governed.

## Approach

`OrderStateMap` stays the single source of legal transitions. `OrderOperationsService.transition(orderId, target, actor, reason)` wraps repository `markStatus` in a transaction: enforce permission per target, audit `orders.{target}` with from→to+reason, consume stock on accept, release on cancel/reject/expire, cancel linked pending payment on cancel. `PaymentService::autoAcceptOrder` routes through the same service so auto-accepted orders also consume stock (reserved-quantity guard makes repeats no-ops). Migration 008 modifies the `stock_movements.reason` ENUM and re-adds its CHECK.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `api/database/migrations/008_operations.sql.php` | New | Consume movement reason enum + CHECK |
| `api/app/Orders/OrderOperationsService.php` | New | Transition gateway, expiry sweep |
| `api/app/Inventory/StockService.php` | Modified | `consume()` method |
| `api/app/Payments/PaymentService.php` | Modified | auto-accept routes through operations service |
| `api/app/Admin/OperationsAdminController.php` + `templates/operacion.php` | New | Board UI (Spanish) |
| `api/app/Admin/AdminController.php`, `templates/dashboard.php` | Modified | Route wiring + nav card |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Double consume on accept (manual + auto path) | Low | Guarded UPDATE (`reserved_quantity>=qty`) makes repeats no-ops; tests cover both paths |
| ENUM migration rebuild on `stock_movements` | Low | Additive value; table rebuild is safe at current scale; backup-before-migrate per config rules |
| Lazy expiry surprises operators | Medium | Only pre-accept states, configurable `orders.order_expiry_hours` (default 24), audited with released stock visible |

## Rollback Plan

Migration 008 is additive (enum value); rollback = revert code units; expired/cancelled rows remain historical per audit-preservation rules. No destructive DDL.

## Dependencies

- Phases 1–8 (orders, stock, payments, admin shell) — complete.
- MariaDB running locally for tests (scratch `vo_ops9_test_<rand>`).

## Success Criteria

- [ ] Operators with `orders.*` permissions execute all legal transitions from `/admin/operacion`; illegal ones are rejected by the state machine and audited.
- [ ] Accept consumes simple stock (`consume_accepted`); cancel/expire/release paths decrement reserved stock exactly once.
- [ ] Cancelling an order cancels its pending payment; approved payments stay approved with `refund_required` audit.
- [ ] Stale pending orders expire via lazy sweep with stock released; full suite green.
