# Proposal: VO Pricing

## Intent
Implement Phase 5 pricing as the backend single source of truth for cart preview, checkout, order creation, and later order modification, preserving integer cents and explicit `PRICE_CHANGED` handling.

## Scope
### In Scope
- Pricing engine API: input item/variant/modifier lines, branch, fulfillment, payment method, coupon code, delivery fee cents default `0`, and accepted totals; output line breakdown, discounts, minimum status, and price-change flags.
- Promotion/coupon schema and engine rules for automatic promotions, item/category/minimum-amount/payment-method discounts, scheduled promo prices, and simple coupons.
- Spanish admin CRUD for promotions/coupons guarded by auth, CSRF, `products.manage`, branch scope where applicable, and audited changes.

### Out of Scope
- Cart endpoints, checkout/order creation, stock reservations, delivery-rate calculation, payment gateway processing, 2x1/3x2, complex promo rules, public cart preview UI.

## Capabilities
### New Capabilities
- `pricing-engine`: Effective prices, promotion/coupon/payment-discount order, minimums, delivery parameter, and `PRICE_CHANGED` detection.
- `promotions-admin`: Promotion/coupon persistence and admin management.

### Modified Capabilities
- None.

## Technical Exploration
- API shape: `PricingService::quote(PricingQuoteRequest): PricingQuoteResult`; request carries `branch_id`, `fulfillment` (`pickup|delivery`), `payment_method`, `coupon_code`, `delivery_fee_cents=0`, `accepted_totals`, and lines `{item_id, variant_id?, qty, modifiers[]}`. Result carries `gross_items`, `item_promotions`, `order_promotions`, `coupon_discount`, `payment_discount`, `merchandise_total`, `customer_delivery_fee`, `grand_total`, line totals, applied/rejected discounts, minimum result, and `price_changed` details.
- Entities now: `promotions`, `promotion_rules`, `coupons`; recommend creating `promotion_usage` and `order_discounts` in Phase 7 because rows are written only at order time.
- Stackability: evaluate valid automatic promotions by ascending priority. A non-stackable applicable promotion wins for its scope and blocks later compatible automatic promotions for that same scope; stackable promotions may combine unless explicitly incompatible. Coupons run after automatic promotions and obey their own incompatibilities.

## Assumptions
- Scheduled promo price overrides effective item price before percentage/fixed promos.
- Coupon applies after automatic promotions; payment discount is a percentage of merchandise subtotal after coupon.
- Minimum order is checked before discounts, excluding delivery, with separate pickup/delivery thresholds.
- `PRICE_CHANGED` compares accepted grand total and line totals against recomputation; never silently confirms.

## Affected Areas
| Area | Impact | Description |
|---|---|---|
| `api/database/migrations/004_create_pricing_promotions.sql.php` | New | Promotion/coupon tables and admin permission seed if needed. |
| `api/app/Pricing/*` | New | PricingService, repository, DTO-style request/result arrays, tests. |
| `api/app/Admin/*` | Modified | Promotions/coupons admin controller/templates/routes. |
| `openspec/specs/*` | New | `pricing-engine`, `promotions-admin`. |

## Risks
| Risk | Likelihood | Mitigation |
|---|---|---|
| Discount ordering drift | Med | Exhaustive unit tests around section 8 formula. |
| Schema overreach into orders | Med | Defer order write tables to Phase 7. |
| Admin slice exceeds 400 lines | Med | Split implementation units. |

## Rollback Plan
Remove Phase 5 routes/classes/templates and reverse migration `004` before production data; after production, archive/deactivate promotions and run a targeted migration rollback only if no order usage rows exist.

## Dependencies
- Existing Money, catalog price resolver/order, auth/CSRF/RBAC/audit/admin patterns, migrations 001-003.

## Success Criteria
- [ ] Formula matches spec section 8 exactly: gross minus automatic promos, coupon, payment discount equals merchandise total; delivery parameter yields grand total.
- [ ] Effective price order remains branch variant → variant → branch item → base.
- [ ] Coupon validation covers validity window, global usage limit, and minimum amount.
- [ ] PRICE_CHANGED returns recomputed preview and requires renewed acceptance.
