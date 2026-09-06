# Delivery Specification

## Purpose

Defines Phase 10 delivery: branch coverage zones with text matching, zone-priced customer fee and driver payout, delivery person registry, per-order delivery state machine with manual assignment, PIN-confirmed completion, failure/cancellation semantics, and public PIN display.

## Requirements

### Requirement D1: Delivery zones CRUD

The system MUST let users with `deliveries.manage` create, update, activate/deactivate, and delete `delivery_zones` scoped to a branch, each with a name, comma/slash-separated `match_terms`, integer `customer_rate_cents`, and independent integer `driver_payout_cents`.

#### Scenario: Zone created for a branch

- GIVEN an admin with `deliveries.manage`
- WHEN a zone is created with terms `"palermo, colegiales"` and rates 300/150
- THEN the zone persists scoped to the chosen branch, active by default.

#### Scenario: Delete is blocked when zone referenced

- GIVEN a zone referenced by a cart or order
- WHEN deletion is attempted
- THEN the system rejects or nulls references per FK policy without corrupting carts/orders.

### Requirement D2: Coverage resolution at checkout-data

For delivery fulfillment, the system MUST resolve coverage in `CartService::setCheckoutData`: normalize address street+city (lowercase, trim, strip accents), split the zone's terms on comma/slash, and substring-match against the normalized address; the first active zone of the cart's branch ordered by id ascending MUST win.

#### Scenario: Address matches zone terms

- GIVEN branch 1 has active zone with terms `"main, caba"`
- WHEN checkout-data sets delivery with street `"Main 123"`, city `"CABA"`
- THEN the zone, its customer rate, and its payout persist on the cart.

#### Scenario: No matching zone

- GIVEN no active zone terms match the address
- WHEN delivery checkout-data is saved
- THEN a typed 422 `delivery_unavailable` error with a Spanish message is returned and no zone/fee is persisted.

#### Scenario: First zone by id wins on overlapping terms

- GIVEN two active zones whose terms both match the address
- WHEN coverage is resolved
- THEN the zone with the lowest id is used.

### Requirement D3: Independent fee and payout rates

Each zone MUST price the customer fee (`customer_rate_cents`) and the driver payout (`driver_payout_cents`) independently; the payout MUST NOT be derived from the fee. Free shipping MUST be expressible as fee 0 with payout > 0.

#### Scenario: Free shipping zone

- GIVEN a zone with `customer_rate_cents` 0 and `driver_payout_cents` 500
- WHEN a delivery order is quoted
- THEN the customer pays 0 delivery while the order snapshots a 500 payout.

### Requirement D4: Delivery persons registry

The system MUST let users with `deliveries.manage` manage active/inactive delivery persons per business, each assignable to one or more branches through a unique person/branch pivot.

#### Scenario: Person serves two branches

- GIVEN a person linked to branches 1 and 2
- WHEN listing assignable persons for branch 1
- THEN the person appears; for branch 3 they do not.

### Requirement D5: Delivery lifecycle

Every delivery-fulfillment order MUST get one `pending` delivery row (order one-to-one) at creation. Legal transitions: `pending→assigned`, `assigned→picked_up` (or `assigned→assigned` on reassignment), `picked_up→delivered`; `failed` (mandatory reason) from `assigned`/`picked_up`; `cancelled` from `pending`/`assigned`/`picked_up`. `delivered`, `failed`, `cancelled` are terminal.

#### Scenario: One delivery per order

- GIVEN a delivery order is confirmed
- THEN exactly one `pending` delivery row exists for that order.

#### Scenario: Illegal transition rejected

- GIVEN a delivery is `pending`
- WHEN pickup or deliver is attempted
- THEN the transition is rejected without side effects.

### Requirement D6: Assignment and reassignment

`assign` and `reassign` MUST require `deliveries.assign` and `deliveries.reassign` respectively (pickup requires `deliveries.assign`), be limited to orders from `accepted` onward, be scoped to the operator's branches, and pick only active persons linked to the order's branch. Reassignment MUST be blocked once the delivery is `picked_up` or beyond.

#### Scenario: Assign from board

- GIVEN an `accepted` delivery order and a person linked to its branch
- WHEN an operator with `deliveries.assign` assigns
- THEN the delivery becomes `assigned` with `assigned_at` set and an audit entry written.

#### Scenario: Branch-foreign person rejected

- GIVEN a person not linked to the order's branch
- WHEN assignment is attempted
- THEN the system rejects it.

### Requirement D7: PIN-confirmed delivery

`delivered` MUST require the order to be `ready` and a PIN match. The PIN is a deterministic 4–6 digit code derived per delivery from a per-business HMAC key, recomputable for redisplay, stored only hashed, verified in constant time, with at most 5 failed attempts; exhausting attempts MUST fail the delivery with reason `pin_exhausted`. On success the PIN is consumed and the order transitions `ready→completed`.

#### Scenario: Correct PIN completes order

- GIVEN a `picked_up` delivery whose order is `ready`
- WHEN the operator submits the correct PIN
- THEN the delivery is `delivered`, the PIN is consumed, and the order is `completed`.

#### Scenario: Order not ready blocks delivery

- GIVEN the order is `accepted` or `in_progress`
- WHEN deliver is attempted
- THEN a typed error is returned and delivery state is unchanged.

#### Scenario: Attempts exhausted fails the delivery

- GIVEN 5 wrong PIN submissions on an active delivery
- WHEN a 6th submission arrives
- THEN the delivery is `failed` with reason `pin_exhausted`.

### Requirement D8: Failure and cancellation

`fail` MUST require a non-empty reason and `deliveries.assign`. `cancel` MUST require `deliveries.manage` when issued directly; additionally, cancelling the order MUST cascade `cancelled` to any active delivery without requiring a separate permission.

#### Scenario: Fail without reason rejected

- GIVEN an `assigned` delivery
- WHEN fail is submitted with a blank reason
- THEN the request is rejected and state is unchanged.

#### Scenario: Order cancellation cascades

- GIVEN an `assigned` delivery
- WHEN its order is cancelled through the operations gateway
- THEN the delivery becomes `cancelled` and the cancellation is audited.

### Requirement D9: Public PIN display

The public token order page MUST show the delivery state and the recomputed PIN only while the delivery is `assigned` or `picked_up`; no PIN may be shown for `pending`, `delivered`, `failed`, or `cancelled` deliveries or for pickup orders.

#### Scenario: PIN visible while active

- GIVEN an assigned delivery
- WHEN the customer opens `/pedido/{token}`
- THEN the page shows the current PIN and delivery state.

#### Scenario: PIN hidden after consumption

- GIVEN a `delivered` delivery
- WHEN the page is opened
- THEN no PIN is displayed.
