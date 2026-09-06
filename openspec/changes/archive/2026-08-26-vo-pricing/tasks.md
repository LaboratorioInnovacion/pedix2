# Tasks: VO Pricing

## Review Workload Forecast

| Field | Value |
|---|---|
| Estimated changed lines | Unit A ~630, Unit B ~570; total ~1200 |
| 400-line budget risk | High |
| Chained PRs recommended | N/A local mode |
| Suggested split | Unit A pricing engine -> Unit B admin management |
| Delivery strategy | auto / both / NO-COMMIT MODE |
| Chain strategy | N/A local mode |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Focused test command | Runtime harness | Rollback boundary |
|---|---|---|---|---|
| A | Pricing schema + engine | `D:\xampp\php\php.exe tools/run-tests.php --filter PricingEngineTest` | Scratch MariaDB quote fixtures | Remove migration 004, `api/app/Pricing/*`, `tests/PricingEngineTest.php` |
| B | Promotions/coupons admin | `D:\xampp\php\php.exe tools/run-tests.php --filter AdminPromotionsHttpTest` | PHP built-in server via HTTP test harness | Remove admin controller/templates/delegation/test |

## Unit A: Pricing Engine (RED-first)

- [x] A.1 Create `tests/PricingEngineTest.php` RED cases for formula stages, resolver chain, scheduled price, stackability, coupons, payment discount, minimums, `PRICE_CHANGED`, and half-even rounding.
- [x] A.2 Create `api/database/migrations/004_create_pricing_promotions.sql.php` with promotions, promotion_rules, and coupons; verify with focused pricing test.
- [x] A.3 Add shared catalog price resolver by extracting/reusing `CatalogService::resolveDisplayPrice()`; acceptance: branch variant -> variant -> branch item -> base remains identical.
- [x] A.4 Create `api/app/Pricing/PricingRepository.php` for active promotion/coupon and catalog queries using PDO prepared statements.
- [x] A.5 Create `api/app/Pricing/PricingService.php` with quote request/result arrays, ordered discounts, integer cents, Money half-even percentages, and delivery default 0.
- [x] A.6 Implement `PRICE_CHANGED` accepted-total comparison for grand total and line totals; acceptance: both higher and lower recomputes return fresh preview.
- [x] A.7 Verify Unit A: `D:\xampp\php\php.exe tools/run-tests.php --filter PricingEngineTest`.

## Unit B: Promotions Admin

- [x] B.1 Create `tests/AdminPromotionsHttpTest.php` RED cases for unauthenticated/authz denial, CSRF, promotion CRUD, coupon CRUD, archive-not-delete, and request_id audit rows.
- [x] B.2 Create `api/app/Admin/PromotionsAdminController.php` guarded by session, CSRF, and `products.manage`; use actions `pricing.promotion_*` and `pricing.coupon_*`.
- [x] B.3 Add Spanish templates `api/app/Admin/templates/promotions_list.php`, `promotions_form.php`, `coupons_list.php`, `coupons_form.php`, and reuse `catalog_error.php` or a pricing error view.
- [x] B.4 Modify `api/app/Admin/AdminController.php` to delegate `/admin/promociones*` and `/admin/cupones*` to the new controller.
- [x] B.5 Verify Unit B: `D:\xampp\php\php.exe tools/run-tests.php --filter AdminPromotionsHttpTest`.

## Phase-End Verification

- [x] V.1 Run full suite once after Units A+B: `D:\xampp\php\php.exe tools/run-tests.php`.
- [x] V.2 Confirm no cart, order, stock, delivery-rate, payment-processing, 2x1/3x2, or complex incompatibility-list behavior was added.

## Scope Guard
Do not implement carts, checkout/order creation, stock reservation, delivery-rate calculation, payment gateway processing, public cart preview UI, 2x1/3x2, complex promotion rules, `promotion_usage`, or `order_discounts` in this change.
