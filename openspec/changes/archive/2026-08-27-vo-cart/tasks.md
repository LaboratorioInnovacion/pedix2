# Tasks: Guest Cart and Checkout Preview

## Review Workload Forecast

| Field | Value |
|---|---|
| Estimated changed lines | Unit A ~390; Unit B ~340; total ~730 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | Unit A -> Unit B |
| Delivery strategy | auto-chain / NO-COMMIT MODE |
| Chain strategy | stacked-to-main |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| A | Backend cart schema/service/API | PR 1 | `D:\xampp\php\php.exe tools/run-tests.php --filter CartApi` | Built-in server with `vo_cart6_test_<rand>` | Migration 005 + `api/app/Cart/*` + API routing/tests |
| B | Storefront cart/checkout pages and JS | PR 2 | `D:\xampp\php\php.exe tools/run-tests.php --filter PublicCart` | Browser/HTTP probe: `/carrito`, `/checkout`, cart fetch flow | templates + `public_html` route + `assets/js/shop/cart.js` + page tests |

## Unit A: Backend Cart API

- [x] A.1 Create `api/database/migrations/005_create_carts.sql.php` with `carts`, `cart_items`, `cart_item_modifiers`, indexes, checks, and cascading deletes. Covers R1-R2.
- [x] A.2 Add `api/app/Cart/CartToken.php` for 32-byte tokens, cookie-safe validation, SHA-256 hash, and two-hour expiry handling. Covers R1.
- [x] A.3 Add `api/app/Cart/CartRepository.php` with prepared CRUD, lazy expiry delete, catalog reads, modifier group reads, and unavailable-line detection. Covers R1-R5.
- [x] A.4 Add `api/app/Cart/CartService.php` implementing add/update/remove/coupon/checkout-data/get-preview plus branch, mixed-cart, and modifier min/max rules. Covers R2-R7.
- [x] A.5 Add `api/app/Cart/CartController.php` JSON handlers for all cart endpoints; issue HttpOnly SameSite cart cookie and return 422 validation errors. Covers R1-R7.
- [x] A.6 Modify `api/app/Http/Router.php` and `api/bootstrap/app.php` for PATCH/DELETE and `/api/cart/*` route registration. Covers R2, R6-R7.
- [x] A.7 Create `tests/CartApiHttpTest.php` for token lifecycle, CRUD, invalid selections, mixed delivery rejection, `price_changed`, and no order/payment/stock side effects. Verifies R1-R7.
- [x] A.8 Verification placeholder: run Unit A focused command and record PASS/FAIL before Unit B.

## Unit B: Storefront Pages and JS

- [x] B.1 Modify `api/app/Catalog/PublicCatalogController.php` with `cart()` and `checkout()` server-rendered page methods. Covers R8-R9.
- [x] B.2 Create `api/app/Catalog/templates/cart.php` and `checkout.php` with escaped Spanish shells, checkout-data form, and preview container. Covers R8.
- [x] B.3 Modify `api/app/Catalog/templates/layout.php` and product template to expose cart affordances without computing totals. Covers R8-R9.
- [x] B.4 Modify `public_html/index.php` to route `GET /carrito` and `GET /checkout`; keep `/health` JSON boundary unchanged. Covers R8.
- [x] B.5 Create `public_html/assets/js/shop/cart.js` for fetch-based add/remove/update/coupon/checkout/preview and toast feedback; backend totals only. Covers R5-R9.
- [x] B.6 Create `tests/PublicCartPagesHttpTest.php` for page rendering, JS inclusion, escaped content, and preview-only storefront flow. Verifies R8-R9.
- [x] B.7 Verification placeholder: run Unit B focused command, then full `D:\xampp\php\php.exe tools/run-tests.php`.

## Contract Check

- Every task maps to spec IDs R1-R9.
- Every design component maps to Unit A or Unit B.
- Apply should start with Unit A, then Unit B.
