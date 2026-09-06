# Delta for Cart API

## MODIFIED Requirements

### Requirement R7: Confirmation creates a real order

`POST /api/cart/confirm` MUST require an idempotency key and accepted totals, recompute the complete cart through backend pricing, create a real order on unchanged totals, and clear cart items after a successful transaction. `POST /api/cart/preview` MUST remain preview-only and MUST NOT create orders, payments, stock reservations, delivery rows, or historical snapshots.
(Previously: confirmation behavior lived under `POST /api/cart/preview` and only returned a preview-ready flag.)

#### Scenario: Preview remains side-effect free
- GIVEN a valid cart with accepted totals
- WHEN preview is requested
- THEN a final quote is returned
- AND order/payment/stock tables are unchanged.

#### Scenario: Confirm creates order response
- GIVEN a valid cart, idempotency key, and accepted totals
- WHEN `POST /api/cart/confirm` is called
- THEN the response includes order id, order number, public token/link, status, and totals
- AND cart items are cleared.

#### Scenario: Confirm requires idempotency key
- GIVEN a valid cart and accepted totals
- WHEN confirmation omits the idempotency key
- THEN the API rejects the request
- AND no order is created.

#### Scenario: Stale accepted totals
- GIVEN cart pricing changed after preview
- WHEN `POST /api/cart/confirm` submits stale accepted totals
- THEN the API returns a price-changed response with fresh totals
- AND cart items remain available for review.
