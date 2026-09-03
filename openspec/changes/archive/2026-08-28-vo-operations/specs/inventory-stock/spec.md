# Delta for Inventory Stock

## ADDED Requirements

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
