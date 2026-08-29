# Cart API Specification

## Purpose

Defines Phase 6-7 guest cart persistence, live pricing, checkout data, real idempotent order confirmation, and side-effect-free preview.

## Requirements

### Requirement R1: Guest cart token and lifecycle

The system MUST identify guest carts with a random bearer cookie token, store only its SHA-256 hash, lazily delete expired carts on access, and default expiry to two hours after mutation.

#### Scenario: Existing cart is reused
- GIVEN a visitor has a valid cart token cookie
- WHEN they call any cart API
- THEN the matching non-expired cart is loaded by token hash
- AND expiry is extended after mutation.

#### Scenario: Expired cart is discarded
- GIVEN a cart token references an expired cart
- WHEN the visitor accesses the cart
- THEN the expired cart and dependent rows are physically deleted
- AND a fresh empty cart can be created.

### Requirement R2: Cart item CRUD

The system MUST allow `POST /api/cart/items`, `PATCH /api/cart/items/{cart_item_id}`, `DELETE /api/cart/items/{cart_item_id}`, and `GET /api/cart` for adding, updating quantity, removing, and reading live cart lines.

#### Scenario: Guest edits persisted lines
- GIVEN a valid public catalog item selection
- WHEN the visitor adds it, changes quantity, and removes it
- THEN cart item rows reflect each action
- AND `GET /api/cart` returns the current live cart.

### Requirement R3: Catalog selection validation

The system MUST reject missing required variants, invalid variants, inactive/archived modifiers, and modifier group selections outside min/max limits.

#### Scenario: Required modifier minimum fails
- GIVEN an item has a required modifier group with minimum 1
- WHEN the visitor adds the item without a modifier from that group
- THEN the API returns a validation error
- AND no invalid cart line is persisted.

### Requirement R4: Branch and fulfillment rules

The system MUST keep every cart bound to one branch once items exist, reject branch changes with existing items, and force mixed product/service carts to pickup only.

#### Scenario: Mixed cart rejects delivery
- GIVEN a cart contains both product and service items
- WHEN checkout data sets fulfillment to delivery
- THEN the API rejects the change
- AND the cart remains pickup for the original branch.

### Requirement R5: Live pricing and unavailable flags

The system MUST recompute totals through `PricingService::quote()` on read and preview, pass coupon/payment/fulfillment/delivery/accepted totals, and flag inactive/unavailable lines instead of silently deleting them.

#### Scenario: Changed price requires new acceptance
- GIVEN the visitor accepted previous totals
- WHEN `POST /api/cart/preview` receives those accepted totals after a price change
- THEN the response includes `price_changed=true` and fresh totals
- AND no order is created.

### Requirement R6: Coupon and checkout data

The system MUST support `POST /api/cart/coupon` and `POST /api/cart/checkout-data` for coupon code, payment method, fulfillment, address JSON, and `customer_note` up to 500 characters.

#### Scenario: Checkout data updates preview inputs
- GIVEN a cart has items
- WHEN checkout data and a coupon are saved
- THEN later cart and preview responses use those values in the live quote.

### Requirement R7: Confirmation creates a real order

`POST /api/cart/confirm` MUST require an idempotency key and accepted totals, recompute the complete cart through backend pricing, create a real order on unchanged totals, and clear cart items after a successful transaction. `POST /api/cart/preview` MUST remain preview-only and MUST NOT create orders, payments, stock reservations, delivery rows, or historical snapshots.

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
