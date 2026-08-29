# Proposal: Orders, Customers, and Stock Reservation

## Intent

Turn checkout confirmation into real order creation for Phase 7, preserving immutable commercial snapshots, idempotent double-click safety, stock movement history, and promotion/coupon usage effects.

## Scope

### In Scope
- Migration `006` for orders, order items/modifiers, order addresses, discounts/usages, customers, stock movements, order counters, and DB idempotency records.
- `OrderService` creation flow: validate cart, recompute pricing, reject changed totals, snapshot order data, reserve stock, persist discounts, increment usage counters, clear cart.
- Public `POST /api/cart/confirm` and `/pedido/{public_token}` confirmation view with snapshot data.
- Minimal authenticated admin read-only orders list/detail for visibility only.

### Out of Scope
- Payments/payment state persistence and Mercado Pago webhooks (Phase 8).
- Admin/operation state-transition board, preparation workflows, order modifications, ticket generation (Phase 9).
- Delivery assignment/rates/PIN, notifications/OpenWA, customer login portal, refunds, fiscal tickets.

## Capabilities

### New Capabilities
- `orders`: order creation, numbering, snapshots, state machine, read models.
- `inventory-stock`: stock reservation/movement log and cancellation release rules.
- `customers`: guest-first customer snapshots with optional customer account link.

### Modified Capabilities
- `cart-api`: confirmation changes from preview-only to idempotent order creation.
- `pricing-engine`: quote results become order snapshots and trigger promotion/coupon usage only after confirmed order creation.

## Approach

Use existing PHP route/controller/service/repository/PDO architecture. Order numbers are per business using an `order_counters` row locked inside the order transaction, formatted as `B-000123`. `orders.status` starts at `pending` and supports spec states: `pending`, `change_proposed`, `accepted`, `in_progress`, `ready`, `completed`, `rejected`, `cancelled`, `expired`. Phase 7 only creates `pending`; later operational transitions are deferred except cancellation stock release if implemented as a service method.

`POST /api/cart/confirm` requires `idempotency_key` and accepted totals. The transaction locks the cart, validates current catalog/branch/stock/minimums, calls `PricingService::quote()`, rejects `price_changed`, snapshots line/modifier/address/customer/discount totals, inserts promotion usage rows, increments `times_used`, reserves simple stock with signed movements, clears cart items, and stores idempotent response JSON.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `api/database/migrations/006_*` | New | Persistent order/customer/stock/idempotency schema. |
| `api/app/Order/*` | New | Order service, repository, state map, public/admin controllers. |
| `api/app/Inventory/*` | New | Stock validation and movement recording. |
| `api/app/Cart/*` | Modified | Confirm endpoint creates orders and clears cart. |
| `public_html/index.php`, `api/bootstrap/app.php`, `public_html/admin/index.php` | Modified | Register public/API/admin read routes. |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Overselling under concurrent confirms | Med | Row locks, stock check/update in one transaction, focused concurrency-style tests. |
| Duplicate orders from retries | Med | DB-backed idempotency with request hash and stored response. |
| Snapshot drift from live cart/catalog | Med | Copy every mutable commercial field at confirmation. |

## Rollback Plan

Before release, revert code and drop migration `006` tables in a scratch/test DB. After production orders exist, disable confirm routes and ship a forward migration preserving order rows; never hard-delete order/payment/stock history.

## Dependencies

- Existing Phase 6 cart, Phase 5 pricing, Phase 4 catalog, and branch/user baseline tables.

## Success Criteria

- [ ] Accepted unchanged cart creates exactly one order under repeated idempotent requests.
- [ ] Changed prices return preview and create no order.
- [ ] Simple stock is reserved with movements and unavailable stock is rejected.
- [ ] Discounts/usages are snapshotted and counters increment only after order creation.
- [ ] Guest confirmation page and admin read-only list/detail show immutable snapshot data.
