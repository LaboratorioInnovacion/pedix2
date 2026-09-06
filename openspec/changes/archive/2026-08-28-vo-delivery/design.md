# Design: vo-delivery — Zones, Rates, Persons, Assignment & PIN

## Technical Approach

Server-owned delivery economics: zones are branch-scoped text-match rows; `CartService::setCheckoutData` resolves coverage once and persists `delivery_zone_id/fee/payout` on the cart; preview/confirm quote from the cart-stored fee; `OrderService` forces that fee (client value ignored) and snapshots payout + zone name + creates the `pending` delivery row. `DeliveryService` owns the state machine; board/detail actions and `/admin/delivery` CRUD are additive. Implements specs: delivery D1–D9, cart-api R5/R6, orders R1 + state-machine guard, schema-baseline 009.

## Architecture Decisions

| # | Decision | Alternatives | Rationale |
|---|----------|--------------|-----------|
| 1 | Text-match zones (`match_terms` substring, first active by id) | Geocoding distance tiers | Spec sec 16 stand-in; geocoding is a non-goal (proposal). Deterministic + testable. |
| 2 | Fee resolution at `setCheckoutData`, persisted on cart | Resolve at confirm | Client cannot inject fees; preview shows the real total; one source of truth. |
| 3 | `OrderService` forces cart fee in `quoteRequest()` | Trust `accepted[delivery_fee_cents]` | Integrity fix: `PRICE_CHANGED` still guards totals, but the fee source is server-only. |
| 4 | PIN = 6 digits from `HMAC-SHA256(pin_key, delivery_id:order_id)` (first 3 bytes mod 10^6, zero-padded), stored SHA-256 (`pin_hash CHAR(64)`), constant-time `hash_equals`, cap 5 attempts → `failed` reason `pin_exhausted`, consumed (hash nulled) on success | Random PIN stored encrypted | Recomputable for token-page redisplay without storing plaintext; spec sec 17 tension resolved. |
| 5 | Delivery row created in `createFromCart` transaction (delivery orders only) | Lazy row on first assign | UNIQUE(order_id) invariant; board lists pending deliveries with zero queries extra. |
| 6 | `OrderOperationsService::completeFromDelivery()` (mirrors `acceptFromPayment`: caller holds transaction, no nesting) + delivery cancel cascade inside `transitionWithinTransaction` case `cancelled` | DeliveryService mutating orders directly | Keeps single order-transition gateway; reuses audit + state map. |
| 7 | `DeliveryUnavailableException extends CartValidationException`, code `delivery_unavailable`, 422, message `No tenemos cobertura de envío para esa dirección.` | Generic cart_error | Typed per spec; Spanish per proposal. |
| 8 | Deliver requires order `ready` (typed `ORDER_NOT_READY`) | Complete from any state | Matches OperationsService chain; OrdersSchemaTest pins untouched. |

### DeliveryStateMap

```
pending   → assigned | cancelled
assigned  → picked_up | assigned (reassign) | failed | cancelled
picked_up → delivered | failed | cancelled
delivered | failed | cancelled → terminal
```
Permissions: assign/reassign/pickup → `deliveries.assign` (reassign → `deliveries.reassign`); deliver → `deliveries.assign` + PIN; fail → `deliveries.assign` + mandatory reason; cancel direct → `deliveries.manage` (order-cancel cascade: no extra permission). Assignment only for orders `accepted`+; deliver only when order `ready`. All actions branch-scoped via `user_branches`; audit `deliveries.{action}` on entity `delivery:{id}`.

### Zone matcher

Normalize: `mb_strtolower`, trim, strip accents via `strtr` map (áéíóúñü→aeiounu). Haystack = `street . ' ' . city`. Terms split on `,/`; match = substring contains. Candidates: active zones of cart branch `ORDER BY id ASC`; first hit wins. No match → typed exception.

## Data Flow

```
checkout-data(delivery) ──► DeliveryRepository.matchZone(branch, address)
        │ resolves                        │ no match
        ▼                                 ▼
carts.delivery_zone_id/fee/payout    422 delivery_unavailable
        ▼
preview/confirm: quote(delivery_fee_cents = cart row)
        ▼
OrderService::createFromCart ─► orders(+zone_name,+payout) + deliveries(pending,pin_hash)
        ▼
board: assign→picked_up→deliver(PIN) ─► OrderOperationsService::completeFromDelivery
order cancel ─► deliveries.cancelled (cascade)
/pedido/{token}: delivery active? ─► recompute PIN, display
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `api/database/migrations/009_delivery.sql.php` | Create | DDL below + `deliveries.manage` seed (003 pattern) + `delivery.pin_key` per business |
| `api/app/Delivery/DeliveryRepository.php` | Create | zone CRUD/match, persons, delivery row lifecycle, scoped lookups |
| `api/app/Delivery/DeliveryService.php` | Create | assign/reassign/pickup/deliver(PIN)/fail/cancel + permissions + audit |
| `api/app/Delivery/DeliveryStateMap.php` | Create | StateMachine per table above |
| `api/app/Delivery/DeliveryUnavailableException.php` | Create | typed cart validation error (or nested in Cart namespace next to `CartValidationException`) |
| `api/app/Cart/CartService.php` | Modify | resolve zone in `setCheckoutData`; `state()` uses cart fee; optional `?DeliveryRepository` ctor param |
| `api/app/Cart/CartRepository.php` | Modify | `updateCart` persists the 3 new columns |
| `api/app/Cart/CartApiController.php` | Modify | catch `DeliveryUnavailableException` → `JsonResponse::error('delivery_unavailable',…,422)` |
| `api/app/Orders/OrderService.php` | Modify | `quoteRequest` forces cart fee; snapshot payout/zone_name; create pending delivery |
| `api/app/Orders/OrderRepository.php` | Modify | `insertOrder` cols += `delivery_zone_name`,`delivery_payout_cents` |
| `api/app/Orders/OrderOperationsService.php` | Modify | `completeFromDelivery()`; cancel cascade via `DeliveryRepository` (optional ctor param) |
| `api/app/Admin/OperationsAdminController.php` | Modify | actions `asignar/reasignar/retirar/entregar/fallar` → DeliveryService; board/detail delivery context |
| `api/app/Admin/templates/operacion.php`, `orders_detail.php` | Modify | person dropdown, PIN input, fail reason — only when legal |
| `api/app/Admin/DeliveryAdminController.php` + `templates/delivery.php` | Create | `/admin/delivery` zones + persons CRUD, `deliveries.manage` |
| `api/app/Admin/AdminController.php` | Modify | `isDeliveryPath()` dispatch |
| `api/app/Orders/OrderPageController.php`, `templates/order_confirmation.php` | Modify | delivery state + recomputed PIN while `assigned/picked_up` |
| `tests/DeliveryZoneTest.php`, `tests/DeliveryServiceTest.php`, `tests/DeliveryAdminHttpTest.php` | Create | per-unit tests |
| `tests/BaselineSchemaTest.php`, `CatalogSchemaTest.php`, `OrdersSchemaTest.php` | Modify | migration lists += `'009 delivery'`; Baseline tables += 4 delivery tables; remove `deliveries` from both deferred lists |
| `tests/OrderCreationTest.php` | Modify | `accepted()` uses cart-row fee; `seedCatalog()` seeds a matching zone; payout/zone asserts |
| `tests/CartApiScratchDatabase.php` (seed) | Modify | seed one delivery zone (CartPageTest delivery post must resolve) |
| `api/bootstrap/app.php` | Modify | wire `DeliveryRepository` into CartService |

### Migration 009 DDL (exact)

```sql
CREATE TABLE delivery_zones (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,branch_id BIGINT UNSIGNED NOT NULL,name VARCHAR(191) NOT NULL,match_terms VARCHAR(500) NOT NULL,customer_rate_cents INT NOT NULL DEFAULT 0,driver_payout_cents INT NOT NULL DEFAULT 0,is_active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_delivery_zones_branch_active (branch_id,is_active),CONSTRAINT fk_delivery_zones_branch FOREIGN KEY(branch_id) REFERENCES branches(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE delivery_persons (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,business_id BIGINT UNSIGNED NOT NULL,name VARCHAR(191) NOT NULL,phone VARCHAR(64) NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_delivery_persons_business (business_id,is_active),CONSTRAINT fk_delivery_persons_business FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE delivery_person_branches (person_id BIGINT UNSIGNED NOT NULL,branch_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(person_id,branch_id),KEY idx_dpb_branch (branch_id),CONSTRAINT fk_dpb_person FOREIGN KEY(person_id) REFERENCES delivery_persons(id) ON DELETE CASCADE,CONSTRAINT fk_dpb_branch FOREIGN KEY(branch_id) REFERENCES branches(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE deliveries (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,delivery_person_id BIGINT UNSIGNED NULL,state ENUM('pending','assigned','picked_up','delivered','failed','cancelled') NOT NULL DEFAULT 'pending',pin_hash CHAR(64) NULL,pin_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,assigned_at DATETIME NULL,picked_up_at DATETIME NULL,delivered_at DATETIME NULL,failed_reason VARCHAR(191) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_deliveries_order (order_id),KEY idx_deliveries_person_state (delivery_person_id,state),CONSTRAINT fk_deliveries_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,CONSTRAINT fk_deliveries_person FOREIGN KEY(delivery_person_id) REFERENCES delivery_persons(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE carts ADD COLUMN delivery_zone_id BIGINT UNSIGNED NULL, ADD COLUMN delivery_fee_cents INT NOT NULL DEFAULT 0, ADD COLUMN delivery_payout_cents INT NOT NULL DEFAULT 0, ADD CONSTRAINT fk_carts_delivery_zone FOREIGN KEY(delivery_zone_id) REFERENCES delivery_zones(id) ON DELETE SET NULL;
ALTER TABLE orders ADD COLUMN delivery_zone_name VARCHAR(191) NULL, ADD COLUMN delivery_payout_cents INT NOT NULL DEFAULT 0;
-- permission seed (003 pattern), then PIN key per business:
INSERT INTO permissions(permission_key,label) SELECT 'deliveries.manage','deliveries.manage' WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key='deliveries.manage');
INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON p.permission_key='deliveries.manage' WHERE r.name='owner' AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id=r.id AND rp.permission_id=p.id);
INSERT INTO business_settings(business_id,setting_key,setting_value) SELECT b.id,'delivery.pin_key',SHA2(CONCAT(b.id,RAND(),UUID()),256) FROM businesses b WHERE NOT EXISTS (SELECT 1 FROM business_settings bs WHERE bs.business_id=b.id AND bs.setting_key='delivery.pin_key');
```

## Interfaces / Contracts

- `DeliveryService`: `assign(int $deliveryId, int $personId, int $operator)`, `reassign(...)`, `markPickedUp(int $deliveryId, int $operator)`, `deliver(int $deliveryId, string $pin, int $operator)`, `fail(int $deliveryId, string $reason, int $operator)`, `cancel(int $deliveryId, int $operator)`. Typed errors: `InvalidTransition`, `PERMISSION_DENIED` (DomainException), `ORDER_NOT_READY`, `PERSON_NOT_AVAILABLE`, `FAIL_REASON_REQUIRED`, `PIN_INVALID`/`pin_exhausted`.
- `PinService` (inside `DeliveryService` or VO\Delivery\Pin): `code(businessKey, deliveryId, orderId): string`, `hash(code)`, `verify(code, hash): bool`.
- Cart API: checkout-data error `{ok:false,error:'delivery_unavailable',message:'No tenemos cobertura de envío para esa dirección.'}` status 422.
- `PricingService::quote` unchanged (`delivery_fee_cents` param stays; callers pass the resolved value).

## Testing Strategy

| Layer | What | How |
|-------|------|-----|
| Unit A | matcher normalization/first-match; cart persistence; typed no-match; fee force; delivery row + snapshots | `DeliveryZoneTest` + coverage cases in it (scratch DB `vo_del10_test_*`, serial); updated `OrderCreationTest` |
| Unit B | state machine, permissions, PIN flow/attempts/consume, order-ready gate, cancel cascade, audit | `DeliveryServiceTest` |
| Unit C | `/admin/delivery` CRUD + permission; board/detail actions; public PIN visibility | `DeliveryAdminHttpTest` + `OrderConfirmationHttpTest` additions |
| Fixtures | 3 schema tests `+= '009 delivery'`; Baseline tables list; deferred lists drop `deliveries`; `OrderCreationTest` fee; `CartApiScratchDatabase::seed()` zone | explicit tasks 1.x |

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary. Security boundaries covered: HMAC PIN derivation + hashed storage + constant-time compare + attempt cap; permissions per action; branch scoping; CSRF on admin POSTs (existing middleware).

## Migration / Rollout

Migration 009 is additive (new tables/columns with defaults; seeds idempotent). Rollback: drop delivery tables + added columns + `deliveries.manage` permission (pre-launch, no data). Existing installs get `delivery.pin_key` seeded per business by the migration itself.

## Open Questions

- None blocking. Attempt-cap behavior fixed as: 5 wrong PINs → delivery `failed`, reason `pin_exhausted` (per orchestrator decision).
