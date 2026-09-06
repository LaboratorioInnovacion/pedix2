# Verification Report — vo-reports (Phase 12, FINAL)

**Verdict: PASS** — 10/10 requirements compliant (reports 8, admin-shell 2), 0 CRITICAL, 0 WARNING.
Inline bounded verification with maintainer-approved evidence set (option 2: skip untouched-suite rerun).

## Evidence

| Check | Result |
|---|---|
| ReportsRepositoryTest (focused) | **8/8** — hand-computed exact-cents scenarios: default status set (cancelled excluded), neta ≡ grand − payout identity, status override, product/category EXISTS filters count order money ONCE, payment/fulfillment filters, branch scope isolation (out-of-scope → zero), empty range → zero row, service validation/presets/rollup, dashboard 11 metrics scoped |
| ReportsAdminHttpTest (focused) | **5/5** — page metrics/filters over real HTTP, CSV exact rows (BOM + semicolon + METRIC_LABELS header + filename), guard 403 + authz.denied + anonymous redirect, branch-scoped visibility, dashboard metrics block + Reportes card |
| Smoke (serial) | ReportsRepositoryTest + NotificationsAdminHttpTest + AdminHttpTest → **15/15** |
| Suite chunk 1 (Admin*/Auth/Baseline/Cart/Catalog/Config incl. AdminHttpTest) | **38/38** |
| Suite chunk 2 (Csrf→NotificationTransport) | **NOT rerun this phase** — maintainer-approved: none of these 40 tests are touched by Phase 12 code (AdminController/dashboard changes covered by chunk 1's AdminHttpTest + focused smoke); last full pass 40/40 in Phase 11 closing |
| Report-level acceptance | CSV byte-contract (BOM, semicolon, exact header order, filename `ventas_<from>_<to>.csv`); canonical neta formula lives in exactly one place; integer cents throughout |

## Requirement traceability

- **R1-R4 (filters/status semantics)**: ReportsRepositoryTest scenarios 1-4 + HTTP filter assertions.
- **R5 (canonical formulas)**: neta identity test; design correction validated (bruta = SUM(gross_items_cents); merchandise_total is already net — the exploration's original mapping would have reported negative net sales; caught at planning).
- **R6 (daily breakdown)**: daily rows sum to totals test.
- **R7 (branch scoping)**: scope isolation + HTTP scoped-visibility tests.
- **R8 (CSV export)**: byte-contract HTTP test.
- **admin-shell (nav card + scope guard)**: dashboard card test + page guard test.

## Findings

**CRITICAL:** none. **WARNING:** none.
**SUGGESTION:** (1) fputcsv quotes space-containing fields — CSV rows built with implode(';') to honor the byte contract (documented in code); (2) dashboard "hoy" uses CURDATE() range (index-friendly, semantically equal to DATE(created_at)=CURDATE()); (3) chunked suite remains the evidence format; consider permanent sharding + scratch-DB template cache; (4) carried follow-ups unchanged (MariaDB service install; per-business MP; IdempotencyConflict→409; sweep cron endpoint).

## Verdict

The Ventas report implements master spec section 21 exactly: 7 filters (validated, presets, silent invalid handling), 6 metrics with the corrected canonical formula, daily breakdown, CSV export byte-contract, branch scoping via user_branches, reports.view guard with audited denials, and the 11-metric Dashboard V1 block. **PASS — ready for archive. This closes Phase 12 and the Vender Online Core V1 implementation.**
