# Inventory Stock Specification

## Purpose

Defines branch-level stock persistence, reservation, movement history, and oversell prevention for Phase 7 orders.

## Requirements

### Requirement R1: Branch item stock persistence
The system MUST persist stock mode, physical quantity, and reserved quantity per `branch_items` row; `simple` stock uses quantities, while `none` and `unlimited` skip reservation blocking.

#### Scenario: Simple stock availability
- GIVEN a branch item has physical stock 10 and reserved stock 3
- WHEN availability is checked
- THEN available stock is 7.

### Requirement R2: Reserve stock at order creation
The system MUST reserve simple stock during order creation before the cart is cleared and MUST associate each reservation movement with the created order.

#### Scenario: Reservation succeeds
- GIVEN simple stock has enough available quantity
- WHEN an order is confirmed
- THEN reserved quantity increases by ordered quantity
- AND a stock movement with reason `reserve` is recorded.

### Requirement R3: Prevent overselling
The system MUST lock affected `branch_items` rows and reject confirmation if any simple-stock line lacks enough available quantity.

#### Scenario: Not enough stock
- GIVEN available simple stock is lower than requested quantity
- WHEN checkout is confirmed
- THEN the API rejects the order
- AND no reservation or order row is persisted.

### Requirement R4: Movement log is immutable
The system MUST append stock movements with branch, item, optional variant, order, signed quantity, reason, note, and actor metadata; movements MUST NOT be deleted by normal operation.

#### Scenario: Movement audit trail
- GIVEN stock is reserved for an order
- WHEN stock history is queried
- THEN the `reserve` movement identifies the order and signed quantity.

### Requirement R5: Cancellation release foundation
The system MUST provide a service-level release operation that decreases reserved quantity and appends reason `release_cancelled`, `release_rejected`, or `release_expired` for future order-state flows.

#### Scenario: Cancel release
- GIVEN an order has reserved simple stock
- WHEN cancellation release is invoked once
- THEN reserved quantity decreases once
- AND a release movement is appended.

### Requirement R6: Consume stock on order acceptance
The system MUST provide a guarded consume operation that decreases physical and reserved quantity of simple stock by the ordered quantity and appends movements with reason `consume_accepted`; the `stock_movements.reason` values MUST include `consume_accepted`. The operation MUST be idempotent per line: when reserved quantity is lower than the requested quantity it MUST do nothing. Existing release operations (`release_cancelled`, `release_rejected`, `release_expired`) remain the only release reasons and are consumed by the order cancel/reject/expire flows defined in the operations capability.

#### Scenario: Consume decrements physical and reserved stock
- GIVEN an order reserved 3 units of a simple item (stock 10, reserved 3)
- WHEN consume runs for that order
- THEN stock is 7 and reserved is 0
- AND a `consume_accepted` movement with delta -3 is recorded.

#### Scenario: Double consume is a no-op
- GIVEN the same order's stock was already consumed
- WHEN consume runs again
- THEN quantities are unchanged and no new movement is appended.

#### Scenario: Movement reason accepts consume and still rejects unknown values
- GIVEN the migration-extended `stock_movements` table
- WHEN a movement is inserted with reason `consume_accepted`
- THEN the insert succeeds
- AND inserting reason `manual` still fails the reason constraint.
