# Delta for Cart API

## MODIFIED Requirements

### Requirement R5: Live pricing and unavailable flags

The system MUST recompute totals through `PricingService::quote()` on read and preview, pass coupon/payment/fulfillment/delivery/accepted totals, and flag inactive/unavailable lines instead of silently deleting them. For delivery carts, the quote MUST use the cart-resolved `delivery_fee_cents` persisted at checkout-data — never a client-supplied value.
(Previously: the cart quote hardcoded `delivery_fee_cents` to 0, so delivery totals ignored zone pricing.)

#### Scenario: Changed price requires new acceptance

- GIVEN the visitor accepted previous totals
- WHEN `POST /api/cart/preview` receives those accepted totals after a price change
- THEN the response includes `price_changed=true` and fresh totals
- AND no order is created.

#### Scenario: Preview carries resolved delivery fee

- GIVEN a delivery cart matched to a zone with `customer_rate_cents` 300
- WHEN the cart is read or previewed
- THEN the quote's `delivery_fee_cents` and grand total include 300.

### Requirement R6: Coupon and checkout data

The system MUST support `POST /api/cart/coupon` and `POST /api/cart/checkout-data` for coupon code, payment method, fulfillment, address JSON, and `customer_note` up to 500 characters. For delivery fulfillment, checkout-data MUST resolve branch coverage per the delivery capability (normalized text match, first active zone by id), persisting the matched `delivery_zone_id`, `customer_rate_cents` as `delivery_fee_cents`, and `driver_payout_cents` on the cart. When no active zone matches (or delivery is requested without a matchable address), the API MUST return a typed 422 `delivery_unavailable` error with a Spanish message and persist no zone/fee state. Pickup checkout-data MUST NOT resolve or alter delivery zone state.
(Previously: checkout-data stored address/note only; delivery fees were not resolved server-side and no coverage validation existed.)

#### Scenario: Checkout data updates preview inputs

- GIVEN a cart has items
- WHEN checkout data and a coupon are saved
- THEN later cart and preview responses use those values in the live quote.

#### Scenario: Delivery checkout-data resolves and persists zone pricing

- GIVEN a product cart and an active zone whose terms match the address
- WHEN delivery checkout-data is saved
- THEN the cart row stores the zone id, fee, and payout
- AND subsequent previews quote with the resolved fee.

#### Scenario: Delivery without coverage is typed-rejected

- GIVEN no active zone terms match the submitted address
- WHEN delivery checkout-data is saved
- THEN the response is 422 with error code `delivery_unavailable` and a Spanish message
- AND the cart keeps its previous checkout state.
