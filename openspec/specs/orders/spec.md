# Orders Specification

## Purpose

Defines Phase 7 order creation, immutable snapshots, numbering, states, and read-only order visibility.

## Requirements

### Requirement R1: Atomic order creation
The system MUST create orders from cart confirmation in one transaction: lock cart, recompute quote, validate totals/stock/customer data, write order snapshots, apply usage side effects, reserve stock, clear cart items, and persist idempotency.

#### Scenario: Valid cart creates one pending order
- GIVEN a cart with unchanged accepted totals
- WHEN checkout is confirmed
- THEN one `pending` order is created with items, address, discounts, customer snapshot, and totals
- AND cart items are cleared while the cart row is retained.

#### Scenario: Any transaction step fails
- GIVEN any required write or validation fails during confirmation
- WHEN the transaction rolls back
- THEN no partial order, stock, usage, or cart clearing side effect remains.

### Requirement R2: PRICE_CHANGED rejection
The system MUST reject confirmation when recomputed accepted grand total or line totals differ, return fresh pricing data, and create no order.

#### Scenario: Accepted totals are stale
- GIVEN accepted totals no longer match live pricing
- WHEN checkout is confirmed
- THEN the response is a typed price-changed error with current quote
- AND no order number is consumed.

### Requirement R3: Idempotent confirmation
The system MUST require an idempotency key for order creation and MUST return the original order response for exact replays of the same key.

#### Scenario: Double submit replays original order
- GIVEN an order was created for an idempotency key
- WHEN the same confirmation is retried
- THEN the API returns the original order response without creating a duplicate.

### Requirement R4: Per-business order numbering
The system MUST assign a monotonically increasing per-business order number formatted like `B-000123` and enforce uniqueness per business.

#### Scenario: Two branches share a business counter
- GIVEN two carts for branches in the same business
- WHEN both are confirmed
- THEN their numbers advance on the same business sequence.

### Requirement R5: Order state machine
The system MUST define states `pending`, `change_proposed`, `accepted`, `in_progress`, `ready`, `completed`, `rejected`, `cancelled`, `expired`; legal transitions are pending→change_proposed|accepted|rejected|cancelled|expired, change_proposed→accepted|rejected|cancelled|expired, accepted→in_progress|cancelled, in_progress→ready|cancelled, ready→completed|cancelled; terminal states have no outgoing transitions.

#### Scenario: Illegal transition rejected
- GIVEN an order is `completed`
- WHEN a transition to `accepted` is requested
- THEN the state machine rejects it.

### Requirement R6: Immutable order snapshots
The system MUST snapshot customer contact, address, item/variant/modifier names, unit prices, discounts, delivery fee, notes, and totals exactly from the accepted quote and checkout data.

#### Scenario: Catalog changes after confirmation
- GIVEN an order was confirmed
- WHEN catalog names or prices change later
- THEN order detail and public confirmation still show the original snapshot values.

### Requirement R7: Public order page
The system MUST expose a no-auth read-only order confirmation page by unguessable public token and MUST NOT expose orders by sequential number alone.

#### Scenario: Token lookup succeeds
- GIVEN a valid public token
- WHEN `/pedido/{public_token}` is opened
- THEN the page renders the order snapshot only.

### Requirement R8: Admin read-only order visibility
Authenticated admin users with existing product management permission MUST read order lists and details filtered by business/branch scope without mutating state.

#### Scenario: Scoped list
- GIVEN an admin is scoped to one branch
- WHEN they open the orders list
- THEN only orders for authorized branches are returned.

### Requirement R9: Guest-first customer link
Orders MUST keep contact snapshots for guest checkout and MAY link to a customer account when one exists or is created during checkout.

#### Scenario: Guest order without account
- GIVEN checkout includes guest contact data only
- WHEN the order is created
- THEN `orders.customer_id` may be null while contact snapshot fields are present.
