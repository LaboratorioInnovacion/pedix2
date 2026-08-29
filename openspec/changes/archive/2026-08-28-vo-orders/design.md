# Design: Orders, Customers, and Stock Reservation

## Technical Approach

Implement Phase 7 in the current PHP/PDO flow: cart API delegates confirmation to `OrderService`, which owns one DB transaction and calls `PricingService::quote()` as the only pricing source. Public and admin read paths render immutable order snapshots; no payment/delivery state is introduced.

## Architecture Decisions

| Decision | Choice | Tradeoff / rationale |
|---|---|---|
| Cart lineage | Keep `carts` row, delete only `cart_items`; add `orders.cart_token_hash` | Preserves idempotency/debug lineage without keeping mutable cart contents. |
| Stock location | Add `stock_quantity INT UNSIGNED NOT NULL DEFAULT 0` and `reserved_quantity INT UNSIGNED NOT NULL DEFAULT 0` to `branch_items` | `003` already models stock per branch+item via `stock_mode`; Phase 7 does not add variant-level stock. |
| Admin permission | Reuse `products.manage` for read-only orders | Minimal Phase 7 wiring; Phase 9 may introduce `orders.view`. |
| Public token | `CHAR(64)` bin2hex(random_bytes(32)) | Matches existing 64-char token/hash style and avoids base64url parsing edge cases. |
| Idempotency | DB table stores request hash, status code, response JSON, optional order id | Survives PHP process restarts and handles double-click/retry safely. |

## Migration 006: exact schema

Create `api/database/migrations/006_create_orders.sql.php`:
- Alter `branch_items`: add `stock_quantity`, `reserved_quantity`, index `(branch_id,item_id,stock_mode)`.
- `customers`: `id`, `business_id`, nullable `user_id`, `name`, `email`, `phone`, `password_hash NULL`, timestamps, `UNIQUE(business_id,email)`, FK business CASCADE, user SET NULL.
- `order_counters`: `business_id PK`, `next_number BIGINT UNSIGNED NOT NULL DEFAULT 1`, timestamps, FK business CASCADE.
- `orders`: `id`, `business_id`, `branch_id`, `customer_id NULL`, `number VARCHAR(32)`, `status ENUM(...) DEFAULT 'pending'`, `public_token CHAR(64) UNIQUE`, `cart_token_hash CHAR(64) NULL`, `fulfillment`, `payment_method`, `customer_name/email/phone`, `customer_note`, pricing snapshot columns (`gross_items_cents`, `item_promotions_cents`, `order_promotions_cents`, `coupon_discount_cents`, `payment_discount_cents`, `merchandise_total_cents`, `delivery_fee_cents`, `grand_total_cents`), timestamps, `UNIQUE(business_id,number)`, indexes status/branch/customer, FKs business/branch RESTRICT, customer SET NULL.
- `order_items`: order FK CASCADE, branch/item/variant FKs RESTRICT, snapshot `item_name`, `variant_name`, `quantity`, `unit_price_cents`, `modifiers_total_cents`, `gross_unit_cents`, `gross_line_cents`, `line_total_cents`.
- `order_item_modifiers`: order_item FK CASCADE, modifier FK RESTRICT, snapshot `group_name`, `modifier_name`, `price_delta_cents`, `qty`.
- `order_addresses`: order FK CASCADE, `type`, `recipient_name`, `phone`, `street`, `city`, `notes`, `address_json JSON`.
- `order_discounts`: order FK CASCADE, nullable promotion/coupon refs SET NULL, `kind`, `type`, `scope`, `line_index`, `code`, `amount_cents`, `snapshot_json`.
- `promotion_usage`: order FK CASCADE, nullable promotion/coupon SET NULL, `business_id`, `kind`, `code`, `amount_cents`, `UNIQUE(order_id,kind,promotion_id,coupon_id,code)`.
- `stock_movements`: no normal deletes; FKs business/branch/order/item/variant, signed `quantity_delta`, `reason ENUM('reserve','release_cancelled','release_rejected','release_expired','adjustment')`, `note`, `actor_type`, `actor_id`, timestamps.
- `idempotency_keys`: `business_id`, `idempotency_key`, `request_hash`, `status_code`, `response_json JSON`, `order_id NULL`, `expires_at`, `UNIQUE(business_id,idempotency_key)`, FK order SET NULL.

## State Map

Create `api/app/Orders/OrderStateMap.php` returning `new StateMachine($states,$transitions)`: `pending => [change_proposed,accepted,rejected,cancelled,expired]`, `change_proposed => [accepted,rejected,cancelled,expired]`, `accepted => [in_progress,cancelled]`, `in_progress => [ready,cancelled]`, `ready => [completed,cancelled]`, terminals empty.

## Core Flow

`OrderService::createFromCart(string $cartTokenHash,string $idempotencyKey,array $acceptedTotals,array $customerInput=[]): array`:
1. Start transaction; derive branch/business; lock idempotency row or replay stored response.
2. Lock cart `SELECT ... FOR UPDATE`; load cart lines/modifiers; build quote request with accepted totals; recompute.
3. If `price_changed`, rollback and return typed 409 payload with current quote; do not write idempotency/order/counter.
4. Validate stock via `StockService` locks for `stock_mode='simple'`.
5. `CustomerService` find/create customer when email/account input exists; always copy contact snapshots.
6. Lock/insert `order_counters`, generate `B-%06d`, insert order, items, modifiers, address, discounts, usage; increment promotion/coupon counters once.
7. Reserve stock with `stock_movements(reason=reserve)`, delete `cart_items`, store idempotency response, commit.

## File Changes

| File | Action | Description |
|---|---|---|
| `api/app/Orders/*` | Create | Service, repository, state map, public/admin controllers/templates. |
| `api/app/Inventory/StockService.php` | Create | Check/reserve/release with movement log. |
| `api/app/Domain/DbIdempotencyStore.php` | Create | Implements existing `IdempotencyStore` shape against DB. |
| `api/app/Cart/{CartApiController,CartService}.php` | Modify | Add `/api/cart/confirm`; keep preview side-effect free. |
| `api/app/Admin/AdminController.php` | Modify | Delegate `/admin/pedidos` routes. |
| `api/bootstrap/app.php`, `public_html/index.php` | Modify | Wire API/public routes. |
| `tests/*` | Modify/Create | Order service/API/public/admin/schema tests; update migration/table lists. |

## Testing Strategy

Unit/service tests cover state map, idempotent replay, price_changed, snapshots, stock reservation/release, usage increments. HTTP tests cover `/api/cart/confirm`, `/pedido/{token}`, and guarded `/admin/pedidos`. Schema tests update migration list to include `006`; remove `orders`, `customers`, `stock_movements` from deferred lists; carts already exists and stays non-deferred.

## Threat Matrix

Applicable to routing changes only: unknown `/pedido/{token}` and unauthorized `/admin/pedidos` MUST fail safely (404/302/403); route params MUST be regex-constrained and rendered snapshots escaped by templates. No shell, subprocess, VCS, executable classification, or process-integration boundary.

## Migration / Rollout

Run migration 006 before exposing confirm. In rollback before production orders, drop Phase 7 tables/columns in scratch DB; after production data exists, disable confirm route and forward-migrate only.

## Open Questions

None.
