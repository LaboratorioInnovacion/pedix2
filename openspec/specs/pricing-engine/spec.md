# Pricing Engine Specification

## Purpose

Defines the backend pricing engine as the single source of truth for quote calculation: effective price resolution through the catalog chain, automatic promotion/coupon/payment-discount ordering, minimum order checks, the delivery fee parameter, and `PRICE_CHANGED` detection. Synced from change `vo-pricing` (Phase 5).

## Requirements

### Requirement: Effective price resolution
The pricing engine MUST resolve effective prices with the catalog chain: branch variant, variant, branch item, base item. Scheduled promo prices MUST override the resolved item/variant price before other promotions.

#### Scenario: Branch variant wins before scheduled price
- GIVEN an active line has all catalog price levels and one valid scheduled price promotion
- WHEN the quote is calculated
- THEN the gross line price uses the scheduled promotional price instead of the branch variant price

### Requirement: Automatic promotion order and stackability
The engine MUST evaluate automatic promotions by ascending priority: product, category, minimum-amount/order, then payment-method later as its own stage. For item/order automatic promotions, non-stackable wins MUST block later promotions for the same scope; stackable promotions MAY combine.

#### Scenario: Non-stackable scope block
- GIVEN two applicable product promotions for the same item and the first by priority is non-stackable
- WHEN the quote is calculated
- THEN only the first product promotion applies to that item

### Requirement: Coupon validation and ordering
Coupon discounts MUST run after automatic item/order promotions and MUST require active validity, unarchived status, global usage limit availability, and minimum amount based on merchandise after automatic promotions.

#### Scenario: Coupon rejected by minimum
- GIVEN a valid coupon with a minimum amount above the post-automatic-promotion merchandise subtotal
- WHEN the quote is calculated with that coupon code
- THEN the coupon is rejected and coupon_discount is 0

### Requirement: Payment-method discount
Payment-method discounts MUST apply after coupon as a percentage of the merchandise subtotal remaining after automatic promotions and coupon. Payment discounts MUST NOT include delivery.

#### Scenario: Payment discount base excludes delivery
- GIVEN merchandise after coupon is 10000 cents and delivery fee is 1500 cents
- WHEN a 10% payment discount applies
- THEN payment_discount is 1000 cents and delivery remains 1500 cents

### Requirement: Exact formula and delivery parameter
The engine MUST compute `GROSS_ITEMS - ITEM_PROMOTIONS - ORDER_PROMOTIONS - COUPON_DISCOUNT - PAYMENT_DISCOUNT = MERCHANDISE_TOTAL` and `MERCHANDISE_TOTAL + CUSTOMER_DELIVERY_FEE = GRAND_TOTAL`. Delivery fee MUST default to 0 when omitted.

#### Scenario: Formula totals are explicit
- GIVEN gross items 10000, item promotions 1000, order promotions 500, coupon 1000, payment 750, and delivery 1200
- WHEN the quote is calculated
- THEN merchandise_total is 6750 and grand_total is 7950

### Requirement: Minimum order check
The engine MUST check minimum order before discounts, excluding delivery, with separate pickup and delivery thresholds.

#### Scenario: Delivery minimum fails before discounts
- GIVEN gross items are 4000 cents, delivery minimum is 5000 cents, and pickup minimum is 3000 cents
- WHEN fulfillment is delivery
- THEN minimum status is not met even if discounts would not change eligibility

### Requirement: PRICE_CHANGED handling
When accepted totals are provided, the engine MUST compare accepted grand total and accepted line totals against recomputation. Any difference MUST return `PRICE_CHANGED` details plus a fresh preview and MUST NOT silently confirm.

#### Scenario: Recomputed total differs
- GIVEN accepted line totals or grand total differ from the current quote
- WHEN the quote is recalculated for confirmation
- THEN price_changed is true and the result includes the new preview totals

### Requirement: Integer money and deterministic rounding
All money MUST be integer cents. Percentage calculations MUST use `Money::pct()` half-even rounding and MUST NOT use floats.

#### Scenario: Half-even percentage rounding
- GIVEN a percentage produces a half-cent tie
- WHEN the discount is calculated
- THEN the rounded cents follow half-even behavior

### Requirement: Scope guards
Pricing engine V1 MUST NOT create carts, orders, stock reservations, delivery rates, or payment transactions while calculating a quote. Order creation MAY persist order snapshot rows, order discount rows, promotion/coupon usage rows, and stock reservations only after the quote is accepted by `OrderService`.

#### Scenario: Quote has no order side effects
- GIVEN a quote request is calculated
- WHEN it completes successfully
- THEN no order, stock, delivery-rate, or payment-processing rows are written.

#### Scenario: Confirmed order records discount usage
- GIVEN a quote contains promotion and coupon discounts
- WHEN an unchanged accepted cart is confirmed as an order
- THEN order discount rows and promotion usage rows are recorded
- AND promotion/coupon `times_used` increments only once for the confirmed order.

#### Scenario: Price-changed confirmation has no usage
- GIVEN a confirmation returns price_changed
- WHEN the response is sent
- THEN no order discounts, promotion usage rows, or `times_used` increments are written.
