# Tasks: VO Installer

## Review Workload Forecast

| Field | Value |
|---|---|
| Estimated changed lines | Unit A 360-390; Unit B 360-400; total 720-790 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 Unit A -> PR 2 Unit B |
| Delivery strategy | ask-on-risk, already resolved for this engagement |
| Chain strategy | stacked-to-main |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| A | Baseline schema, config overlay, seed services, scratch DB tests | PR 1 | `D:\xampp\php\php.exe tools/run-tests.php --filter ConfigOverlayTest,BaselineSchemaTest,InstallerSeederTest` | Scratch MariaDB DB create/drop on 127.0.0.1:3306; no `vo_test` writes | Revert Unit A files and generated scratch DB/config only |
| B | Web wizard, session/CSRF, HTTP harness, lock behavior | PR 2 | `D:\xampp\php\php.exe tools/run-tests.php --filter InstallerHttpTest` | PHP built-in server against `public_html` with isolated cookies/scratch paths | Revert Unit B files; Unit A remains usable |

## Unit A: schema + overlay + seeds + tests

- [x] A.1 Create `tests/BaselineSchemaTest.php` RED coverage for schema-baseline scenarios: fresh migration, constraints, rerun, deferred tables absent.
- [x] A.2 Create `api/database/migrations/001_create_baseline.sql.php` with only the 11 baseline tables, InnoDB/utf8mb4, FKs, uniqueness, timestamps; no catalog/orders/payments/delivery/auth-session tables.
- [x] A.3 Create `tests/ConfigOverlayTest.php` RED coverage for foundation-runtime ADDED scenarios: overlay present, overlay missing, runtime remains minimal.
- [x] A.4 Modify `api/app/Config/Config.php` and create `api/app/Installer/InstalledConfig.php` for private overlay merge, lock path, app key, atomic temp-file + `rename()`, no overwrite except test paths.
- [x] A.5 Create `tests/InstallerSeederTest.php` RED coverage for owner permissions, hash-only admin password, retry guards, audit row, and testing-bootstrap scratch DB isolation/test paths.
- [x] A.6 Create `api/app/Installer/InstallerSeeder.php` with transactional guarded seeds for business, branch, settings, owner role, permissions, user roles/branches, and audit metadata.
- [x] A.7 Verify Unit A with `D:\xampp\php\php.exe tools/run-tests.php --filter ConfigOverlayTest,BaselineSchemaTest,InstallerSeederTest`; acceptance: all schema-baseline, foundation-runtime ADDED, and related testing-bootstrap ADDED scenarios pass.

Commit boundary: `feat(installer): add baseline schema and seed foundation`.

## Unit B: wizard + session/CSRF + HTTP tests + lock

- [x] B.1 Modify `tools/run-tests.php` for HTTP server helpers, random-port lifecycle, scratch config/storage/DB env, cookie isolation, and Windows child cleanup.
- [x] B.2 Create `tests/InstallerHttpTest.php` RED coverage for wizard order, requirement block, bad DB sanitization, missing CSRF, success lock-last, partial failure rerun, installed lock before DB, and deferred feature absence.
- [x] B.3 Create `api/app/Installer/InstallerSession.php`, `RequirementsChecker.php`, and `Template.php` for secure session flags, CSRF `hash_equals`, requirements checks, escaping, and secret clearing.
- [x] B.4 Create `api/app/Installer/InstallerController.php` to orchestrate steps, validate initial data, call Unit A migrations/seeder/config writer, sanitize errors, and clear secrets.
- [x] B.5 Create `public_html/install/index.php`, `public_html/install/assets/install.css`, and templates `requirements.php`, `database.php`, `details.php`, `review.php`, `done.php`, `locked.php`, `error.php`.
- [x] B.6 Verify Unit B with `D:\xampp\php\php.exe tools/run-tests.php --filter InstallerHttpTest`, then full `D:\xampp\php\php.exe tools/run-tests.php`; acceptance: all web-installer scenarios and remaining testing-bootstrap ADDED scenarios pass.

Commit boundary: `feat(installer): add locked web installer wizard`.

## Scope Guard

Do not implement runtime login/auth endpoints, admin panel, catalog, orders, payments, delivery, customers, updater, backups, workers, modules, production Apache rewrites, or business features beyond installer provisioning.
