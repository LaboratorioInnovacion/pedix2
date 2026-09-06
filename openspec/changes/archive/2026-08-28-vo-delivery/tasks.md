# Tasks: vo-delivery — Zones, Rates, Persons, Assignment & PIN

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1000 total (≈300 A / ≈350 B / ≈350 C incl. tests) |
| 400-line budget risk | Medium |
| Chained PRs recommended | Yes |
| Suggested split | Unit A → Unit B → Unit C (each <400 lines) |
| Delivery strategy | ask-on-risk |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: Medium

Preflight note: run is NO-COMMIT — units verified on working tree; chain/PR decision deferred to orchestrator/user.

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| A | Migration 009 + repo/matcher + cart resolution + order fee force | PR 1 | `D:\xampp\php\php.exe tools\run-tests.php --filter DeliveryZoneTest` then OrderCreationTest | Scratch DBs `vo_del10_test_<rand>` (MariaDB, serial) | Revert Unit A files; drop migration 009 artifacts |
| B | DeliveryService lifecycle + cancel cascade + board/detail actions | PR 2 | `D:\xampp\php\php.exe tools\run-tests.php --filter DeliveryServiceTest` | Scratch DB + seeded orders | Revert Unit B files; deliveries stay `pending` |
| C | `/admin/delivery` CRUD + routing/templates + public PIN | PR 3 | `D:\xampp\php\php.exe tools\run-tests.php --filter DeliveryAdminHttpTest` | HTTP harness servers per existing pattern | Revert Unit C files; board unchanged |

## Phase 1: Unit A — Schema + Coverage + Fee Integrity

- [x] 1.1 Create `009_delivery.sql.php` with exact DDL from design.md (tables, cart/order columns, `deliveries.manage` seed, `delivery.pin_key` seed). Refs schema-baseline 009.
- [x] 1.2 Fixture: `BaselineSchemaTest` migration list += `'009 delivery'`; expected tables += 4 delivery tables; remove `deliveries` from deferred list. Refs schema-baseline.
- [x] 1.3 Fixture: `CatalogSchemaTest` + `OrdersSchemaTest` migration lists += `'009 delivery'`; `CatalogSchemaTest` deferred list drops `deliveries`.
- [x] 1.4 Create `api/app/Delivery/DeliveryRepository.php` (zone CRUD lookups, `matchZone`, person queries, delivery row ops) + `DeliveryUnavailableException`.
- [x] 1.5 Create `tests/DeliveryZoneTest.php`: normalization (accents/case), comma/slash terms, substring match, first-active-by-id, no-match typed error, 009 schema pins. Refs D1–D2.
- [x] 1.6 Modify `CartService::setCheckoutData` + `CartRepository::updateCart`: resolve+persist zone/fee/payout for delivery; pickup untouched. Refs cart-api R6.
- [x] 1.7 Modify `CartService::state()` (cart fee) + `CartApiController` typed `delivery_unavailable` catch. Refs cart-api R5/R6.
- [x] 1.8 Modify `OrderService` (`quoteRequest` forces cart fee; snapshot `delivery_zone_name`/`delivery_payout_cents`; create pending delivery) + `OrderRepository::insertOrder` cols + `bootstrap/app.php` wiring. Refs orders R1.
- [x] 1.9 Fixture: `OrderCreationTest` — `seedCatalog` seeds matching zone; `accepted()` reads cart-row fee; assert payout/zone snapshot + delivery row. Refs orders R1.
- [x] 1.10 Fixture: `CartApiScratchDatabase::seed()` adds one delivery zone (CartPageTest delivery post resolves). Verify: Unit A focused tests + CartApiTest + CartPageTest green.

## Phase 2: Unit B — Delivery Lifecycle + Board Actions

- [x] 2.1 Create `api/app/Delivery/DeliveryStateMap.php` + `DeliveryService` (assign/reassign/pickup/deliver/fail/cancel; permissions `deliveries.assign`/`deliveries.reassign`/`deliveries.manage`; branch scoping; audit `deliveries.*`). Refs D5–D8.
- [x] 2.2 Implement PIN logic: HMAC 6-digit from `delivery.pin_key`, SHA-256 storage, `hash_equals`, 5-attempt cap → `failed('pin_exhausted')`, consume on success, recompute for redisplay. Refs D7.
- [x] 2.3 Modify `OrderOperationsService`: `completeFromDelivery()` (ready→completed, caller-held transaction) + cancel cascade to active delivery. Refs orders ADDED + D8.
- [x] 2.4 Modify `OperationsAdminController` + `operacion.php` + `orders_detail.php`: `asignar/reasignar/retirar/entregar/fallar` routes with person dropdown (branch-scoped), PIN input, fail reason; buttons only when legal. Refs D6–D8.
- [x] 2.5 Create `tests/DeliveryServiceTest.php`: full chain, illegal transitions, permission denials (audit), branch-foreign person, order-not-ready, 5 wrong PINs, PIN consume, cancel cascade. Verify Unit B focused command + AdminOperationsHttpTest + OperationsServiceTest green.

## Phase 3: Unit C — Admin CRUD + Public PIN

- [x] 3.1 Create `api/app/Admin/DeliveryAdminController.php` + `templates/delivery.php`: zones CRUD per branch + persons CRUD with branch checkboxes, `deliveries.manage` guard, CSRF, Spanish labels. Refs D1, D4.
- [x] 3.2 Modify `AdminController`: `isDeliveryPath()` dispatch (`/admin/delivery` + subpaths).
- [x] 3.3 Modify `OrderPageController` + `order_confirmation.php`: delivery state + recomputed PIN only while `assigned/picked_up`. Refs D9.
- [x] 3.4 Create `tests/DeliveryAdminHttpTest.php`: CRUD happy paths, 403 without `deliveries.manage`, board/detail action presence by state, public PIN shown while active / hidden after delivered. Extend `OrderConfirmationHttpTest` if cheaper. Verify Unit C focused command.

## Phase 4: Full Verification

- [ ] 4.1 Run full suite serially with scratch DBs: `D:\xampp\php\php.exe tools\run-tests.php` — 113 prior + new tests green; pickup/PRICE_CHANGED/idempotency unchanged.
- [ ] 4.2 Confirm success criteria: fee/payout on cart+order, board actions audited, PIN blocked at 5 attempts + consumed, token-page PIN gating.
