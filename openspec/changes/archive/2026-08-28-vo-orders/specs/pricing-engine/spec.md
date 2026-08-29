# Delta for Pricing Engine

## MODIFIED Requirements

### Requirement: Scope guards
Pricing engine V1 MUST NOT create carts, orders, stock reservations, delivery rates, or payment transactions while calculating a quote. Order creation MAY persist order snapshot rows, order discount rows, promotion/coupon usage rows, and stock reservations only after the quote is accepted by `OrderService`.
(Previously: any quote completion had no order side effects and order creation did not exist.)

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
