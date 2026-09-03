# Proposal: vo-delivery — Delivery Zones, Rates, Persons, Assignment & PIN

## Intent

Phase 10 of Vender Online Core V1. Delivery exists only as an enum value today: fees are hardcoded `0` / caller-supplied, there is no coverage validation, no driver registry, and no delivery lifecycle. This change makes delivery a real capability: branch zones with text-based coverage, zone-priced fees resolved server-side at checkout-data, delivery person registry, board-driven assignment, and PIN-confirmed delivery.

## Scope

### In Scope
- Migration 009: `delivery_zones` (branch-scoped, match terms, customer rate + driver payout), `delivery_persons` + `delivery_person_branches` pivot, `deliveries` (per-order state machine), cart columns (`delivery_zone_id`, `delivery_fee_cents`, `delivery_payout_cents`), order snapshot columns (`delivery_zone_name`, `delivery_payout_cents`), seeded `deliveries.manage` permission.
- Coverage + rate resolution in `CartService::setCheckoutData` (delivery): normalize address, match active zone terms against street/city, persist zone/fee/payout on cart; no match → typed 422 `delivery_unavailable` (Spanish message).
- Preview/confirm include resolved fee; `OrderService::createFromCart` forces the cart-stored fee (client-passed fee ignored — integrity fix); payout + zone name snapshotted on the order.
- `DeliveryService`: manual assignment (`deliveries.assign`) for delivery orders from `accepted` onward; person swap pre-pickup (`deliveries.reassign`); states `pending→assigned→picked_up→delivered` plus `failed` (mandatory reason) and `cancelled`; `delivered` requires PIN match (≤5 attempts, hashed, consumed) and order `ready`, then completes the order.
- Board/detail actions (asignar, retirar, entregar con PIN, fallar) + `/admin/delivery` CRUD for zones and persons.
- Public order page shows the delivery PIN once assigned (token page is the secret holder; OpenWA sending is Phase 11).

### Out of Scope
- Geocoding/maps APIs, distance matrix, route optimization; distance-tier coverage (`km_min/km_max`) until a geocoding source exists.
- Driver panel `/delivery/`, auto-assignment with offers/timeouts/capacity, driver-side states (`searching/offered/waiting_pickup/on_route/arrived/reassignment_required`).
- Real-time tracking, third-party delivery integrations, notifications/OpenWA (Phase 11), failed-delivery re-fee policy, fulfillment mode switching, force-complete-without-PIN, driver payout settlement reports.

## Capabilities

### New Capabilities
- `delivery`: zones/coverage, rates, persons, assignment, delivery state machine, PIN confirmation.

### Modified Capabilities
- `cart-api`: `setCheckoutData` resolves zone coverage + fee; preview/confirm quote carries cart-resolved fee; typed coverage error.
- `orders`: order creation snapshots payout + zone name; confirm uses cart-stored delivery fee.
- `operations`: board/detail delivery actions; order cancellation cancels the delivery.
- `schema-baseline`: migration 009 tables/columns/permission.

## Approach

Text-match zones are the V1 stand-in for the spec's distance tiers (geocoding input is unspecified and non-goal). Customer fee and driver payout are stored independently per zone (spec sec 16 economy). PIN is HMAC-derived per delivery (recomputable for display on the token-gated page, stored hashed) — resolves the spec's "hashed storage" vs "shown in secure tracking" tension. No order state machine extension: spec sec 11 fixes the 9 order states; `delivered` maps to order `ready→completed`, so `OrdersSchemaTest` transition pins stay intact.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `api/database/migrations/009_create_delivery.sql.php` | New | Tables, cart/order columns, permission seed |
| `api/app/Delivery/*` | New | Repository, DeliveryService, zone matcher, PIN |
| `api/app/Cart/CartService.php`, `CartRepository.php` | Modified | Zone resolution, fee wiring, cart state |
| `api/app/Orders/OrderService.php`, `OrderRepository.php` | Modified | Server-forced fee, payout/zone snapshot |
| `api/app/Orders/OrderOperationsService.php` | Modified | Cancel cascades to delivery |
| `api/app/Admin/OperationsAdminController.php`, `OrdersAdminController.php`, `AdminController.php`, templates | Modified | Delivery actions, `/admin/delivery` CRUD |
| `api/app/Orders/templates/order_confirmation.php`, `OrderPageController.php` | Modified | PIN display when assigned |
| `tests/*` | Modified | Fee-source updates (OrderCreationTest), new delivery tests |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Confirm fee-source change breaks existing delivery test fixtures | High | Update `OrderCreationTest` accepted-totals helper to the cart-resolved path; pickup carts unaffected (fee 0) |
| Term overlap causes wrong-zone match | Medium | First active zone by id wins; document term conventions in admin UI |
| Board template regressions | Medium | Additive actions only; rerun AdminOperationsHttpTest |
| PIN key provisioning on existing installs | Low | Migration 009 seeds per-business HMAC key `WHERE NOT EXISTS` |

## Rollback Plan

Revert code commits; migration rollback = `DROP TABLE deliveries, delivery_person_branches, delivery_persons, delivery_zones` + drop added carts/orders columns + delete `deliveries.manage` permission (pre-launch feature, no production data to preserve).

## Dependencies

- Phases 1–9 complete (113/113 tests green); MariaDB scratch DB harness per existing test patterns.

## Success Criteria

- [ ] Delivery checkout requires a matching zone; fee/payout persist on cart and order snapshots.
- [ ] Pickup flows, PRICE_CHANGED, and idempotent confirm behavior unchanged; full suite green (serial, scratch DBs).
- [ ] Board: assign/reassign/pickup/deliver-with-PIN/fail work with branch scoping + permissions + audit.
- [ ] Wrong PIN after 5 attempts blocks delivery; PIN consumed after success.
- [ ] Public token page shows PIN only while delivery is active.
