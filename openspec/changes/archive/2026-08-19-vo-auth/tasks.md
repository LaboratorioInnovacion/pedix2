# Tasks: VO Auth

## Review Workload Forecast

| Field | Value |
|---|---|
| Estimated changed lines | Unit A 360-390; Unit B 300-340; Unit C 330-370; total 990-1100 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | Unit A -> Unit B -> Unit C |
| Delivery strategy | historical chained/stacked; CURRENT MODE = no-git local development |
| Chain strategy | stacked-to-main historically; no git operations now |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| A | Auth core + POST routing | PR 1 historical | `D:\xampp\php\php.exe tools/run-tests.php --filter AuthSessionTest,LoginRateLimitTest,CsrfMiddlewareTest,FoundationRuntimeTest` | HTTP POST route + scratch `vo_auth_test_*` DB | remove migration 002, `api/app/Auth/{AuthSession,LoginService,LockoutService,CsrfService}.php`, Request/Router/bootstrap deltas, A tests |
| B | RBAC + audit | PR 2 historical | `D:\xampp\php\php.exe tools/run-tests.php --filter PermissionGuardTest,AuditServiceTest` | guard allow/deny requests with request_id audit | remove `PermissionGuard`, `BranchScope`, `AuthorizationMiddleware`, `AuditService`, B tests |
| C | Admin shell | PR 3 historical | `D:\xampp\php\php.exe tools/run-tests.php --filter AdminHttpTest,InstallerHttpTest` | browser-like cookie jar login/dashboard/logout | remove `/admin`, AdminController/templates/CSS, `Support/Template` generalization while preserving installer wrapper |

## Unit A: Auth core

- [x] A.1 RED: create `tests/AuthSessionTest.php` for migration 002 tables, hardened cookie flags, regenerate, validate/refresh, expired/revoked rejection, logout idempotency; maps `auth-runtime` session/migration/logout scenarios.
- [x] A.2 RED: create `tests/LoginRateLimitTest.php` for success, generic failure, dummy hash path, five-failure lockout, success clears attempts, safe audit metadata; maps login/lockout/audit scenarios.
- [x] A.3 RED: create `tests/CsrfMiddlewareTest.php` and extend `tests/FoundationRuntimeTest.php` for POST dispatch, 405, form helper, JSON unchanged, valid/invalid CSRF; maps `foundation-runtime` and CSRF scenarios.
- [x] A.4 GREEN: add `api/database/migrations/002_create_auth_runtime.sql.php` with `auth_sessions` and `login_attempts` indexes; no baseline rewrite.
- [x] A.5 GREEN: add `api/app/Auth/{AuthSession,LockoutService,CsrfService,LoginService}.php` with PDO prepared statements, SHA-256 hashes, 30m idle, 12h absolute, generic errors.
- [x] A.6 GREEN: modify `api/app/Http/{Request,Router}.php` and `api/bootstrap/app.php` for method-aware GET/POST and form POST without admin HTML coupling.
- [x] A.7 VERIFY: run Unit A focused command, then `D:\xampp\php\php.exe tools/run-tests.php`; scope guard: no remember-me, password reset, operation/delivery/customer/business routes.

## Unit B: RBAC + audit

- [x] B.1 RED: create `tests/PermissionGuardTest.php` for permission allow/deny, global actions without branch, explicit branch assignment required/allowed, direct request denied; maps all `rbac-authorization` guard scenarios.
- [x] B.2 RED: create `tests/AuditServiceTest.php` for authz denial audit with request_id and safe metadata.
- [x] B.3 GREEN: add `api/app/Audit/AuditService.php` append-only wrapper for existing `audit_log`.
- [x] B.4 GREEN: add `api/app/Auth/{PermissionGuard,BranchScope,AuthorizationMiddleware}.php` using permission keys and `user_branches`; JSON/admin denial mapping.
- [x] B.5 VERIFY: run Unit B focused command, then full suite; scope guard: no user/role management UI or deferred business modules.

## Unit C: Admin shell

- [x] C.1 RED: create `tests/AdminHttpTest.php` for `/admin/` front controller, login token, generic invalid login, valid dashboard, logout revoke, redirect on revoked/expired, cookie jar scratch DB; maps `admin-shell` and `testing-bootstrap` scenarios.
- [x] C.2 GREEN: create `public_html/admin/index.php` and `api/app/Admin/AdminController.php` with GET login, POST login, GET dashboard, POST logout only.
- [x] C.3 GREEN: create `api/app/Support/Template.php`, adapt `api/app/Installer/Template.php` as compatible wrapper, keep installer tests green.
- [x] C.4 GREEN: add Spanish `api/app/Admin/templates/{layout,login,dashboard}.php` and `public_html/admin/assets/admin.css`; show only business name, user, placeholders.
- [x] C.5 VERIFY: run Unit C focused command, then full suite; scope guard: no catalog, orders, payments, delivery, customers, remember-me, reset, or role/user UI.
