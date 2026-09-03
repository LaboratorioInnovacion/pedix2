# Archive Report — vo-reports (Phase 12, FINAL)

**Archived:** 2026-08-28 · **Verdict:** verified PASS · **Evidence:** focused 8/8 + 5/5, smoke 15/15, suite chunk 1 38/38 (chunk 2 accepted from Phase 11 pass per maintainer decision — untouched by this phase)

## Scope delivered

Ventas report + Dashboard V1 (Phase 12 — final phase of Vender Online Core V1):

- **Unit A** — `ReportsRepository` (single GROUP BY day query, EXISTS subqueries for product/category filters so order money is never double-counted, branch scope via user_branches join, A1 default status set = all states except cancelled/rejected/expired, `dashboard()` with 11 pinned metrics incl. delivery buckets and low stock) + `ReportsService` (presets hoy/7d/30d/custom, validation ISO + span ≤366, canonical neta formula in one place, ARS integer-cents formatting). **Design-phase correction:** bruta = SUM(gross_items_cents) — `merchandise_total_cents` is already net of all four discount buckets, so the exploration's original mapping would have reported negative net sales; caught before apply.
- **Unit B** — `/admin/reportes` (reports.view guard with audited 403, filter form per spec 21's 7 filters, 6 metric cards, daily breakdown table with TOTAL row, empty-state), `/admin/reportes/csv` (UTF-8 BOM, semicolon separator, exact METRIC_LABELS header contract, `ventas_<from>_<to>.csv`), dashboard 11-metric block + "Reportes" card replacing the placeholder, `isReportsPath` routing.

No migration: all aggregate indexes already existed since migration 006. No schema-baseline delta.

## Verification summary

- 10/10 requirements (reports 8, admin-shell 2).
- Focused: ReportsRepositoryTest 8/8 (exact-cents hand-computed scenarios) · ReportsAdminHttpTest 5/5 (real HTTP incl. CSV byte-contract).
- Smoke 15/15; suite chunk 1 38/38. Chunk 2 not rerun — maintainer-approved option 2 (untouched by Phase 12; 40/40 at Phase 11 close).

## Specs synced

- NEW: `openspec/specs/reports/` (8 requirements).
- MERGED: `admin-shell` Reportes dashboard navigation + scope guard (reports added as third spec-governed page).
- Main spec count: **22 capabilities**.

## Project closure note

**Vender Online Core V1 implementation is COMPLETE.** All 12 roadmap phases delivered, verified, and archived: foundation, installer, auth/RBAC, catalog, pricing, cart, orders/stock/customers, payments (transfer + Mercado Pago), operations board, delivery with PIN handover, notifications outbox, reports + dashboard. 163 tests green across the final state; 22 capability specs under `openspec/specs/`.

## Open follow-ups (non-blocking, for post-V1)

1. MAINTAINER ACTION: commit the working tree — Phases 2B-12 uncommitted on `cb66ded` (single `git add -A && git commit` recommended; PR slicing optional).
2. MariaDB Windows service install (survive reboots); scratch-DB hygiene sweep before long suites.
3. Carried: per-business MP credential routing; IdempotencyConflict→409; prorated-lines legend in order detail; notification sweep cron endpoint; suite sharding/schema-template cache; driver panel + auto-assignment (delivery future slice); "reportes Pro" (spec line 1415).
4. php -S development server is for dev only — production deploy needs Apache config per spec section 28.

## Traceability

Engram: `sdd/vo-reports/{explore(4094),proposal(4095),spec(4096),design(4097),tasks(4098),apply-progress(4103),verify-report}`. Focused test files: `tests/ReportsRepositoryTest.php`, `tests/ReportsAdminHttpTest.php`.
