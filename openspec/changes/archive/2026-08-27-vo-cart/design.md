# Design: Guest Cart and Checkout Preview

## Technical Approach

Implement Phase 6 in the existing shared-hosting PHP path: `Request/Router -> CartController -> CartService -> CartRepository -> PDO/MySQL`, with `PricingService::quote()` as the only total calculator. Cart rows keep live catalog IDs only; order snapshots remain out of scope until Phase 7.

## Architecture Decisions

| Decision | Choice | Alternatives considered | Rationale |
|---|---|---|---|
| Guest identity | 32-byte random cookie token; store `SHA-256` in `carts.token_hash` | PHP session ID, raw DB token | Bearer cart secret without server session identity; hash-at-rest limits DB leakage impact. |
| CSRF | No CSRF for public JSON cart API | Session CSRF token | Cart token is the bearer secret and there is no authenticated session cookie; admin CSRF rules do not apply. |
| Pricing | Recompute on every read/preview through `PricingService::quote()` | Snapshot prices in cart | Master spec requires live cart data and `PRICE_CHANGED` on accepted-total drift. |
| Availability | Return unavailable flags on cart lines | Auto-delete bad lines | Avoids silent data loss and lets UI ask the customer to fix selections. |
| Expiry | Lazy delete expired carts; `expires_at = now + 2h` after mutation | Cron cleanup | Shared-hosting compatible and proposal defers scheduled cleanup. |

## Data Flow

```text
Public page/JS -> /api/cart/* -> CartController
  -> CartToken -> CartService -> CartRepository -> carts/cart_items/cart_item_modifiers
  -> PricingService::quote(items, branch_id, fulfillment, payment_method, coupon_code, delivery_fee_cents, accepted totals)
  -> JsonResponse or server-rendered /carrito /checkout shell
```

## File Changes

| File | Action | Description |
|---|---|---|
| `api/database/migrations/005_create_carts.sql.php` | Create | `carts`, `cart_items`, `cart_item_modifiers`. |
| `api/app/Cart/CartRepository.php` | Create | Prepared-statement CRUD and catalog validation reads. |
| `api/app/Cart/CartService.php` | Create | Pure cart logic: add, qty, remove, coupon, checkout data, get/preview, mixed-cart, branch, modifier min/max. |
| `api/app/Cart/CartController.php` | Create | JSON boundary and cookie issuance. |
| `api/app/Cart/CartToken.php` | Create | Token generation/hash/validation helper. |
| `api/app/Http/Router.php` | Modify | Add `patch()`, `delete()`, and simple `{cart_item_id}` matching. |
| `api/bootstrap/app.php` | Modify | Register `/api/cart` JSON routes. |
| `api/app/Catalog/PublicCatalogController.php` | Modify | Add `cart()` and `checkout()` page handlers. |
| `api/app/Catalog/templates/{cart,checkout}.php` | Create | Server-rendered shells for cart and preview form. |
| `api/app/Catalog/templates/layout.php` | Modify | Include cart JS on storefront pages. |
| `public_html/index.php` | Modify | Route `GET /carrito` and `GET /checkout`. |
| `public_html/assets/js/shop/cart.js` | Create | Fetch add/remove/update/coupon/checkout/preview with toast feedback. |
| `tests/CartApiHttpTest.php`, `tests/PublicCartPagesHttpTest.php` | Create | Scratch DB HTTP and page probes. |

## Interfaces / Contracts

Migration `005`:
- `carts`: `id`, `token_hash CHAR(64) UNIQUE`, `branch_id BIGINT UNSIGNED NULL FK branches(id)`, `fulfillment ENUM('pickup','delivery') DEFAULT 'pickup'`, `coupon_code VARCHAR(64) NULL`, `payment_method VARCHAR(64) NULL`, `address_json JSON NULL`, `customer_note VARCHAR(500) NULL`, timestamps, `expires_at`, indexes on `expires_at`, `branch_id`.
- `cart_items`: `id`, `cart_id FK ON DELETE CASCADE`, `item_id FK`, `variant_id FK NULL`, `qty INT UNSIGNED CHECK(qty>0)`, indexes on cart/item/variant.
- `cart_item_modifiers`: `id`, `cart_item_id FK ON DELETE CASCADE`, `modifier_id FK`, `qty INT UNSIGNED DEFAULT 1`, indexes on cart item/modifier.

API: `POST /api/cart/items`, `PATCH /api/cart/items/{cart_item_id}`, `DELETE /api/cart/items/{cart_item_id}`, `GET /api/cart`, `POST /api/cart/coupon`, `POST /api/cart/checkout-data`, `POST /api/cart/preview`. Responses use `JsonResponse::ok/error`; validation errors return 422.

## Testing Strategy

| Layer | What to Test | Approach |
|---|---|---|
| Unit/integration | Service branch, fulfillment, modifier, expiry, quote input mapping | Scratch DB + direct `CartService`. |
| HTTP | Cookie token, CRUD, coupon, checkout data, preview no-side-effects | Built-in PHP server harness, `vo_cart6_test_<rand>` DBs. |
| Page | `/carrito` and `/checkout` render and include JS | HTTP probes against `public_html/router.php`. |

## Threat Matrix

Routing boundary applicable: invalid methods return 405, unknown paths 404, route parameters accept numeric cart item IDs only. No shell, subprocess, VCS/PR automation, executable classification, or process integration boundary.

## Migration / Rollout

Run migration `005` after pricing/catalog migrations. Rollback removes cart routes/classes/assets and drops cart tables; carts are non-authoritative and may be deleted.

## Open Questions

- None.
