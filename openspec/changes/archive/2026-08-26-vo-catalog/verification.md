```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:733d693810ac018db474eb136d64dac11acea229e9284bd4bbbda3e11b9fce63
verdict: fail
blockers: 0
critical_findings: 2
requirements: 15/17
scenarios: 20/22
test_command: D:\xampp\php\php.exe tools/run-tests.php
test_exit_code: 0
test_output_hash: sha256:733d693810ac018db474eb136d64dac11acea229e9284bd4bbbda3e11b9fce63
build_command: for %f in (13 changed vo-catalog files) do D:\xampp\php\php.exe -l %f
build_exit_code: 0
build_output_hash: sha256:c1df76875257c48eb07c875d50487218a2fd1ecdfe3faf050ceba2d3d7598962
```

# Verification Report

**Change**: vo-catalog
**Version**: N/A (no spec version declared)
**Mode**: Standard (Strict TDD inactive — `openspec/config.yaml` sets `strict_tdd: false`)
**Date**: 2026-08-21
**Verifier**: independent verify worker (fresh context), per `sdd-verify` skill contract

## Summary

All 16 tasks (A.1–A.6, B.1–B.5, C.1–C.5) are checked and real. Full suite passes 55/55 (exit 0, 902.6s, real MariaDB). A real-browser exercise (scratch DB `vo_cat_verify_<rand>`, migrations + InstallerSeeder + full catalog seed, built-in `php -S`) passed 40/40 intended checks: public visibility rules, descendant category expansion, display-price fallback order, escaped LIKE search (literal `%` inert), archived/unavailable 404s, `<script>` escaping, exact `/health` envelope, read-only guard, multi-branch union visibility, admin login/CSRF/audit spot checks.

**Two scenarios are only PARTIAL and are escalated to CRITICAL per the sdd-verify gate** ("spec scenario without a passing covering test at runtime is UNTESTED/CRITICAL"; project config allows no manual verification):

1. `catalog-admin` "Availability or archive is audited" — audit rows ARE written (verified live: `catalog.archived`, `catalog.branch_override_updated`), but no permanent suite test asserts them, and the requirement's MUST "with safe metadata and **request context**" is unmet at runtime: every `catalog.*` audit row has `request_id` NULL because `CatalogService::log()` hardcodes the request id to `null` (`api/app/Catalog/CatalogService.php:31`), even though `CatalogAdminController` holds the live request id and uses it only for `authz.denied`.
2. `catalog-schema` "Image metadata only" — table existence is suite-tested, but no runtime test (and no code path at all) exercises row persistence or column-level assertions for `item_images`.

Verdict: **FAIL** — remediation required before archive. The remediation is small and precisely scoped (see Remediation Checklist); all delivered behavior observed at runtime is correct.

## Completeness

| Metric | Value |
|--------|-------|
| Tasks total | 16 (A.1–A.6, B.1–B.5, C.1–C.5) |
| Tasks complete | 16 |
| Tasks incomplete | 0 |

All 16 checked tasks are real: every referenced file exists (`api/database/migrations/003_create_catalog.sql.php`, `api/app/Catalog/*`, `api/app/Admin/CatalogAdminController.php` + `templates/catalog_*.php`, `public_html/index.php`, `public_html/router.php`, `public_html/assets/catalog.css`, 4 test files) and the suite containing them passes 55/55 at runtime.

## Build & Tests Execution

**Build (php -l over 13 changed files)**: ✅ Passed — 13/13 "No syntax errors detected", exit 0
(Repository, Service, PublicCatalogController, CatalogAdminController, AdminController, index.php, router.php, migration 003, InstallerSeeder, 4 catalog tests).

**Tests**: ✅ 55 passed / 0 failed / 0 skipped — exit 0, 902.6s, real MariaDB
```text
D:\xampp\php\php.exe tools/run-tests.php
PASS Tests\AdminCatalogHttpTest::testPermissionDenyWritesAudit
PASS Tests\AdminCatalogHttpTest::testCategoryCrudCsrfAndValidation
PASS Tests\AdminCatalogHttpTest::testItemCreateVariantsModifierPriceAuditArchiveAndWrongMethod
PASS Tests\AdminCatalogHttpTest::testBranchConfigRequiresBranchScopeAndSavesOverride
PASS Tests\CatalogSchemaTest::testMigrationCreatesCatalogTablesConstraintsAndSeedsPermissionIdempotently
PASS Tests\CatalogServiceTest::testValidatesSlugVariantModifierAndServiceRulesWithRollback
PASS Tests\CatalogServiceTest::testArchiveHidesRowsSearchEscapesLikeAndPriceChangeAuditKeepsOldNewCents
PASS Tests\PublicCatalogHttpTest::testHomeCategoryDetailSearchAndHealth
... (47 baseline tests also PASS)
55 tests, 0 failures
```

**Coverage**: ➖ Not available (custom Composer-free runner, no coverage instrumentation — `openspec/config.yaml` `coverage.available: false`).

## Real Browsing Exercise (beyond unit tests)

Fresh scratch DB `vo_cat_verify_<rand>` (MariaDB 127.0.0.1; migrations 001+002+003 + `InstallerSeeder` + catalog seed: parent category `comidas` + child `pizzas`, product with 2 variants + modifier group + branch/variant price overrides, service item, archived item, branch-unavailable item, `<script>`-named item, `100%`-named item), built-in `php -S` + `public_html/router.php`. After every run: server killed, scratch DB dropped, `information_schema` verified 0 remaining `vo_cat_verify_%`, no stray `php.exe`. `vo_test` never touched.

**Public storefront — 27/27 checks PASS:**
- `GET /` → 200, Spanish (`Catálogo`), only available items listed; `Producto Archivado` (archived) and `Producto Sin Sucursal` (branch `is_available=0`) absent.
- `GET /categoria/comidas` → 200; items from BOTH parent-direct categories AND child category `pizzas` listed (recursive descendant expansion proven: `Pizza Especial` (child) + `Servicio Catering` (parent) both present); child category link present; hidden items absent.
- `GET /producto/pizza-especial` → 200; `Variantes` (`Grande`/`Chica`); display prices prove full fallback order: branch-variant override 900 → `$ 9,00` (beats variant price 1100) and branch-item override 1000 → `$ 10,00` (beats base 1200); modifier group `Extras &amp; Salsas` with `Picante &lt;suave&gt;` `+ $ 1,50`; no cart/checkout strings.
- `GET /buscar?q=pizza` → 200, matches only `Pizza Especial`.
- `GET /buscar?q=%25` (literal `%`) → 200, matches ONLY `Limonada 100%`; no crash, no unescaped match-all; `q=100%_bogus` → no matches (`%`/`_` inert as wildcards).
- `GET /producto/producto-archivado` → 404; `GET /producto/producto-sin-sucursal` → 404.
- `GET /health` → 200 with EXACT original envelope unchanged: `{"ok":true,"data":{"status":"ok","app":"Vender Online"},"request_id":"87e5a61cd03d5d74809191c71d242079"}`, `Content-Type: application/json; charset=utf-8`. git HEAD `index.php` routed all requests through `api/bootstrap/app.php`; new `index.php:14-24` keeps the identical handler chain (diff shows behavior-preserving refactor only).
- Escaping: `Empanada <script>alert(1)</script>` renders as `Empanada &lt;script&gt;…` — response contains `&lt;script&gt;` and NO raw `<script>alert`.
- Read-only guard: `POST /buscar` → 404, zero catalog state change.
- After admin base-price edit 1200→1350, public detail still shows `$ 10,00` (branch override still wins — display order intact).

**Admin spot — 5/5 PASS:** owner login → 302; `GET /admin/catalogo` → 200 Spanish; `POST /admin/categorias` WITHOUT CSRF → 419 with categories count unchanged (2→2); price edit via form WITH CSRF → 302 and `catalog.price_changed` audit row with full metadata `{"old_cents":1200,"new_cents":1350,"target_type":"catalog_item","target_id":1}`; `catalog.*` audit rows present after CRUD touch.

**Follow-up probes — 13/13 intended checks PASS** (one intermediate probe FAIL was a test-side confound — the branch form had legitimately made the item available in the first branch — corrected with a clean third probe):
- Archive via `POST /admin/producto/{id}/archivar` (CSRF) → 302; `audit_log` row `catalog.archived` written: `{"action":"catalog.archived","entity_type":"catalog_item","entity_id":1,"actor_type":"user","actor_id":1,"metadata_json":"[]","request_id":null}`; item then 404 publicly.
- Branch config form save → `branch_items` stored for that branch only (`is_available=1, price_override_cents=555, stock_mode=simple`); audit row `catalog.branch_override_updated`: `{"metadata_json":"{\"branch_id\":1}","request_id":null}`.
- Multi-branch union visibility (clean probe 3): item unavailable in `Centro` + available in active `Norte` → LISTED on `/` and detail 200; after deactivating `Norte` → hidden again. `EXISTS(active branch AND available)` semantics proven exactly.

## Spec Compliance Matrix

### catalog-schema (6 requirements / 7 scenarios) — 6/7 compliant

| Requirement | Scenario | Test / Evidence | Result |
|-------------|----------|------|--------|
| Migration 003 Catalog Tables | Catalog base schema exists | `CatalogSchemaTest::testMigrationCreatesCatalogTablesConstraintsAndSeedsPermissionIdempotently` PASS + exercise (001–003 applied on real MariaDB; duplicate slug rejected; variant FK rejected; InnoDB/utf8mb4 asserted) | ✅ COMPLIANT |
| Modifier Persistence | Modifier schema supports selection rules | `CatalogSchemaTest` (5 modifier tables exist) + `CatalogServiceTest` (min 3 > max 1 rejected) + `AdminCatalogHttpTest` (item↔group link stored; PK `(item_id,group_id)` prevents duplicates) + exercise (multi 0..2 group with +150 delta stored & rendered) | ✅ COMPLIANT |
| Branch Overrides | Branch-specific availability and prices | `AdminCatalogHttpTest::testBranchConfigRequiresBranchScopeAndSavesOverride` PASS (availability + override + stock_mode stored per branch, independent of master rows) + exercise (`branch_variants` override 900 displayed) | ✅ COMPLIANT |
| Item Image Metadata | Image metadata only | `CatalogSchemaTest` (item_images exists; `uploads` table absent) + source inspection (filename/sort/alt columns; NO writer/reader/upload endpoint exists) | ❌ PARTIAL → CRITICAL C2 — no runtime row-persistence path or column-level assertion |
| Permission Seed and Archive Semantics | Owner can receive catalog permission | `CatalogSchemaTest` (migration run twice idempotent; exactly 1 `products.manage` + 1 owner grant) | ✅ COMPLIANT |
| Permission Seed and Archive Semantics | Archived rows are hidden by default | `CatalogServiceTest` (archive → public reads 0 rows) + `PublicCatalogHttpTest` + exercises (archived absent on lists, 404 on detail) | ✅ COMPLIANT |
| Catalog Schema Scope Guard | Deferred tables remain absent | `CatalogSchemaTest` (carts/orders/promotions/payments/reservations/uploads/customers/deliveries asserted absent) | ✅ COMPLIANT |

### catalog-admin (6 requirements / 8 scenarios) — 7/8 compliant

| Requirement | Scenario | Test / Evidence | Result |
|-------------|----------|------|--------|
| Catalog Admin Authorization | Permission and CSRF required | `testPermissionDenyWritesAudit` (403 + `authz.denied` audit) + `testCategoryCrudCsrfAndValidation` (bad CSRF → 419, 0 rows written) + exercise (419, categories 2→2) | ✅ COMPLIANT |
| Catalog Admin Authorization | Branch scope required | `testBranchConfigRequiresBranchScopeAndSavesOverride` (unassigned branch → 403) | ✅ COMPLIANT |
| Catalog CRUD Without Hard Delete | Archive item | `testItemCreateVariantsModifierPriceAuditArchiveAndWrongMethod` (POST archivar → `archived_at` set; GET archivar → 404) + `CatalogServiceTest` (no hard-delete path exists in code) + exercise (`catalog.archived` row, public 404) | ✅ COMPLIANT |
| Admin Validation and Publish Rules | Variant requirement blocks activation | `CatalogServiceTest` (`requires_variant=1` without variants → `InvalidArgumentException`; Spanish message `El producto requiere al menos una variante activa.` in `CatalogService::saveItem`) | ✅ COMPLIANT |
| Admin Validation and Publish Rules | Service delivery is rejected | `CatalogServiceTest` (service + `allows_delivery=1` → exception; `Un servicio no puede habilitar envío.`) | ✅ COMPLIANT |
| Branch Catalog Configuration | Branch override saved | `testBranchConfigRequiresBranchScopeAndSavesOverride` (row stored for assigned branch only) + exercise (555/simple saved via form) | ✅ COMPLIANT |
| Catalog Admin Audit | Price change is audited | `CatalogServiceTest` (old 1200/new 1500) + `AdminCatalogHttpTest` HTTP path (old 1200/new 1800) + exercise (full metadata incl. `target_type`/`target_id`) | ✅ COMPLIANT |
| Catalog Admin Audit | Availability or archive is audited | Runtime rows verified live in this verification (`catalog.archived` with actor+entity+safe `[]` metadata; `catalog.branch_override_updated` with `{"branch_id":1}`) — but NO permanent suite test asserts these rows, and requirement MUST "with … request context" is unmet: `request_id` NULL on all `catalog.*` rows | ❌ PARTIAL → CRITICAL C1 |

### catalog-public (5 requirements / 7 scenarios) — 7/7 compliant

| Requirement | Scenario | Test / Evidence | Result |
|-------------|----------|------|--------|
| Public Catalog Routes | Public routes render safely | `PublicCatalogHttpTest::testHomeCategoryDetailSearchAndHealth` PASS + exercise (200s, Spanish, all dynamic output escaped incl. `&lt;script&gt;`) | ✅ COMPLIANT |
| Public Visibility Rules | Available in any active branch | Exercise probe 3 (pure union: unavailable in `Centro` + available in active `Norte` → listed, detail 200; `Norte` deactivated → hidden) + suite `EXISTS` structure | ✅ COMPLIANT |
| Public Visibility Rules | Archived or inactive data hidden | `PublicCatalogHttpTest` (`Oculto` inactive / `Archivado` / no-branch items absent + 404s) + exercises | ✅ COMPLIANT |
| Display-Only Stored Price | Stored price fallback | `PublicCatalogHttpTest` (`$ 9,00` branch-variant override beats variant 1100) + exercise (`$ 10,00` branch-item override beats base 1200; all 4 levels demonstrated; no totals/promotions anywhere in output or code) | ✅ COMPLIANT |
| Search and Category Browsing | Safe search | `PublicCatalogHttpTest` (`q=100%` matches only the %-named item) + exercise (literal `%`, inert `_`, parameterized binds, no crash) | ✅ COMPLIANT |
| Search and Category Browsing | Category tree browsing | `PublicCatalogHttpTest` (parent page shows child-category item) + exercise (parent + child items, child category link) | ✅ COMPLIANT |
| Public Catalog Scope Guard | Read-only storefront | `PublicCatalogHttpTest` (POST → 404, no state change) + exercise + source inspection (no commerce code path exists) | ✅ COMPLIANT |

**Compliance summary**: 20/22 scenarios COMPLIANT, 2/22 PARTIAL (escalated to CRITICAL), 0 FAILING, 0 UNTESTED. Per capability: catalog-schema 6/7, catalog-admin 7/8, catalog-public 7/7. Requirements complete: 15/17 (the two requirements owning the PARTIAL scenarios are not fully met).

## Correctness (Static Evidence)

| Requirement | Status | Notes |
|------------|--------|-------|
| schema: 9 tables, FKs, indexes, archive columns, seed | ✅ Implemented | Migration 003 matches design DDL; guarded dynamic-table allowlist in Repository (`TABLES` const) |
| schema: no business_id (single-tenant) | ✅ Implemented | No tenant column in any 003 table |
| admin: auth + `products.manage` + CSRF on ALL catalog actions | ✅ Implemented | `CatalogAdminController::handle` gates before routing; every POST path calls `requireCsrf()` |
| admin: branch scope via `user_branches` | ✅ Implemented | `requireBranch` on both GET and POST of branch config |
| admin: archive-not-delete | ✅ Implemented | Only `archive()` UPDATE … `archived_at`; no DELETE statement in catalog code |
| admin: Spanish server-side validation | ✅ Implemented | `CatalogService` throws Spanish messages; admin renders them escaped |
| admin: audit delegation | ⚠️ Partially implemented | All catalog actions call `AuditService`, but request id is not threaded (C1) |
| public: read-only GET-only routing | ✅ Implemented | `index.php:36` non-GET → 404 before DB access |
| public: escaping + security headers | ✅ Implemented | All templates use `Template::e`; `Content-Type: text/html; charset=utf-8` + CSP/nosniff/referrer/permissions headers |
| public: display resolver order | ✅ Implemented | `resolveDisplayPrice`: branchVariant → variant → branchItem → base; nullable, no arithmetic beyond formatting |

## Coherence (Design)

| Decision | Followed? | Notes |
|----------|-----------|-------|
| Work split A/B/C | ✅ Yes | Units independently verifiable; all tasks checked with focused + full commands |
| AdminController delegates catalog paths | ✅ Yes | `AdminController.php:45` + `isCatalogPath()` route table (line 117) |
| Pure display resolver, no PricingService | ✅ Yes | No `PricingService` exists anywhere; resolver is a read-only helper |
| Prepared LIKE with escaped wildcards | ✅ Yes | `escapeLike` + `ESCAPE '\\\\'` + bound params |
| Archive via `archived_at` | ✅ Yes | Confirmed in code and at runtime |
| `/health` preserved through bootstrap app | ✅ Yes | Same app/handler chain; envelope semantics verified live |
| Audit actions incl. `catalog.availability_changed` with old/new availability metadata | ⚠️ Deviation | `catalog.availability_changed` never implemented; branch availability logs `catalog.branch_override_updated` with `{"branch_id":<id>}` only — no `old_available`/`new_available` (W1) |

## Proposal Success Criteria

| Criterion | Evidence | Status |
|---|---|---|
| Migration 003 creates expected tables/constraints and seeds owner `products.manage` | `CatalogSchemaTest` PASS + exercise | ✅ Met |
| Admin catalog actions are permission/CSRF guarded and audited | `AdminCatalogHttpTest` 4/4 PASS + exercise (403/419/audit rows) | ⚠️ Met with caveat C1 (audit request context) |
| Public catalog lists only active, non-archived, available items; categories, detail, search | `PublicCatalogHttpTest` PASS + 40 exercise checks | ✅ Met |

## Issues Found

**CRITICAL**:
- **C1 — Availability/archive audit scenario lacks durable coverage and the "request context" MUST is unmet.** Spec `catalog-admin` / "Catalog Admin Audit" requires availability and archive audits "with safe metadata and request context". At runtime every `catalog.*` row (`catalog.archived`, `catalog.branch_override_updated`, `catalog.price_changed`) has `request_id` NULL: `CatalogService::log()` (`api/app/Catalog/CatalogService.php:31`) passes `null` as the request id, while `CatalogAdminController` holds the live `$this->requestId` and uses it only for `authz.denied` (`CatalogAdminController.php:81`). Additionally no suite test asserts the availability/archive audit rows (this verification proved them live only). Precedent: vo-auth verification treated unmet audit MUSTs as remediation-blocking.
- **C2 — `item_images` scenario has no runtime persistence coverage.** Spec `catalog-schema` / "Item Image Metadata" scenario ("WHEN image metadata is stored THEN filename, sort, and alt are persisted") is only covered by a table-existence assertion; no test (and no Phase 4 code path) inserts or reads an `item_images` row, and columns are not asserted at runtime. Consistent with deferred uploads, but the scenario is unproven as a durable test.

**WARNING**:
- **W1 — Designed audit action/metadata not implemented.** Design "Interfaces / Contracts" specifies `catalog.availability_changed` with `old_available`/`new_available`, branch and target IDs. Actual: `catalog.branch_override_updated` with `{"branch_id":<id>}` only (verified live).
- **W2 — Request-id threading gap is structural.** `CatalogService` accepts no request id at all; fixing C1 properly means threading it through the service constructor or per-call parameter (small, contained change).
- **W3 — Verified-manually-only behaviors.** Multi-branch union visibility and inactive-branch exclusion (proved in exercise probe 3) and the Spanish texts of variant/service validation errors (proved at source level) have no permanent suite assertions.

**SUGGESTION**:
- **S1** — Add a two-branch case to `PublicCatalogHttpTest` (union visibility + inactive-branch exclusion).
- **S2** — Add HTTP-level Spanish assertions for the variant-requirement and service-delivery validation errors.
- **S3** — Consider logging old/new availability values in `saveBranchCatalog` metadata when fixing C1/W1 in the same pass.

## Remediation Checklist (for sdd-apply remediation pass)

1. Thread request context into catalog audits: accept `?string $requestId` in `CatalogService` (constructor or per-call) and pass `CatalogAdminController::$this->requestId`; assert `request_id IS NOT NULL` on `catalog.*` rows in tests. (Fixes C1 runtime half.)
2. Add suite assertions: `catalog.archived` row after `POST /admin/producto/{id}/archivar`; `catalog.branch_override_updated` row after branch config save (with request id). (Fixes C1 coverage half.)
3. Add `item_images` INSERT + column read-back assertion to `CatalogSchemaTest` (filename/sort/alt persisted). (Fixes C2.)
4. Optional but recommended in same pass: old/new availability metadata (W1/S3) and two-branch public visibility test (S1).

## Verdict

**FAIL** — remediation required before archive.

Reason: 20/22 scenarios fully compliant and every delivered behavior verified correct at runtime (55/55 suite, 40/40 live checks), but 2 scenarios lack durable passing coverage and the `catalog-admin` audit requirement's "request context" MUST is observably unmet (`request_id` NULL on all catalog audit rows). Not archive-ready; remediation is small and precisely scoped above.

## Re-verification (post-remediation)

**Final verdict: PASS** — 17/17 requirements, 22/22 scenarios, 57/57 tests.

Evidence (verified directly by the orchestrator, 2026-08-26):

- **C1 resolved (request context)**: `CatalogService` now receives the real request id (constructor arg, `CatalogService.php:8`) and passes it to every `catalog.*` audit append (`CatalogService.php:32`); `CatalogAdminController` supplies its own request id (`CatalogAdminController.php:19`). HTTP-level test `testAuditRowsCarryRequestIdForPriceAvailabilityAndArchive` asserts non-null `request_id` on `catalog.price_changed`, `catalog.availability_changed`, and `catalog.archived` rows written through the real built-in server + scratch DB flow — PASS.
- **W1 resolved (availability audit)**: `catalog.availability_changed` emitted on three paths (item save, variant save, branch catalog save) with `old_available`/`new_available` (+`branch_id` where applicable) in metadata — covered by the same HTTP test.
- **C2 resolved (image metadata persistence)**: `syncImages` + repository methods (`saveItemImage`, `allImagesForItem`, `deactivateImagesExcept`) persist filename/alt/sort rows through the admin item form; HTTP-level test `testItemImageMetadataPersistsReordersAndSurvivesArchive` proves create/2 images → reorder/remove → archived item keeps rows — PASS.
- **Suite**: `D:\xampp\php\php.exe tools/run-tests.php` → **57 tests, 0 failures** (all DB-backed assertions exercised live against MariaDB; zero skips).
- **W2 resolved** by C1 threading; W3/SUGGESTION items remain non-blocking observations for future phases.

Infrastructure hardening applied in the same pass (per maintainer velocity decision): test HTTP servers now probe port bindability before launch (Windows excluded-port ranges caused intermittent false failures), and MariaDB unavailability now FAILS DB tests loudly instead of silently skipping (opt-out: `VO_SKIP_DB=1`).

## Exercise Hygiene

- Scratch DBs: all `vo_cat_verify_*` dropped (information_schema count 0 after each run); `vo_test` never touched.
- Servers: every spawned `php -S` terminated (process list verified clean).
- Git: read-only operations only (status/diff/show/log); no adds, commits, or pushes.
