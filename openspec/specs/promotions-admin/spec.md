# Promotions Admin Specification

## Purpose

Defines authenticated Spanish admin behavior for promotion and coupon management: guard/CSRF/permission boundaries, promotion types including scheduled prices, coupon management, audit with request_id, archive-not-delete, and V1 scope guards. Synced from change `vo-pricing` (Phase 5).

## Requirements

### Requirement: Promotion CRUD guard and CSRF
The admin MUST provide Spanish promotion CRUD pages guarded by authenticated admin session, CSRF, and the existing `products.manage` permission. V1 MUST reuse `products.manage`; it MUST NOT introduce `promotions.manage`.

#### Scenario: User without products.manage is denied
- GIVEN an authenticated user lacks `products.manage`
- WHEN they access `/admin/promociones`
- THEN the response is 403 and an authorization denial is audited

### Requirement: Promotions archive, not delete
Promotions MUST be created, updated, listed, and archived by setting `archived_at`; normal admin operations MUST NOT physically delete promotions.

#### Scenario: Archive keeps row
- GIVEN an existing active promotion
- WHEN an authorized admin archives it with valid CSRF
- THEN `archived_at` is set and the row remains queryable for audit/history

### Requirement: Promotion types and scheduled prices
The admin MUST manage promotion types for product percentage, product fixed, category percentage, minimum-amount percentage, payment-method percentage, and scheduled price. Scheduled promo price MUST live in the promotions table as type `scheduled_price` targeting an item with a price override; it MUST NOT be edited as an item field.

#### Scenario: Scheduled price targets an item
- GIVEN an admin creates a scheduled price promotion for an item
- WHEN the form is submitted
- THEN a promotion and item-scope rule are stored with override price cents

### Requirement: Coupon management
The admin MUST create, update, list, archive/deactivate simple percent coupons with code uniqueness, validity window, optional usage limit, optional minimum amount, and archived status.

#### Scenario: Coupon usage limit is stored
- GIVEN an admin submits a coupon with usage limit and validity dates
- WHEN the coupon is saved
- THEN the coupon stores the limit, current usage count, and validity window

### Requirement: Audit request_id
Promotion and coupon create, update, and archive actions MUST write audit rows with request_id using actions `pricing.promotion_created`, `pricing.promotion_updated`, `pricing.promotion_archived`, `pricing.coupon_created`, `pricing.coupon_updated`, and `pricing.coupon_archived`.

#### Scenario: Promotion update is audited
- GIVEN an authorized admin updates a promotion
- WHEN the request succeeds
- THEN an audit row exists with action `pricing.promotion_updated`, entity id, metadata, and non-null request_id

### Requirement: Admin scope guards
Promotions admin V1 MUST NOT manage carts, orders, stock, delivery rates, payment processing, 2x1/3x2, or complex incompatibility lists.

#### Scenario: No forbidden controls
- GIVEN an admin views promotion forms
- WHEN the page renders
- THEN it contains no cart, order, stock, delivery-rate, payment-processing, 2x1, or 3x2 controls
