# Proposal: Guest Cart and Checkout Preview

## Intent

Implement Phase 6: guest cart, checkout data, and backend pricing preview before Phase 7 order creation.

## Scope

### In Scope
- Guest identity: random 32-byte cookie token; DB stores only `token_hash`.
- Migration `005`: `carts`, `cart_items`, `cart_item_modifiers`, live catalog references, `expires_at`.
- JSON API: add/update/remove items, read cart, apply/remove coupon, save checkout data, final preview.
- Server-rendered cart and preview pages plus small vanilla ES6 Fetch helper.
- Validation: required variants, modifier min/max, mixed product/service pickup-only rule, same branch, unavailable-line flags, minimums, `PRICE_CHANGED`.

### Out of Scope
- Orders, accounts, stock reservation, payments, transfer proof, delivery rates/coverage, geocoding, address validation, scheduled cleanup.

## Capabilities

### New Capabilities
- `cart-checkout-preview`: guest cart persistence, checkout data, live quotes, cart API, preview-only confirmation.

### Modified Capabilities
- `catalog-public`: add storefront cart entry points and pages.

## Approach

Follow `Route -> Controller -> Service -> Repository -> MySQL`. Store `carts(token_hash UNIQUE, branch_id, fulfillment ENUM('pickup','delivery'), coupon_code, payment_method, customer_note VARCHAR(500), address_json, created_at, updated_at, expires_at)`, `cart_items(cart_id,item_id,variant_id,quantity)`, and `cart_item_modifiers(cart_item_id,modifier_id,quantity DEFAULT 1)`. Cart lines keep live references only; prices recompute through `PricingService::quote()` on read/preview.

Cart token is the bearer secret. With no PHP session/auth cookie, CSRF is not required. Changing branch with items is rejected; users clear cart first to avoid silent repricing or mixed-branch ambiguity. Expired carts may be physically deleted by lazy sweep; cron is deferred.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `api/database/migrations/005_*.php` | New | Cart schema. |
| `api/app/Cart/*` | New | Repository, service, controller, token. |
| `api/app/Http/Router.php` | Modified | PATCH/DELETE route support. |
| `api/bootstrap/app.php`, `public_html/index.php` | Modified | JSON and page routes. |
| `public_html/assets/js/shop/cart.js` | New | Minimal cart actions. |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Token theft controls cart | Med | Secure HttpOnly SameSite cookie, hash at rest, expiry. |
| Cart/pricing drift | Med | Recompute every read/preview and expose unavailable/PRICE_CHANGED. |
| Scope creep | Med | Confirm remains preview-only until Phase 7/10. |

## Rollback Plan

Remove cart routes/classes/assets and revert migration `005`; carts are non-authoritative and may be deleted.

## Dependencies

- Existing catalog/public catalog and pricing engine.

## Assumptions

- Delivery fee stays `0` or caller-supplied until Phase 10.
- Address is freeform cart JSON; `OrderAddress` starts in Phase 7.
- Stock is not reserved in this phase.

## Success Criteria

- [ ] Guests can add/edit/remove persisted cart lines.
- [ ] Preview uses backend pricing and never silently accepts changed totals.
- [ ] Service delivery, missing variants, invalid modifiers, and branch changes with items are rejected.
- [ ] Expired carts can be lazily swept safely.
