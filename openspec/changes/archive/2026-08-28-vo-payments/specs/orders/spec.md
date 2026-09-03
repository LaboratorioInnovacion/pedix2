# Delta for Orders

## MODIFIED Requirements

### Requirement R5: Order state machine
The system MUST define states `pending`, `change_proposed`, `accepted`, `in_progress`, `ready`, `completed`, `rejected`, `cancelled`, `expired`; legal transitions are pending→change_proposed|accepted|rejected|cancelled|expired, change_proposed→accepted|rejected|cancelled|expired, accepted→in_progress|cancelled, in_progress→ready|cancelled, ready→completed|cancelled; terminal states have no outgoing transitions. The system MUST auto-transition a `pending` order to `accepted` when its linked transfer payment becomes `verified` or its linked Mercado Pago payment becomes `approved`. Rejected or cancelled payments MUST NOT reject, cancel, or expire the order automatically; the order remains operationally `pending` with the rejected/cancelled payment visible.
(Previously: order state transitions were independent of payment state.)

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

## ADDED Requirements

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
