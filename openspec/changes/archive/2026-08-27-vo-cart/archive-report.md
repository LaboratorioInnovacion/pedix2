# Archive Report: vo-cart (Phase 6 — Guest Cart and Checkout Preview)

**Date**: 2026-08-27
**Verdict**: PASS — archived after verified completion. 0 blockers, 0 CRITICAL findings.
**Mode**: hybrid (openspec filesystem + Engram persistence). NO-COMMIT MODE: no git mutations performed.

## Final State at Close

Change `vo-cart` is COMPLETE and VERIFIED PASS: 9/9 requirements, 11/11 scenarios, 57/57 independent probe checks, full suite **68/68 (two identical consecutive runs, exit 0)**. Per the Final-State Authority, the launch-prompt final-state facts outrank intermediate snapshots; where `apply-progress`/`verify-report` describe earlier moments, this report states the terminal state.

## Work Units Delivered

- **Unit A — Backend cart**: `api/database/migrations/005_create_cart.sql.php` (`carts`, `cart_items`, `cart_item_modifiers`), `api/app/Cart/{CartToken,CartRepository,CartService,CartApiController}.php`, JSON API (`POST/PATCH/DELETE /api/cart/items/{id}`, `GET /api/cart`, coupon, checkout-data, preview), PATCH/DELETE router support, route wiring.
- **Unit B — Storefront**: `api/app/Cart/CartController.php` (server-rendered `/carrito` + `/checkout`), `api/app/Cart/templates/{cart_page,checkout_page}.php` (Spanish, escaped), `public_html/assets/js/shop/cart.js` (minimal vanilla ES6; JS never computes totals), `public_html/index.php` routes, layout/product cart CTAs.

## Verification Summary (per verify-report, observation #3687)

- Suite: 68/68, exit 0, two runs with identical result, real MariaDB 127.0.0.1:3306.
- Independent HTTP integration probe (scratch DB `vo_cart6_verify_<rand>`, migrations 001–005 + seed, built-in server + cookie jar): **57/57 checks passed**; scratch DBs dropped after each run.
- **Hand-computed preview case matched to the cent** (MASTER SPEC §8 order): gross 3700 → coupon FIFTY 50% = 1850 → payment cash 10% = 185 (half-even) → merchandise 1665 → delivery 0 → **grand total 1665**. PRICE_CHANGED both directions (stale 1665 → `price_changed=true`, fresh 2565 after variant 1000→2000; re-accept → `confirmation_ready=true`).
- Rule matrix verified: branch lock 409, mixed product/service delivery 409 (cart stays pickup at original branch), unavailable lines flagged not deleted, delivery minimum `met=false`, required-modifier `min_select=1` rejection, SHA-256 hash-at-rest, lazy expiry deletion with cascade, preview side-effect guards (no order/payment/stock tables exist in Phase 6).

## Filename-Drift Correction Note (verify WARNING 1 — resolved at archive)

The `tasks.md` and `design.md` texts inside this archived folder reference filenames that drifted during implementation. The text is preserved as historical record (NOT rewritten); the ACTUAL files are:

| Referenced in tasks/design | Actual file |
|---|---|
| `tests/CartApiHttpTest.php` | `tests/CartApiTest.php` |
| `tests/PublicCartPagesHttpTest.php` | `tests/CartPageTest.php` |
| `api/database/migrations/005_create_carts.sql.php` | `005_create_cart.sql.php` |
| `api/app/Catalog/templates/{cart,checkout}.php` | `api/app/Cart/templates/{cart_page,checkout_page}.php` |
| B.1 modify `PublicCatalogController` | new `api/app/Cart/CartController.php` (user-directed, per apply-progress #3606) |

The synced main specs (`openspec/specs/cart-api/`, `openspec/specs/catalog-public/`) are the source of truth and are unaffected by this drift. Task checkboxes were left as written; the drift is documentation-level only, behavior unaffected.

## Compressed Planning Note

Compressed planning was used per maintainer velocity mode: explore+propose combined, and spec+design+tasks combined. Artifacts remain complete despite the compression.

## Tasks Completion

15/15 tasks checked (A.1–A.8, B.1–B.7) in archived `tasks.md`. No `V.x` housekeeping checkbox section exists in this change's tasks.md — nothing to tick at archive. A.8/B.7 verification placeholders were discharged by the verification itself (per verify-report).

## Suggestions / Follow-ups (non-blocking, recorded for later phases)

1. Test harness `stop()` emits Windows `taskkill` noise (`ERROR: no se encontró el proceso "<pid>"`) after `proc_terminate` already reaped the PID — suppress or check `proc_get_status`.
2. Undocumented `GET /api/cart/preview` endpoint exists (test convenience read) beyond the spec's `POST` — document it or remove at Phase 7.
3. Bodyless `POST /api/cart/preview` silently falls back to GET-like behavior — consider 400 for an explicit contract.
4. `minimum_order.met=false` is not surfaced as a user-visible warning on the checkout page — wire minimums messaging when order creation lands (Phase 7/10).
5. `vo_flash` cookie lacks `HttpOnly` (UI-only flash text, low risk) — unify with cart cookie flag policy.

## Operations Notes

- **MariaDB environmental**: MariaDB was DOWN at the verify session start; the verify worker started it via `D:\xampp\mysql_start.bat` (MariaDB 10.4.32). Tests fail loudly when DB is absent (by design). Windows service install still pending a user admin step — unattended runs will fail until it is registered.
- **Uncommitted files**: all Phase 6 work is working-tree only, uncommitted since HEAD `0439b22` (`feat(installer): add baseline schema and seed foundation`) — NO-COMMIT MODE was respected; ~65 changed files pending the maintainer's commit decision.

## Phase Boundaries (by design, later phases)

- No order creation: preview/confirmation is preview-only (`confirmation_ready` gating only). Order creation starts Phase 7.
- Delivery fee hardcoded 0 until Phase 10.
- Delivery minimum unmet is informational until Phase 7 order creation.
- No stock reservation; no customer accounts; no payments.

## Spec Sync (Step performed at archive)

- `cart-api`: NEW capability — delta copied verbatim (SHA-256-identical) to `openspec/specs/cart-api/spec.md`.
- `catalog-public`: delta MODIFIED 2 requirements merged into existing `openspec/specs/catalog-public/spec.md` by name match: **Public Catalog Routes** (now includes `/carrito`, `/checkout`, backend-preview-only rule) and **Public Catalog Scope Guard** (now allows guest-cart mutation via cart API only). Delta `(Previously: ...)` annotations were delta-operation syntax and were not carried into the main spec — full history remains in this archived delta. 3 unrelated requirements preserved untouched: Public Visibility Rules, Display-Only Stored Price, Search and Category Browsing. No `checkout-storefront` capability dir was created (merged into existing `catalog-public` to avoid duplication).

## Engram Traceability (artifact store: both)

| Artifact | Observation ID | Topic |
|---|---|---|
| proposal | #3600 | `sdd/vo-cart/proposal` |
| spec | #3602 | `sdd/vo-cart/spec` |
| design | #3603 | `sdd/vo-cart/design` |
| tasks | #3604 | `sdd/vo-cart/tasks` |
| apply-progress | #3606 | `sdd/vo-cart/apply-progress` |
| verify-report | #3687 | `sdd/vo-cart/verify-report` |
| review/{transaction,ledger,receipt,gate-context} | none | native review unmanaged for this change (no review artifacts exist) |
| archive-report | (this file + Engram save) | `sdd/vo-cart/archive-report` |

## Gates

- Task Completion Gate: PASS (15/15 checked; no stale unchecked implementation tasks).
- Native Review Receipt Gate: review delivery unmanaged — no review artifacts exist in the repo; archive proceeded under the unmanaged relaxation.
- CRITICAL blocker check: 0 CRITICAL findings — archive permitted.

## Next Phase

Phase 7 — Orders + inventory + `promotion_usage`/`order_discounts` + customer accounts + order creation from cart confirmation.
