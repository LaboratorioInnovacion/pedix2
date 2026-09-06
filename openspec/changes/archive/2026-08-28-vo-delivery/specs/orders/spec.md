# Delta for Orders

## MODIFIED Requirements

### Requirement R1: Atomic order creation

The system MUST create orders from cart confirmation in one transaction: lock cart, recompute quote validating accepted totals against a server-forced delivery fee (the cart-stored `delivery_fee_cents`; any client-passed delivery fee MUST be ignored), validate totals/stock/customer data, write order snapshots including `delivery_zone_name` and `delivery_payout_cents` from the resolved zone, create one `pending` delivery row for delivery-fulfillment orders, apply usage side effects, reserve stock, clear cart items, and persist idempotency.
(Previously: the confirm quote honored the client-supplied `delivery_fee_cents`, and no delivery row or payout/zone snapshot was created.)

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

## ADDED Requirements

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
