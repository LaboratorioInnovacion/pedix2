# Archive Report: vo-pricing

**Change**: vo-pricing (Phase 5 — PricingService engine + promotions/coupons admin)
**Archived**: 2026-08-26 → `openspec/changes/archive/2026-08-26-vo-pricing/`
**Verdict at close**: PASS — 15/15 requirements, 15/15 scenarios, suite 63/63, 0 blockers, 0 critical, 0 warnings, 5 non-blocking suggestions.

## Final State

Phase 5 is complete and independently verified. All 14 tasks (A.1–A.7, B.1–B.5, V.1, V.2) are closed in `tasks.md`. Full test suite: **63 passed / 0 failed / 0 skipped** (exit 0, two consecutive runs, real MariaDB on 127.0.0.1:3306). Build: `php -l` clean over all 12 vo-pricing files. No CRITICAL or WARNING findings; the 5 suggestions below are non-blocking follow-ups.

Beyond the suite, the verifier independently validated the engine formula with **hand-computed expectations on a scratch DB (`vo_pricing_verify_<rand>`)**: 54/54 engine probe checks passed, including Case A full-stack (branch-variant chain 7000 → scheduled price 4000 → product_pct 10% → category_pct 5% → non-stackable min_amount_pct 8% blocking P4 → coupon 5% half-even 775 → payment_pct 3% half-even 442 → merchandise 14285 → grand 16785 with delivery 2500) matching the MASTER SPEC §8 formula to the cent at every stage; coupon rejection paths (minimum / usage limit / expired window / archived, 9/9); `PRICE_CHANGED` both directions plus line-only drift and delivery-default-0; pickup/delivery minimums on pre-discount gross; and side-effect guards (no usage counters mutated, 0 audit rows from quoting, no orders). A live admin HTTP probe (10/10) drove the real guarded flow end-to-end: login → CSRF → create `scheduled_price` promotion via `/admin/promociones` → engine consumed it at 3750 through `PricingService::quote()`. Scratch DBs dropped; no orphan `php -S` processes; git untouched by verification.

## Delivered Units

| Unit | Contents |
|---|---|
| A — Pricing engine | Migration `004_create_pricing_promotions.sql.php` (promotions, promotion_rules, coupons — no order-write tables); `CatalogPriceResolver` extraction (catalog delegates to it, chain unchanged: branch variant → variant → branch item → base); `api/app/Pricing/PricingRepository.php` (PDO prepared statements); `api/app/Pricing/PricingService.php` (quote request/result arrays, ordered discount stages, integer cents, `Money::pct()` half-even, delivery default 0, `PRICE_CHANGED`); `tests/PricingEngineTest.php`. |
| B — Promotions admin | `api/app/Admin/PromotionsAdminController.php` guarded by session + CSRF + `products.manage` (no `promotions.manage` introduced); 4 Spanish templates (`promotions_list/form`, `coupons_list/form`) reusing existing error views; `AdminController` delegation for `/admin/promociones*` and `/admin/cupones*`; audit actions `pricing.{promotion,coupon}_{created,updated,archived}` with `request_id`; archive-not-delete semantics; `tests/AdminPromotionsHttpTest.php`. |

## Spec Sync

| Domain | Action | Details |
|---|---|---|
| `pricing-engine` | Created `openspec/specs/pricing-engine/spec.md` | 9 requirements added (delta was a full spec — no prior main spec existed) |
| `promotions-admin` | Created `openspec/specs/promotions-admin/spec.md` | 6 requirements added (delta was a full spec — no prior main spec existed) |

No MODIFIED/REMOVED/RENAMED requirements; no other domains touched.

## Compressed Planning Note

This cycle used **compressed planning** per maintainer velocity mode: a combined explore+propose pass and a combined spec+design+tasks pass. All phase artifacts exist regardless (proposal.md, specs/, design.md, tasks.md, verification.md — no separate exploration.md, which is optional). The compression affected planning granularity only, not artifact coverage or verification depth.

## Task Reconciliation Record (archive-time)

`tasks.md` V.1 (full suite) and V.2 (scope guard) were left unchecked at verify time, with the verify report explicitly noting they were discharged by the verification itself and deferring the tick to archive bookkeeping (SUGGESTION 5). Both were ticked during this archive with proof from `verification.md`: V.1 — full suite 63/63, exit 0, two consecutive runs; V.2 — scope guard confirmed (only promotions/promotion_rules/coupons created; no cart/order/stock/delivery-rate/payment-processing/2x1/3x2 behavior added; guard checks 4/4 PASS). No other checkboxes were touched.

## Review Gate Note

No native review artifacts (transaction/ledger/receipt/gate-context) or `state.yaml` exist for this change; this project's cycle ran in local NO-COMMIT mode without native review governing it. Archive proceeded on the orchestrator's launch facts (change COMPLETE, verify verdict PASS, 0 critical/warning), which are the most recent account and corroborated in full by `verification.md` in this archive.

## Suggestions / Open Follow-ups (non-blocking)

1. **Rejection-reason list** — quote results expose no machine-readable rejection reasons (rejected coupon/promotion observable only via absence and 0 totals). Checkout UX (Phase 7) will likely need a `rejected` list with codes.
2. **Coupon duplicate-code UX** — code uniqueness relies on the DB unique key; duplicate create/update surfaces as a PDO exception (500) instead of a Spanish form error. Add a friendly pre-insert check.
3. **Timezone hardening (pre-production)** — see environmental note below.
4. **Fold B2 probes into suite** — coupon limit/window/archived rejection was proven only by the verifier's probe (B2); `PricingEngineTest` inserts `USED`/`FUTURE` coupons but never quotes them. Fold B2-style assertions into the permanent suite.
5. ~~V.1/V.2 checkbox ticks~~ — closed by this archive (see Task Reconciliation Record).

## Timezone Environmental Note (pre-production hardening)

On this host, PHP CLI default timezone (Europe/Berlin) differs from the MariaDB session timezone (America/Buenos_Aires). The engine uses only SQL `NOW()` for promotion/coupon windows, so behavior is internally consistent today, but free-text datetime inputs from the admin are interpreted against the DB session clock and malformed strings surface as DB errors. Before production: set an explicit PHP timezone matching the DB (America/Buenos_Aires), add format validation on datetime inputs, and document the timezone convention.

## Uncommitted Files Note

All Phase 5 work (57 uncommitted paths at archive time — engine, repository, resolver, migration 004, admin controller/templates, both test files, and these openspec artifacts) lives in the working tree only, uncommitted since baseline `0439b22`. NO-COMMIT MODE was in effect for this entire cycle including this archive; committing is a separate maintainer decision.

## Next Phase

Phase 6 — Cart + checkout preview. `promotion_usage` and `order_discounts` tables remain deferred to Phase 7 per proposal.

## Artifact Store

- OpenSpec: this archived folder (proposal.md, specs/{pricing-engine,promotions-admin}/spec.md, design.md, tasks.md, verification.md, archive-report.md)
- Engram: `sdd/vo-pricing/archive-report` (observation id recorded by the archive worker)
