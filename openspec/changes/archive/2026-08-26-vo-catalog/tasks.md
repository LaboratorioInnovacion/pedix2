# Tasks: VO Catalog

## Review Workload Forecast

| Field | Value |
|---|---|
| Estimated changed lines | A 430, B 390, C 385; total ~1205 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | Unit A -> Unit B -> Unit C |
| Delivery strategy | auto-chain; NO-COMMIT local mode |
| Chain strategy | stacked-to-main |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Focused test command | Runtime harness | Rollback boundary |
|---|---|---|---|---|
| A | Schema, repository, service | `D:\xampp\php\php.exe tools/run-tests.php --filter CatalogSchemaTest,CatalogServiceTest` | scratch DB `vo_cat_test_<rand>` via tests | remove migration 003, `api/app/Catalog/{CatalogRepository,CatalogService}.php`, A tests |
| B | Admin CRUD and guarded writes | `D:\xampp\php\php.exe tools/run-tests.php --filter AdminCatalogHttpTest` | built-in PHP server admin flow | remove admin delegation/controller/templates and B test |
| C | Public read-only storefront | `D:\xampp\php\php.exe tools/run-tests.php --filter PublicCatalogHttpTest` | unauthenticated public routes on built-in PHP server | revert `public_html/index.php`, remove public controller/templates/CSS/test |

## Unit A: Migration 003 + Catalog Core

- [x] A.1 RED `tests/CatalogSchemaTest.php`: prove 001+002+003 creates catalog tables/FKs/indexes, idempotent `products.manage`, and no deferred tables.
- [x] A.2 Create `api/database/migrations/003_create_catalog.sql.php` with 9 tables, archive columns, branch overrides, image metadata, owner seed.
- [x] A.3 RED `tests/CatalogServiceTest.php`: validation, archive filters, prepared LIKE escaping, variant/service rules, audit metadata.
- [x] A.4 Create `api/app/Catalog/CatalogRepository.php` with prepared admin/public CRUD/read methods and category descendant expansion support for V1.
- [x] A.5 Create `api/app/Catalog/CatalogService.php` with transactions, validation, archive-not-delete, stored-price resolver helpers, audits.
- [x] A.6 Verify Unit A plus baseline: focused command above, then `D:\xampp\php\php.exe tools/run-tests.php`.

## Unit B: Admin Catalog

- [x] B.1 RED `tests/AdminCatalogHttpTest.php`: permission deny, CSRF 419 no state change, wrong-method routing, branch 403, CRUD/archive/audit scenarios.
- [x] B.2 Modify `api/app/Admin/AdminController.php` to delegate `/admin/catalogo*`, `/admin/categorias*`, `/admin/productos*`, `/admin/sucursales/{id}/catalogo`.
- [x] B.3 Create `api/app/Admin/CatalogAdminController.php` with auth, `products.manage`, branch scope, CSRF, POST allowlist, audit delegation.
- [x] B.4 Create `api/app/Admin/templates/catalog_*.php` Spanish escaped list/form/branch screens; no cart, checkout, uploads, pricing engine, or stock movements.
- [x] B.5 Verify Unit B plus baseline with focused command above, then full test command.

## Unit C: Public Catalog

- [x] C.1 RED `tests/PublicCatalogHttpTest.php`: safe routes, visibility, stored-price fallback, escaped detail, safe search, descendant category tree, read-only guard.
- [x] C.2 Modify `public_html/index.php` for `/`, `/categoria/{slug}`, `/producto/{slug}`, `/buscar?q=`, preserving `/health` JSON.
- [x] C.3 Create `api/app/Catalog/PublicCatalogController.php` with read-only handlers, descendant category filtering, escaped data, display-only prices.
- [x] C.4 Create `api/app/Catalog/templates/*.php` and `public_html/assets/catalog.css` for Spanish storefront/category/detail/search views.
- [x] C.5 Verify Unit C plus baseline with focused command above, then full test command.
