# Design: VO Pricing

## Technical Approach
Implement pricing as `Route -> Controller -> Service -> Repository -> MySQL`, with Unit A delivering the backend pricing source of truth and Unit B adding Spanish admin management. The quote engine reads catalog data, resolves prices with the existing catalog chain, applies promotions/coupon/payment discounts in the spec order, and returns preview data only.

## File Tree and Work Units

| Unit | File | Action | Purpose | Est. LOC |
|---|---|---|---|---:|
| A | `api/database/migrations/004_create_pricing_promotions.sql.php` | Create | `promotions`, `promotion_rules`, `coupons`; no order usage tables yet. | 90 |
| A | `api/app/Pricing/PricingRepository.php` | Create | Query active promotions/coupons and catalog rows. | 120 |
| A | `api/app/Pricing/PricingService.php` | Create | Quote algorithm, formula, minimums, `PRICE_CHANGED`. | 180 |
| A | `tests/PricingEngineTest.php` | Create | Exhaustive scratch-DB pricing tests. | 240 |
| B | `api/app/Admin/PromotionsAdminController.php` | Create | Guarded CRUD for promotions/coupons. | 170 |
| B | `api/app/Admin/AdminController.php` | Modify | Delegate `/admin/promociones` and `/admin/cupones`. | 20 |
| B | `api/app/Admin/templates/promotions_*.php`, `coupons_*.php` | Create | Spanish list/form/error views. | 150 |
| B | `tests/AdminPromotionsHttpTest.php` | Create | Guard, CSRF, CRUD, audit, archive HTTP tests. | 230 |

## Architecture Decisions

| Decision | Choice | Tradeoff / Rationale |
|---|---|---|
| Resolver reuse | Extract `CatalogPriceResolver` or make `CatalogService::resolveDisplayPrice()` injectable/static and call it from pricing; do not duplicate chain logic. | Keeps catalog display and pricing behavior aligned. |
| Percent storage | Store percentages as integer basis points: `discount_basis_points INT`; fixed money as `amount_cents BIGINT`; scheduled override as `override_price_cents BIGINT`. | Matches `Money::pct()` denominator and avoids floats. |
| Coupon shape | Percent-only coupons with optional `max_discount_cents` omitted for V1. | Keeps “simple coupons” small and testable. |
| Incompatibility V1 | No explicit incompatibility list table; rely on `is_stackable`, priority, and scope-blocking. | Master mentions incompatibilities, but proposal narrows V1 semantics. |
| Permission | Reuse `products.manage`; no `promotions.manage` seed. | Simpler and aligned with catalog admin ownership. |

## Schema Contract
`promotions`: `id`, `name`, `type ENUM('product_pct','product_fixed','category_pct','min_amount_pct','payment_pct','scheduled_price')`, `priority INT`, `is_stackable TINYINT`, `starts_at`, `ends_at`, `usage_limit`, `times_used DEFAULT 0`, `archived_at`, timestamps. `promotion_rules`: `promotion_id`, `scope ENUM('item','category','order','payment_method')`, `scope_id NULL`, `payment_method VARCHAR(64) NULL`, `discount_basis_points INT NULL`, `amount_cents BIGINT NULL`, `override_price_cents BIGINT NULL`, `min_amount_cents BIGINT NULL`. `coupons`: `code UNIQUE`, `discount_basis_points INT`, `min_amount_cents NULL`, `starts_at`, `ends_at`, `usage_limit NULL`, `times_used DEFAULT 0`, `archived_at`, timestamps.

## Engine Algorithm
1. Validate branch, fulfillment, integer cents, and active catalog lines.
2. Resolve base line prices through catalog resolver; apply valid scheduled price override first.
3. Add modifiers and compute `GROSS_ITEMS` and per-line gross totals.
4. Check pickup/delivery minimums against gross items, excluding delivery and discounts.
5. Apply product/category/min-amount automatic promotions by priority with stackability scope blocking.
6. Validate coupon and apply it after automatic promotions.
7. Apply payment-method percentage discount to merchandise subtotal after coupon.
8. Compute formula, add delivery fee default `0`, compare accepted totals, and return `PRICE_CHANGED` preview when needed.

## Testing Strategy
Unit A tests are the star: `tests/PricingEngineTest.php` uses scratch MariaDB fixtures and covers every formula stage, stackability matrix, coupon rules, pickup/delivery minimums, both `PRICE_CHANGED` directions, scheduled price override, payment discount, and integer/half-even rounding. Unit B follows `AdminCatalogHttpTest` server style for auth denial, CSRF, CRUD, request_id audits, and archive-not-delete.

## Threat Matrix
N/A — no shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary. Admin routing changes use existing `AdminController` delegation and are covered by HTTP guard tests.

## Migration / Rollout
Run migration `004` after migrations `001-003`. Rollback before production may drop the new tables; after production, archive/deactivate rows and only drop tables if no future order usage references exist.

## Open Questions
None.
