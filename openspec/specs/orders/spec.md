# Orders Specification

## Purpose

Defines Phase 7 order creation, immutable snapshots, numbering, states, and read-only order visibility.

## Requirements

### Requirement R1: Atomic order creation
The system MUST create orders from cart confirmation in one transaction: lock cart, recompute quote validating accepted totals against a server-forced delivery fee (the cart-stored `delivery_fee_cents`; any client-passed delivery fee MUST be ignored), validate totals/stock/customer data, write order snapshots including `delivery_zone_name` and `delivery_payout_cents` from the resolved zone, create one `pending` delivery row for delivery-fulfillment orders, apply usage side effects, reserve stock, clear cart items, and persist idempotency.

#### Scenario: Valid cart creates one pending order
- GIVEN a cart with unchanged accepted totals
- WHEN checkout is confirmed
- THEN one `pending` order is created with items, address, discounts, customer snapshot, and totals
- AND cart items are cleared while the cart row is retained.

#### Scenario: Delivery order snapshots zone and payout
- GIVEN a delivery cart matched to a zone
- WHEN checkout is confirmed
- THEN the order stores `delivery_zone_name` and `delivery_payout_cents` from the cart-resolved zone
- AND exactly one `pending` delivery row exists for the order.

#### Scenario: Client-supplied delivery fee is ignored
- GIVEN a confirmation request whose accepted totals embed a delivery fee differing from the cart-resolved fee
- WHEN checkout is confirmed
- THEN the quote is computed with the cart-stored fee
- AND a mismatching accepted grand total is rejected as `PRICE_CHANGED`.

#### Scenario: Any transaction step fails
- GIVEN any required write or validation fails during confirmation
- WHEN the transaction rolls back
- THEN no partial order, stock, usage, delivery row, or cart clearing side effect remains.

### Requirement: Order state machine unchanged by delivery
The order state machine MUST remain exactly the nine states and legal transitions already defined (R5). Delivery MUST NOT add, remove, or rename order states or transitions; delivery completion maps onto the existing `ready→completed` transition, and delivery lifecycle state lives exclusively on the `deliveries` table.

#### Scenario: Delivery deliver requires ready
- GIVEN a delivery whose order is not `ready`
- WHEN delivery completion is attempted
- THEN the delivery is not marked `delivered` and the order status is unchanged.

#### Scenario: Existing transition pins hold
- GIVEN the order state map
- WHEN delivery completes a `ready` order
- THEN the transition used is the existing `ready→completed` edge.

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
The system MUST define states `pending`, `change_proposed`, `accepted`, `in_progress`, `ready`, `completed`, `rejected`, `cancelled`, `expired`; legal transitions are pending→change_proposed|accepted|rejected|cancelled|expired, change_proposed→accepted|rejected|cancelled|expired, accepted→in_progress|cancelled, in_progress→ready|cancelled, ready→completed|cancelled; terminal states have no outgoing transitions. The system MUST auto-transition a `pending` order to `accepted` when its linked transfer payment becomes `verified` or its linked Mercado Pago payment becomes `approved`. Rejected or cancelled payments MUST NOT reject, cancel, or expire the order automatically; the order remains operationally `pending` with the rejected/cancelled payment visible.

#### Scenario: Illegal transition rejected
- GIVEN an order is `completed`
- WHEN a transition to `accepted` is requested
- THEN the state machine rejects it.

#### Scenario: Verified payment accepts pending order
- GIVEN an order is `pending`
- WHEN its transfer payment becomes `verified`
- THEN the order transitions to `accepted` through the normal order state map.

#### Scenario: Rejected payment keeps order pending
- GIVEN an order is `pending`
- WHEN its payment becomes `rejected` or `cancelled`
- THEN the order remains `pending`
- AND payment state is visible to public/admin readers.

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

### Requirement R10: Public order payment visibility
The public order page MUST show the current payment state and method-specific next action for non-cash orders.

#### Scenario: Transfer action visible
- GIVEN a public token for a transfer order
- WHEN the order page is opened
- THEN transfer instructions, state badge, and proof upload form are shown.

#### Scenario: Mercado Pago action visible
- GIVEN a public token for an MP order with a preference
- WHEN the order page is opened
- THEN the payment state and MP payment button are shown.
