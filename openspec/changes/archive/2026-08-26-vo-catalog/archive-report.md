# Archive Report: vo-catalog

**Change**: vo-catalog
**Phase**: 4 — Catalog (schema + admin CRUD + public storefront)
**Archived**: 2026-08-26
**Archive path**: `openspec/changes/archive/2026-08-26-vo-catalog/`
**Final state**: COMPLETE and VERIFIED **PASS** (post-remediation)

## Final State at Close

- Requirements: **17/17** compliant
- Scenarios: **22/22** compliant
- Suite: **57 tests / 0 failures** (`D:\xampp\php\php.exe tools/run-tests.php`, real MariaDB, zero skips)
- Tasks: **16/16** complete (A.1–A.6, B.1–B.5, C.1–C.5) — persisted tasks artifact fully checked, no stale checkboxes, no reconciliation needed
- All implementation exists as **uncommitted working-tree changes since baseline commit `0439b22`**, per explicit user instruction (NO-COMMIT local mode; tasks.md records delivery strategy `auto-chain`, chain strategy `stacked-to-main` for a future commit pass)

## Unit Breakdown

| Unit | Scope | Key artifacts |
|---|---|---|
| A — Schema + Core | Migration `003` (9 catalog tables, archive columns, branch overrides, image metadata, idempotent `products.manage` owner seed), `CatalogRepository` (prepared admin/public CRUD, category descendant expansion), `CatalogService` (transactions, validation, archive-not-delete, audits) | `api/database/migrations/003_create_catalog.sql.php`, `api/app/Catalog/CatalogRepository.php`, `api/app/Catalog/CatalogService.php`, `tests/CatalogSchemaTest.php`, `tests/CatalogServiceTest.php` |
| B — Admin CRUD | Guarded Spanish admin screens for categories, items, variants, modifier groups, branch catalog; auth + `products.manage` + `user_branches` scope + CSRF + POST allowlist + audit delegation | `api/app/Admin/CatalogAdminController.php`, `api/app/Admin/templates/catalog_*.php`, `tests/AdminCatalogHttpTest.php` |
| C — Public Storefront | Read-only Spanish storefront `GET /`, `/categoria/{slug}`, `/producto/{slug}`, `/buscar?q=`; visibility rules (active + non-archived + available in ≥1 active branch), display-only stored-price fallback order, escaped parameterized search, descendant category tree, `/health` envelope preserved | `api/app/Catalog/PublicCatalogController.php`, `api/app/Catalog/templates/*.php`, `public_html/index.php`, `public_html/router.php`, `public_html/assets/catalog.css`, `tests/PublicCatalogHttpTest.php` |
| Remediation pass | RequestId threading into `CatalogService` audits; `catalog.availability_changed` on 3 paths with old/new availability metadata; `item_images` metadata sync (`syncImages` + `saveItemImage`/`allImagesForItem`/`deactivateImagesExcept`); 2 new HTTP-level tests | `api/app/Catalog/CatalogService.php`, `api/app/Admin/CatalogAdminController.php`, `tests/AdminCatalogHttpTest.php` |

## Verification Journey (FAIL → remediation → PASS)

1. **Initial verify (2026-08-21): FAIL** — 15/17 requirements, 20/22 scenarios. Suite itself passed 55/55 and 40/40 live browser checks passed, but two scenarios were escalated to CRITICAL per the sdd-verify gate:
   - **C1**: every `catalog.*` audit row had `request_id` NULL — `CatalogService::log()` hardcoded the request id to `null` while `CatalogAdminController` held the live one; the spec's "with safe metadata and request context" MUST was unmet, and no permanent suite test asserted availability/archive audit rows.
   - **C2**: `item_images` had no runtime persistence coverage — only a table-existence assertion; no insert/read path or column-level assertion existed.
2. **Remediation**: requestId accepted by `CatalogService` (constructor arg) and supplied by `CatalogAdminController`; `catalog.availability_changed` emitted on item save, variant save, and branch catalog save with `old_available`/`new_available` (+`branch_id` where applicable) metadata; `syncImages` persists filename/alt/sort rows through the admin item form; 2 new HTTP tests: `testAuditRowsCarryRequestIdForPriceAvailabilityAndArchive` and `testItemImageMetadataPersistsReordersAndSurvivesArchive`.
3. **Re-verification (2026-08-26, orchestrator, firsthand evidence): PASS** — 17/17 requirements, 22/22 scenarios, 57/57 tests. Documented in `verification.md` § "Re-verification (post-remediation)" with file:line evidence. W2 (structural request-id gap) resolved by C1 threading; W3/S1/S2 remain non-blocking follow-ups below.

## Suite at Close

`D:\xampp\php\php.exe tools/run-tests.php` → **57 tests, 0 failures** (real MariaDB; all DB-backed assertions exercised live; zero skips).

## Infrastructure Hardening (same pass, per maintainer velocity decision)

- HTTP test servers now **probe port bindability before launch** — Windows excluded-port ranges had caused intermittent false failures.
- MariaDB unavailability now **fails DB tests loudly** instead of silently skipping (opt-out: `VO_SKIP_DB=1`).

## Gates Evaluated at Archive

- **Task Completion Gate**: PASS — 16/16 checked in archived `tasks.md`; no archive-time reconciliation performed or needed.
- **CRITICAL gate**: initial C1/C2 were resolved and re-verified with firsthand evidence (verification.md § Re-verification, final verdict PASS) — not a prompt-only assertion.
- **Review gate**: no native review governs this change (NO-COMMIT local mode; no review artifacts exist) — disabled/unmanaged relaxation.
- **Spec sync**: three NEW main specs created; no prior catalog domains existed, so delta specs (full-format) were copied byte-identical (hash-verified).

## Specs Synced (source of truth)

| Domain | Action | Details |
|---|---|---|
| `catalog-schema` | Created | 6 requirements, 7 scenarios (migration 003, modifiers, branch overrides, image metadata, permission seed + archive semantics, scope guard) |
| `catalog-admin` | Created | 6 requirements, 8 scenarios (authorization/CSRF/branch scope, CRUD without hard delete, validation/publish rules, branch config, audit, scope guard) |
| `catalog-public` | Created | 5 requirements, 7 scenarios (routes, visibility rules, display-only stored price, search/category browsing, scope guard) |

Main specs now at: `openspec/specs/{catalog-schema,catalog-admin,catalog-public}/spec.md`.

## Uncommitted Files Note

Per user instruction, the full Phase 4 implementation, its tests, the synced main specs, and this archive move are **working-tree only** (uncommitted since `0439b22`). Prior archives `2026-08-19-vo-auth/` and `2026-08-19-vo-installer/` are likewise uncommitted in the working tree. Git commit bookkeeping is deferred to the maintainer; git operations during this archive were read-only.

## Open Follow-ups (non-blocking)

1. Two-branch public visibility case (union visibility + inactive-branch exclusion) as a permanent suite test — proven only in verification probes so far.
2. HTTP-level assertions for Spanish validation error texts (variant-requirement, service-delivery) — currently source-level/`CatalogServiceTest` only.
3. Upload processing — deliberately deferred to a later phase; Phase 4 stores image metadata only.
4. MariaDB as a Windows service — user admin step still pending.

## Next Phase

**Phase 5 — Pricing.** Phase 4 shipped display-only stored prices (fallback order: branch variant override → variant price → branch item override → base price); Phase 5 introduces the real pricing engine.

## Traceability

- Filesystem audit trail: this archive folder (proposal.md, specs/ 3 domains, design.md, tasks.md, verification.md, archive-report.md).
- Engram observation IDs (project `pedix2`): explore #3224, proposal #3225, spec #3228, design #3230, tasks #3236, apply-progress #3238, re-verify PASS #3282, archive-report (this document, saved as `sdd/vo-catalog/archive-report`).
