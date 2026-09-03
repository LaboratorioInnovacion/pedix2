# Design: Order Operations Board (vo-operations, Phase 9)

## Technical Approach

Keep `OrderStateMap` as the single source of legal transitions. Add `OrderOperationsService` as the only writer of operator-driven order status: `transition(orderId, to, operatorUserId, reason?)` wraps repository `markStatus` in one transaction — permission check per target, legal-transition check, stock side effects, payment interaction on cancel, one audit row. Add `StockService::consume()` (reason `consume_accepted`) and `expireStaleLazy()` for TTL-based sweep triggered lazily on board/orders-list/public reads (mirrors `PaymentService::expireStaleLazy`). UI: `OperationsAdminController` + `operacion.php` Spanish board, transition buttons on `orders_detail.php`, dashboard nav card.

## Architecture Decisions

### Decision: Migration 008 touches `stock_movements` only
**Choice**: `api/database/migrations/008_operations.sql.php` (migration name `008 operations`) executes exactly:
```sql
ALTER TABLE stock_movements MODIFY COLUMN reason ENUM('reserve','release_cancelled','release_rejected','release_expired','consume_accepted','adjustment') NOT NULL;
ALTER TABLE stock_movements DROP CHECK chk_stock_movements_reason;
ALTER TABLE stock_movements ADD CONSTRAINT chk_stock_movements_reason CHECK(reason IN ('reserve','release_cancelled','release_rejected','release_expired','consume_accepted','adjustment'));
```
**Explicit**: `orders` needs NO new column — expiry derives from `created_at` + business setting `orders.order_expiry_hours` (default 24). NO permission migration — `InstallerSeeder::PERMISSIONS` already seeds the needed keys.
**Alternatives**: `expires_at` column (rejected: created_at suffices, avoids schema churn); new `orders.operate` permission (rejected: duplicates seeded keys).
**Rationale**: additive enum value; no destructive DDL; rollback = code revert.

### Decision: Permission map = seeded `orders.*` keys
**Choice** (verified against `InstallerSeeder::PERMISSIONS`): `accept→orders.accept`, `reject→orders.reject`, `cancel→orders.cancel`, `in_progress→orders.prepare`, `ready→orders.mark_ready`, `completed→orders.prepare` (no `orders.complete` exists). Board GET gate: `orders.view`.
**Alternatives**: hardcode `products.manage` like `/admin/pedidos` (rejected: too coarse for per-action rules); new permission (rejected above).
**Rationale**: spec sec 25 guards + master permission list; owner role already holds all keys.

### Decision: Service-level permission gating via Connection
**Choice**: `OrderOperationsService` checks permissions with a private `hasPermission(userId, key)` running the same DISTINCT-permission SQL `PermissionGuard` uses, through its `Connection` (no PDO dependency).
**Alternatives**: trust controller checks (rejected: orchestrator requires service-level gating tests); inject `PermissionGuard` (mismatch: it requires raw PDO).
**Rationale**: gateway stays the single enforcement point; controller still denies early for UX.

### Decision: Consume is guarded and idempotent
**Choice**: `consume()` per simple line runs `UPDATE branch_items SET stock_quantity=stock_quantity-?, reserved_quantity=reserved_quantity-? WHERE ... AND stock_quantity>=? AND reserved_quantity>=?`; movement inserted only when `rowCount===1`. Repeat calls fail the guard → no-op, no negative stock, no duplicate movement. Items come from `order_items` rows (compatible with `StockService::quantities()`).
**Alternatives**: check-then-update (race-prone); restore stock on cancel after consume (rejected: consumed stock is not auto-restored per explore finding (d)).
**Rationale**: matches `release()` guarded pattern; makes double-consume (manual accept + auto-accept) impossible.

### Decision: Auto-accept routes through the gateway without nested transactions
**Choice**: `PaymentService::autoAcceptOrder` delegates to `OrderOperationsService::acceptFromPayment(orderId)` — an internal path (system actor, no permission check, audited, consumes stock, no-op if already accepted) that does NOT open a transaction. `PdoConnection::transaction()` throws on nesting and the payment transition already holds one.
**Alternatives**: reuse `markAcceptedIfPending` (rejected: skips stock consume); nested transaction (impossible per PdoConnection).
**Rationale**: satisfies "auto-accepted orders also consume stock"; guard makes repeats no-ops.

### Decision: Lazy sweep mirrors payments wiring
**Choice**: `expireStaleLazy()` selects `pending`/`change_proposed` orders with `created_at <= NOW() - TTL`, reads TTL per business from `business_settings` via `settings` lookup (default 24), then per order: `markStatus(expired)` + `release(...,'release_expired')` + cancel linked `pending`/`pending_verification` payments + audit `orders.expired` (system actor). Returns count. Triggered on: board GET, `/admin/pedidos` GET, `/pedido/{token}` GET (added next to the existing payment sweep in `OrderPageController::show`). No workers.
**Alternatives**: cron/worker (forbidden by stack); `expires_at` column (rejected above).
**Rationale**: spec sec 29 lazy operations; expiry releases `release_expired` (not `release_cancelled` — matches reason semantics).

### Decision: Board stays server-rendered with POST+CSRF
**Choice**: `GET /admin/operacion` renders sections Pendiente / Cambio propuesto / Aceptado / En preparación / Listo. One scoped query (`user_branches` join, optional `branch_id` filter) grouped in PHP; card shows number, branch, item count, total, age. Actions: `POST /admin/operacion/{id}/{aceptar|rechazar|preparar|listo|completar|cancelar}` (cancel posts required `reason`). Branch filter GET dropdown; manual refresh link; `?auto=1` emits `<meta http-equiv="refresh" content="30">`. Success/failure redirect back with flash query param. `/admin/pedidos` list keeps zero buttons (guard in `AdminOrdersHttpTest`); detail gains buttons + cancel-with-reason form.

## Data Flow

```
Operator ──POST+CSRF──► OperationsAdminController ──► OrderOperationsService.transition()
   │                            │ guard orders.view / per-action perm        │
   │                            ▼ audit authz.denied                        ├─► OrderStateMap + markStatus
   └─GET board ──► expireStaleLazy() ─► expired+release+payment cancel      ├─► StockService consume/release
                                                                              ├─► PaymentService.cancelPayment (cancel)
Public read /pedido ──► OrderPageController ──► expireStaleLazy()            └─► AuditService orders.{target}
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `api/database/migrations/008_operations.sql.php` | Create | Consume reason enum + CHECK rebuild (exact DDL above) |
| `api/app/Orders/OrderOperationsService.php` | Create | Transition gateway, `acceptFromPayment`, `expireStaleLazy` |
| `api/app/Inventory/StockService.php` | Modify | Add guarded `consume()` |
| `api/app/Orders/OrderRepository.php` | Modify | `lockById`, `boardRows`, `findStaleForExpiry` helpers |
| `api/app/Payments/PaymentService.php` | Modify | `autoAcceptOrder` routes through gateway |
| `api/app/Orders/OrderPageController.php` | Modify | Trigger order sweep on public read |
| `api/app/Admin/OperationsAdminController.php` | Create | Board + action routes, guards, CSRF |
| `api/app/Admin/AdminController.php` | Modify | Route wiring `isOperationsPath` |
| `api/app/Admin/templates/operacion.php` | Create | Spanish board |
| `api/app/Admin/templates/orders_detail.php` | Modify | Transition buttons + cancel-with-reason form |
| `api/app/Admin/templates/dashboard.php` | Modify | "Operación" nav card |
| `api/app/Admin/OrdersAdminController.php` | Modify | Trigger sweep on list load |
| `tests/{BaselineSchemaTest,CatalogSchemaTest,OrdersSchemaTest}.php` | Modify | Migration list += `'008 operations'` |
| `tests/OperationsServiceTest.php` | Create | Unit A tests |
| `tests/AdminOperationsHttpTest.php` | Create | Unit B HTTP tests |

## Interfaces / Contracts

```php
final class OrderOperationsService {
  public function __construct(Connection $db, OrderRepository $orders, StockService $stock,
      PaymentRepository $payments, PaymentService $paymentsSvc, ?AuditService $audit = null, ?string $requestId = null) {}
  public function transition(int $orderId, string $to, int $operatorUserId, ?string $reason = null): array; // throws InvalidTransition|InvalidArgumentException|DomainException
  public function expireStaleLazy(): int;
  public function acceptFromPayment(int $orderId): array; // system actor, no wrapping transaction
}
// StockService: public function consume(int $businessId, int $branchId, int $orderId, array $items): void; // reason fixed 'consume_accepted'
// OrderRepository: lockById(int): ?array (FOR UPDATE); findStaleForExpiry(int $hours): array; boardRows(int $userId, ?int $branchId): array
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Service (`OperationsServiceTest`) | Transition legality chain, illegal rejection, permission gating (perm removed → DomainException, no mutation), consume idempotency, cancel: reason required + `release_cancelled` + pending payment cancelled + approved stays + `refund_required` audit, reject `release_rejected`, sweep TTL stale/fresh + stock release + payment cancel, auto-accept consume | Scratch DB `vo_ops9_test_<rand>`, migration runner + manual seeds; serial `--filter OperationsServiceTest` |
| Schema | Migration list grows to `008 operations`; `consume_accepted` insert ok; `manual` still rejected | Update 3 schema tests + asserts |
| HTTP (`AdminOperationsHttpTest`) | Board 200 sections/cards/buttons, branch filter, `?auto=1` meta tag, guard 403 + `authz.denied`, CSRF 419, transition POST happy → audit + redirect, cancel without reason 422, detail buttons render, list stays button-free | `InstallerTestServer` + `InstallerSeeder` scratch (pattern: `AdminOrdersHttpTest`); serial filter |

## Threat Matrix

N/A — no routing/shell/subprocess, VCS/PR automation, executable-file classification, or process-integration boundary. New HTTP admin routes are covered by CSRF (419), permission guard (403 + `authz.denied`), branch scoping (`user_branches`), and audit requirements O2–O4.

## Migration / Rollout

Migration 008 is additive (enum value + CHECK rebuild, safe at current scale; backup-before-migrate per config rules). Rollback = revert code units; expired/cancelled rows stay historical. No feature flags.

## Open Questions

- None blocking. Noted assumption (from proposal/explore): `ready→completed` requires `orders.prepare` because no `orders.complete` permission is seeded.
