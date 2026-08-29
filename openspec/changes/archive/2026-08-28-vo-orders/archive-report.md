# Archive Report — vo-orders (Phase 7)

**Archived:** 2026-08-28 · **Verdict:** verified PASS · **Suite at close:** 79 tests / 0 failures (two consecutive green runs)

## Scope delivered

Real order creation from cart confirmation (Phase 7 of Vender Online Core V1):

- **Unit A** — migration `006_create_orders` (orders with full pricing-snapshot columns, order_items, order_item_modifiers, order_addresses, order_discounts, promotion_usage, stock_movements, customers, order_counters, idempotency_keys; stock quantity/reserved on branch_items), `OrderStateMap` (9 spec states: pending, change_proposed, accepted, in_progress, ready, completed, rejected, cancelled, expired).
- **Unit B** — `OrderService::createFromCart` single transaction: cart lock → recompute quote → exact accepted-totals compare (price_changed rejection carries fresh breakdown) → customer resolve (guest snapshot or linked account) → per-business sequential number `B-NNNNNN` via locked counter → random 64-hex public token → immutable snapshots → order_discounts → cart items cleared; `OrderRepository`, `DbIdempotencyStore` (strict request-match replay; markers persist only for successful creations).
- **Unit C** — `POST /api/cart/confirm` (200 order JSON; 409 price_changed with new breakdown; 422 typed validation; idempotent replay), token-only public page `GET /pedido/{public_token}` (Spanish; sequential number intentionally 404), checkout page + cart.js confirm wiring (JS never computes totals; one idempotency key per checkout session).
- **Unit D** — `StockService` (reserved_quantity model, atomic conditional decrement, single movement reason=reserve with signed delta; unlimited mode skips), promotion/coupon `times_used` + `promotion_usage` exactly-once with unique backstops inside the same transaction, `OrdersAdminController` read-only Spanish list/detail (products.manage, branch scope, audit with request_id, NO transition controls — Phase 9).

## Verification summary

- 19/19 requirements across 5 capability deltas (orders 9, inventory-stock 5, customers 3, cart-api mod 1, pricing-engine mod 1).
- Independent inline probe (bounded mode, after 3 subagent-launch cancellations by model usage limit): **35/35 checks**, hand-computed to the cent (gross 4200 → promo 420 → coupon 1890 → merch 1890); overselling rollback and price_changed zero-side-effect verified; state chain and illegal transitions verified.
- Two probe expectations were corrected during verification after inspecting actual (design-consistent) semantics: order line totals are discount-prorated and sum exactly to merchandise_total; idempotency markers roll back on rejection so the key is retryable.

## Compressed planning note

Maintainer velocity mode applied (combined explore+propose pass; combined spec+design+tasks pass; four apply units; single phase-end full suite). No TDD RED-first per explicit maintainer preference recorded during this phase — tests written alongside implementation as verification evidence.

## Specs synced

- NEW main specs: `openspec/specs/orders/`, `openspec/specs/inventory-stock/`, `openspec/specs/customers/`.
- MERGED: `cart-api` R7 replaced (preview-only confirmation → real idempotent confirmation; purpose line updated), `pricing-engine` Scope guards replaced (order-side-effect carve-out after acceptance + usage-recording scenarios).
- Main spec count: 17 capabilities.

## Open follow-ups (non-blocking)

1. Map `IdempotencyConflictException` (different-payload replay) to a 409 API response instead of generic 500.
2. Document prorated line-total semantics in the admin order detail legend (operator clarity).
3. Add bounded retry to test harness `InstallerTestServer::start()` (transient "PHP server did not start" under full-suite load, seen once).
4. Naming consistency `cart_items.qty` vs `order_items.quantity` — cosmetic, future migrations.
5. Payment records/states (Phase 8), operation board transitions (Phase 9), delivery rates/PIN (Phase 10) remain out of scope by design.
6. MariaDB startup: down after host reboot; started via `D:\xampp\mysql_start.bat` — Windows service install still pending maintainer admin step.
7. Maintainer commit decision: ~90+ files uncommitted since `0439b22` (Phases 2B-7, working tree only).

## Traceability

Engram topic keys: `sdd/vo-orders/proposal` (3691), `spec`/`design`/`tasks` (3692-3694), `apply-progress` (3696), `verify-report` (3721), archive report (this document). Probe script: `%TEMP%\opencode\vo7_verify_probe.php`.
