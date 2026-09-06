```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:2c7117da9e5ccb8645bbd11e6061f58c1f4cdd2ff94923b26e9ba8a43f834abb
verdict: pass
blockers: 0
critical_findings: 0
requirements: 15/15
scenarios: 15/15
test_command: D:\xampp\php\php.exe tools/run-tests.php
test_exit_code: 0
test_output_hash: sha256:7da504a6609a33ee6822f344538906a901394ffa24e2d5dec378e847338d9847
build_command: D:\xampp\php\php.exe -l over 12 changed vo-pricing files (engine, repository, resolver, admin controller, AdminController, migration 004, 4 templates, 2 test files)
build_exit_code: 0
build_output_hash: sha256:85c5981f08feb021da773a65c406352610ed70bb2c73aa56cdc6c17d5d9bd37b
```

# Verification Report

**Change**: vo-pricing
**Version**: N/A (no spec version declared)
**Mode**: Standard (Strict TDD inactive — `openspec/config.yaml` sets `strict_tdd: false`)
**Date**: 2026-08-26/27
**Verifier**: independent verify worker (fresh context), per `sdd-verify` skill contract

## Summary

All 12 implementation tasks (Unit A: A.1–A.7, Unit B: B.1–B.5) are checked and real; the two phase-end verification tasks V.1/V.2 are discharged by this verification itself (full suite + scope guard, evidence below). Full suite passes **63/63** (exit 0, real MariaDB). Beyond the suite, the verifier independently exercised the engine against the MASTER SPEC section 8 formula with **hand-computed expectations on a scratch DB (`vo_pricing_verify_<rand>`)**: Case A full stack (variant chain + paid modifier ×2 + active scheduled price + product_pct stackable + category_pct + non-stackable min_amount_pct with priority block + coupon + payment_pct + delivery 2500) matched to the cent with half-even rounding at each step; Case B coupon below minimum rejected; Case B2 coupon rejection by usage limit / expired window / archived; Case C `PRICE_CHANGED` both directions plus fresh preview and delivery-default-0; Case D pickup/delivery minimums on pre-discount gross. A second live HTTP probe drove the real admin (`php -S`) to create a `scheduled_price` promotion through `/admin/promociones` and then consumed it end-to-end through `PricingService::quote`. Scratch DBs dropped, no orphan servers, git untouched.

**Verdict: PASS** — 0 blockers, 0 critical findings, 5 suggestions.

## Completeness

| Metric | Value |
|--------|-------|
| Tasks total | 14 (A.1–A.7, B.1–B.5, V.1, V.2) |
| Tasks complete | 12 checked in `tasks.md` + 2 (V.1/V.2) discharged by this verification |
| Tasks incomplete | 0 |

Every checked task is real: `api/database/migrations/004_create_pricing_promotions.sql.php`, `api/app/Pricing/{PricingService,PricingRepository}.php`, `api/app/Catalog/CatalogPriceResolver.php` (extracted; `CatalogService::resolveDisplayPrice()` now delegates to it), `api/app/Admin/PromotionsAdminController.php` + 4 Spanish templates + `AdminController` delegation (`AdminController.php:46,119`), `tests/PricingEngineTest.php`, `tests/AdminPromotionsHttpTest.php` — all exist and pass at runtime. V.1 (full suite) and V.2 (scope guard) are executed and evidenced in this report; their `tasks.md` checkboxes remain unticked for the archive step to close (see SUGGESTION 5).

## Build & Tests Execution

**Build (php -l over 12 vo-pricing files)**: ✅ Passed — 12/12 "No syntax errors detected", exit 0.

**Tests**: ✅ 63 passed / 0 failed / 0 skipped — exit 0 (two consecutive runs, identical result), real MariaDB on 127.0.0.1:3306
```text
D:\xampp\php\php.exe tools/run-tests.php
...
PASS Tests\AdminPromotionsHttpTest::testPermissionDenyWritesAudit
PASS Tests\AdminPromotionsHttpTest::testPromotionCreateCsrfValidationAndReadOnlyUsage
PASS Tests\AdminPromotionsHttpTest::testCouponCreateStoresLimitMinimumValidityAndUsageReadonly
PASS Tests\AdminPromotionsHttpTest::testArchivePromotionKeepsRowAndAuditsRequestId
...
PASS Tests\PricingEngineTest::testEffectivePriceChainScheduledPriceAndFormulaStages
PASS Tests\PricingEngineTest::testStackabilityCouponValidityMinimumsPriceChangedRoundingAndSideEffects
...
63 tests, 0 failures
```

**Coverage**: ➖ Not available (no coverage tool configured; `strict_tdd: false`).

## Independent Engine Exercise (verifier probe, scratch DB `vo_pricing_verify_<rand>`)

Probe: migrations 001–004 + businesses/branches/branch_settings + catalog fixtures on a fresh scratch DB, calling `PricingService::quote()` directly. **54/54 checks passed** (output digest `sha256:8F8FBDA4F7E6C7F5CFDC4F5D3E99C16B572D0AF40A5FD74B65C40E8452E2105D`).

### Fixture (hand-computed on paper before running)

| Entity | Setup |
|---|---|
| Item 1 (cat 1, variant line) | base 10000, branch item 9000, variant 8000, **branch variant 7000** (chain winner); paid modifier +250; qty 2 |
| Item 2 (cat 2) | base 5000; **active scheduled price promo → 4000** (window ±1 day); qty 1 |
| Item 3 (cat 1) | base 3000, qty 2 (Case D only) |
| P1 `product_pct` | 1000bp (10%), stackable, priority 10, scope item 1 |
| P2 `category_pct` | 500bp (5%), stackable, priority 20, scope category 2 |
| P3 `min_amount_pct` | 800bp (8%), **non-stackable**, priority 30, order scope, min 10000 |
| P4 `min_amount_pct` | 500bp (5%), stackable, priority 40, order scope, min 1000 — must be blocked by P3 |
| P5 `payment_pct` | 300bp (3%), priority 50, `card` |
| P6 `scheduled_price` | 4000 on item 2, active window |
| P7 `scheduled_price` | 1111 on item 1, **2020 window (expired)** — negative control |
| Coupons | VERIFICAR 500bp min 10000 · CARO 500bp min 20000 · GASTADO limit 1 used 1 · VIEJO 2020 window · ARCHIVADO archived_at set |
| Minimums | pickup 5000 / delivery 8000 cents; delivery fee param 2500 |

### Case A — full stack (expected hand-computed per MASTER SPEC §8)

| Stage | Hand computation | Expected | Actual | |
|---|---|---:|---:|---|
| line0 unit | branch variant wins (7000), expired P7 ignored | 7000 | 7000 | PASS |
| line0 gross | (7000+250)×2 | 14500 | 14500 | PASS |
| line1 unit | active scheduled price | 4000 | 4000 | PASS |
| GROSS_ITEMS | 14500+4000 | 18500 | 18500 | PASS |
| ITEM_PROMOTIONS | 10%×14500=1450; 5%×4000=200 | 1650 | 1650 | PASS |
| ORDER_PROMOTIONS | 8%×16850=1348; P4 blocked by non-stackable P3 | 1348 | 1348 | PASS |
| COUPON_DISCOUNT | 5%×15502=775.1 → half-even 775 | 775 | 775 | PASS |
| PAYMENT_DISCOUNT | 3%×14727=441.81 → half-even 442 (base excludes delivery) | 442 | 442 | PASS |
| MERCHANDISE_TOTAL | 18500−1650−1348−775−442 | 14285 | 14285 | PASS |
| GRAND_TOTAL | 14285+2500 | 16785 | 16785 | PASS |

Applied set verified = {P1,P2,P3,P5}; P4 absent (scope block); P6 affects price, not discounts. Minimum met (18500 ≥ 8000).

### Case B — coupon below minimum (CARO min 20000 > post-auto 15502)

| Check | Expected | Actual | |
|---|---:|---:|---|
| coupon_discount_cents | 0 (rejected, absent from `discounts`) | 0 | PASS |
| order_promotions_cents | 1348 (non-stackable win re-verified) | 1348 | PASS |
| payment_discount_cents | 3%×15502=465.06 → 465 | 465 | PASS |
| merchandise_total | 15037 | 15037 | PASS |
| grand_total | 17537 | 17537 | PASS |

### Case B2 — coupon rejection paths (no suite coverage; closed here)

GASTADO (limit exhausted), VIEJO (window ended), ARCHIVADO (archived_at set): each → coupon_discount 0, no coupon entry in `discounts`, totals identical to the no-coupon quote (17537). **9/9 PASS.**

### Case C — PRICE_CHANGED semantics

| Check | Expected | Actual | |
|---|---:|---:|---|
| accepted grand 15000 + stale line 15000 | `price_changed=true` + fresh preview (grand 16785, merchandise 14285) | true / 16785 / 14285 | PASS |
| accepted grand current, line0 drift 14000 | `price_changed=true` (line-level drift alone) | true | PASS |
| accepted grand 16785 + lines 14500/4000 | `price_changed=false` | false | PASS |
| no `delivery_fee_cents` param | fee defaults 0, grand = merchandise 14285 | 0 / 14285 | PASS |

### Case D — minimum order before discounts (gross 6000, item3 ×2)

| Fulfillment | Required | Basis (pre-discount gross) | met | |
|---|---:|---:|---|---|
| pickup | 5000 | 6000 | true | PASS |
| delivery | 8000 | 6000 | false | PASS |

### Scope guard (side effects)

`promotions.times_used` and `coupons.times_used` unchanged by all quotes (incl. pre-seeded GASTADO=1); `audit_log` rows written by quoting: 0; `orders` table absent. **4/4 PASS.**

## Independent Admin HTTP Probe (verifier, live `php -S` + installed scratch DB)

Drove the real guarded flow end-to-end: login → CSRF → `POST /admin/promociones` with `type=scheduled_price`, `scope=item`, `scheduled_price_cents=3750`, active window (DB clock). **10/10 PASS** (digest `sha256:587C6B1B9FC2C6E9B06B04AD37BCBCE20D6034B51524C1C7B82C76F0A4D3B23F`):

- 302 create; `promotions` row type `scheduled_price`, `is_stackable=0`, window set
- `promotion_rules` row `scope=item`, `scope_id` targets item, `scheduled_price_cents=3750`
- audit `pricing.promotion_created` with non-null `request_id`
- `PricingService::quote()` on that item resolves unit price **3750** (admin-created promotion consumed by engine)

Cleanup: scratch DBs `vo_pricing_verify_*`/`vo_pricing_test_*` = 0 remaining; spawned `php -S` processes killed (0 orphans); `git` read-only (no commits, no staging).

## Spec Compliance Matrix — pricing-engine (9/9)

| Requirement | Scenario | Evidence | Result |
|---|---|---|---|
| Effective price resolution | Branch variant wins before scheduled price | Probe A line0=7000 (chain) + line1=4000 (scheduled); expired window ignored; `PricingEngineTest::testEffectivePriceChain…` (6000 over branch variant 7000) | ✅ COMPLIANT |
| Automatic promotion order and stackability | Non-stackable scope block | Probe A (P3 blocks P4, applied set asserted); suite test 2 (Product 50 non-stack blocks later fixed promo) | ✅ COMPLIANT |
| Coupon validation and ordering | Coupon rejected by minimum | Probe B (CARO) + suite test 2 (HIGHMIN); B2 adds limit/window/archived paths | ✅ COMPLIANT |
| Payment-method discount | Payment discount base excludes delivery | Probe A (3% of 14727, delivery 2500 untouched → 442); suite test 1 (389 of post-coupon base, delivery 1200 excluded) | ✅ COMPLIANT |
| Exact formula and delivery parameter | Formula totals are explicit | Probe A every stage matches hand computation; C delivery default 0; suite test 1 all stages (4699 grand) | ✅ COMPLIANT |
| Minimum order check | Delivery minimum fails before discounts | Probe D (6000: pickup 5000 met, delivery 8000 not met, basis = gross); suite test 2 (1005 < 2000 delivery → false) | ✅ COMPLIANT |
| PRICE_CHANGED handling | Recomputed total differs | Probe C both directions + line-only drift + current-match false; suite test 2 (999 stale → true; 498 current → false) | ✅ COMPLIANT |
| Integer money and deterministic rounding | Half-even percentage rounding | Probe A (775.1→775 down, 441.81→442 up), B (465.06→465); suite: 1005×50% tie → 502 (even), `MoneyTest` half-even | ✅ COMPLIANT |
| Scope guards | Quote has no order side effects | Probe GUARD 4/4; suite test 2 asserts no `order.%`/`payment.%`/`stock.%` audit rows; no orders tables exist | ✅ COMPLIANT |

**Compliance summary**: 9/9 scenarios compliant.

## Spec Compliance Matrix — promotions-admin (6/6)

| Requirement | Scenario | Evidence | Result |
|---|---|---|---|
| Promotion CRUD guard and CSRF | User without products.manage is denied | `AdminPromotionsHttpTest::testPermissionDenyWritesAudit` (403 + `authz.denied` audit w/ request_id); bad CSRF → 419, no row written (test 2); no `promotions.manage` anywhere (grep: spec/design text only) | ✅ COMPLIANT |
| Promotions archive, not delete | Archive keeps row | `testArchivePromotionKeepsRowAndAuditsRequestId` (archived_at set, row queryable, `promotion` entity + request_id audited) | ✅ COMPLIANT |
| Promotion types and scheduled prices | Scheduled price targets an item | Verifier HTTP probe: form creates promotion type `scheduled_price` + item-scope rule with `scheduled_price_cents=3750`; engine consumes it (3750); suite create-path test (item-scope rule persistence); `scheduled_price` not an item field (migration 003 has no such column) | ✅ COMPLIANT |
| Coupon management | Coupon usage limit is stored | `testCouponCreateStoresLimitMinimumValidityAndUsageReadonly` (limit 25, times_used 0 read-only, window sent and stored, code uppercased unique key) | ✅ COMPLIANT |
| Audit request_id | Promotion update is audited | Suite asserts `pricing.promotion_updated` + request_id NOT NULL; all six actions (created/updated/archived × promotion/coupon) asserted across tests; HTTP probe re-verified created | ✅ COMPLIANT |
| Admin scope guards | No forbidden controls | Suite asserts list page lacks `2x1`/`3x2`/`stock` and no `times_used` input; static inspection of all 4 templates: no cart/order/stock/delivery-rate/payment-processing controls | ✅ COMPLIANT |

**Compliance summary**: 6/6 scenarios compliant.

## Correctness (Static Evidence)

| Item | Status | Notes |
|---|---|---|
| Master spec §8 formula order (products → gross → auto promos → coupon → payment → merchandise → delivery → grand) | ✅ Implemented | `PricingService::quote()` stages mirror the spec order exactly |
| Price chain branch variant → variant → branch item → base | ✅ Implemented | Single shared `CatalogPriceResolver`; `CatalogService::resolveDisplayPrice()` delegates to it (no duplication) |
| Integer cents, `Money::pct()` half-even, no floats | ✅ Implemented | All money int; percentages via `Money::pct()` only |
| Promotion/coupon schema (design contract) | ✅ Implemented | Migration 004 matches design schema (types enum, bp, fixed, override, min_amount, usage, archived_at) |
| Guard/CSRF/permission reuse | ✅ Implemented | Session + CSRF + `products.manage` via existing `PermissionGuard`; Spanish error views reused |
| Audit actions + request_id threading | ✅ Implemented | Six `pricing.*` actions with `$this->requestId` |

## Coherence (Design)

| Decision | Followed? | Notes |
|---|---|---|
| Resolver reuse (extract shared resolver) | ✅ Yes | `CatalogPriceResolver` used by both catalog and pricing |
| Percent storage as basis points, money as cents | ✅ Yes | `discount_basis_points INT`, `*_cents BIGINT` |
| Percent-only coupons V1 | ✅ Yes | `discount_basis_points NOT NULL`; no max cap |
| No incompatibility table V1; stackability + priority + scope-block | ✅ Yes | Matches spec "non-stackable wins block later same-scope" |
| Reuse `products.manage`; no `promotions.manage` | ✅ Yes | Verified by grep + deny test |
| No order write tables in this change | ✅ Yes | Only promotions/promotion_rules/coupons created; `promotion_usage`/`order_discounts` deferred per proposal |

## Issues Found

**CRITICAL**: None

**WARNING**: None

**SUGGESTION**:
1. Quote result exposes no machine-readable rejection reasons (rejected coupon/promotion is only observable via absence and 0 totals). Checkout UX (Phase 7) will likely need a `rejected` list with codes.
2. Coupon code uniqueness relies on the DB unique key; a duplicate create/update surfaces as a PDO exception (500) instead of a Spanish form error. Add a friendly pre-insert check.
3. Promotion/coupon datetime inputs are free-text and interpreted against the MariaDB session clock; malformed strings surface as DB errors. Add format validation and document the timezone (on this host PHP CLI is Europe/Berlin while MariaDB runs America/Buenos_Aires — the engine itself only uses SQL `NOW()`, so app behavior is internally consistent).
4. Suite gap: `PricingEngineTest` inserts coupons `USED`/`FUTURE` but never quotes them; coupon limit/window/archived rejection was proven only by this verification's probe (B2). Consider folding B2-style assertions into the permanent suite.
5. `tasks.md` V.1/V.2 checkboxes remain unchecked although both are discharged by this report; tick them during archive bookkeeping.

## Verdict

**PASS** — All 15 requirements / 15 scenarios across both capabilities are COMPLIANT with runtime evidence (suite 63/63 plus 64/64 verifier probe checks); no blockers, no critical findings; 5 non-blocking suggestions recorded.
