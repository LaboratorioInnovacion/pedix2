```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:a9f8f854fad8819fba5a69726624d0e27aa699717ba7e609c9d7462b92c6949b
verdict: pass
blockers: 0
critical_findings: 0
requirements: 25/25
scenarios: 36/36
test_command: D:\xampp\php\php.exe tools/run-tests.php
test_exit_code: 0
test_output_hash: sha256:1819f8cc99f59c8c1d53f4ba18bec825e4a78218d8bb864167ba2eceea411ff9
build_command: none (PHP interpreted stack; no build step)
build_exit_code: 0
build_output_hash: sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
```

> Final state after remediation — see [Re-verification (post-remediation)](#re-verification-post-remediation) at the end of this report. All sections between the envelope and that section are the original verification record and are preserved unmodified as history.

# Verification Report

**Change**: vo-auth
**Version**: N/A
**Mode**: Standard (Strict TDD inactive — no TDD runner configured)
**Date**: 2026-08-19
**Verifier**: independent verify worker (fresh context), per `sdd-verify` skill contract

## Summary

All 17 planned tasks (A.1–A.7, B.1–B.5, C.1–C.5) are checked and real. The full suite passes 46/46 with exit 0. A real-server admin exercise (PHP built-in server + `public_html/router.php` + scratch MariaDB `vo_auth_test_*` seeded with migrations 001+002 and `InstallerSeeder`) confirmed the Spanish login form, generic failures, identifier+IP lockout, cross-identifier login, CSRF-protected logout, session revocation, and hash-only persistence.

**One CRITICAL spec violation found at runtime**: the `auth-runtime` requirement "Auth Audit Events" is not satisfied by the delivered system. The admin shell wires a no-op audit sink and a `null` request id into `LoginService`, and no `auth.logout` audit emission exists anywhere; after a full login/lockout/logout exercise, `audit_log` contains only the installer row (`installer.completed`) — zero rows for `auth.login_failed`, `auth.lockout`, `auth.login_success`, `auth.logout`. Unit tests masked this by asserting against an in-memory spy closure instead of `audit_log`. Verdict: **FAIL** (remediation required before archive).

## Completeness

| Metric | Value |
|--------|-------|
| Tasks total | 17 |
| Tasks complete | 17 |
| Tasks incomplete | 0 |

## Build & Tests Execution

**Build**: ➖ Not applicable (PHP interpreted stack; no build step). Recorded as exit 0 with SHA-256 of empty output.

**Tests**: ✅ 46 passed / ❌ 0 failed / ⚠️ 0 skipped (DB-backed tests skip gracefully only if MariaDB is down; it was up)

```text
Command: D:\xampp\php\php.exe tools/run-tests.php
Exit code: 0
Output tail:
PASS Tests\StateMachineTest::testTransitionsAndHistoryHook
PASS Tests\StateMachineTest::testInvalidTransitionThrows
46 tests, 0 failures
Output SHA-256: b437006bfd1b638e3c089f245e1eb23ea3c3f927638383f03ce3315851d30609
```

Relevant vo-auth tests in the passing suite: `AdminHttpTest` (3), `AuditServiceTest` (2), `AuthSessionTest` (4), `CsrfMiddlewareTest` (1), `FoundationRuntimeTest` (4), `LoginRateLimitTest` (3), `PermissionGuardTest` (4) = 21 tests directly covering this change; the remaining 25 guard prior capabilities (installer, baseline, primitives).

**Coverage**: ➖ Not available (custom Composer-free runner, no coverage instrumentation).

## Runtime Admin Exercise (real server, real database)

Harness: scratch DB `vo_auth_test_b1cbd08443` (created/dropped; `vo_test` never touched), migrations 001+002 via `MigrationRunner`, `InstallerSeeder` (business "Demo Store", user `owner@example.test`), second admin user `manager@example.test` added for the different-identifier login; `php -S 127.0.0.1:28077 -t public_html public_html/router.php` with `VO_INSTALLED_CONFIG_PATH` lock; reusable cookie jar; redirects not followed (statuses observed directly).

```text
[SETUP] scratch DB `vo_auth_test_b1cbd08443` migrated (001+002) + InstallerSeeder + manager@example.test
[SETUP] php -S started on 127.0.0.1:28077 with public_html/router.php
[E1] GET /admin/login -> 200; form=SPANISH-OK; csrf=PRESENT(64 hex)
[E1] Set-Cookie: vo_admin=...; path=/; HttpOnly; SameSite=Lax
[E1] Set-Cookie: vo_csrf=...; path=/admin; HttpOnly; SameSite=Lax
[E2] POST /admin/login unknown-user -> 200; generic_error=YES; plaintext_leak=NO
[E3] bad-login#1..#5 (owner/wrong, mixed-case id) -> 200; generic=YES   (x5)
[E3] 6th attempt CORRECT password while locked -> 200; blocked_generically=YES
[E3] login_attempts: failures=6 successes=0   (5 owner + 1 unknown identifier; lockout hit at 5 for owner)
[E4] POST /admin/login manager@example.test -> 302; Location=/admin/
[E4] unrevoked auth_sessions rows: 1
[E5] GET /admin/ -> 200; business=SHOWN; user=SHOWN; placeholders=SHOWN
[E6] POST /admin/logout no-csrf -> 419 (expect 419)
[E6] session still valid after rejected logout: GET /admin/ -> 200 (expect 200); revoked_rows=0 (expect 0)
[E7] POST /admin/logout with-csrf -> 302; Location=/admin/login
[E7] GET /admin/ after logout -> 302; Location=/admin/login (expect 302 /admin/login); revoked_rows=1 (expect 1)
[E8] action summary: [{"action":"installer.completed","c":1}]
[E8] audit action `auth.login_failed`: 0 rows   <- SPEC VIOLATION
[E8] audit action `auth.lockout`: 0 rows        <- SPEC VIOLATION
[E8] audit action `auth.login_success`: 0 rows  <- SPEC VIOLATION
[E8] audit action `auth.logout`: 0 rows         <- SPEC VIOLATION
[E8] audit plaintext scan "owner@example.test"/"manager@example.test"/"ghost@example.test"/"Password123"/"Password456"/"bad-pass": all clean
[E8] auth_sessions sid_hash all 64-hex (no raw sid): YES (rows=1)
[E8] login_attempts hashes 64-hex sample: 6/6
[CLEANUP] php -S terminated
[CLEANUP] dropped `vo_auth_test_b1cbd08443`
```

Notes: mixed-case identifier ` OWNER@example.test ` locked out the normalized `owner@example.test` account (normalization works); the different-identifier login (`manager@example.test`, same IP) succeeded while owner was locked (lockout is per identifier+IP, not per IP). Post-run environment check: `vo_auth_test_*` databases: 0 (one stale DB `vo_auth_test_9bde661f9e` left by an earlier timed-out runner invocation was dropped manually), `php -S` processes: 0, `vo_test` untouched.

## Spec Compliance Matrix

Legend: ✅ COMPLIANT (covering test passed at runtime) · ⚠️ PARTIAL (passing test covers only part of the scenario) · ❌ FAILING / UNTESTED

### auth-runtime (9 requirements / 12 scenarios) — 7 PASS, 1 PARTIAL, 1 FAIL

| Requirement | Scenario | Test / Evidence | Result |
|---|---|---|---|
| Admin Login Boundary | Successful login | `AdminHttpTest::testLoginDashboardAndLogoutFlow` + exercise E4 (302 → dashboard) | ✅ COMPLIANT |
| Admin Login Boundary | Generic failed login | `LoginRateLimitTest::testLoginSuccessAndGenericFailuresUseSameMessage` (unknown/inactive/wrong identical) + E2/E3 no leak | ✅ COMPLIANT |
| Session Creation | New authenticated session | `AuthSessionTest::testHardenedCookieFlagsAndRegeneratedLoginSession` + E4 unrevoked row, `session_regenerate_id(true)` in `AuthSession::createForLogin` | ✅ COMPLIANT |
| Session Cookie Policy | HTTPS login cookie | `AuthSessionTest` asserts `httponly`+`secure`+`Lax` for `cookieParams(true)`; HTTP run correctly omits `Secure` (E1) | ✅ COMPLIANT |
| Per-Request Session Validation | Valid session refresh | `AuthSessionTest::testValidateRefreshesLastSeenAndRejectsExpiredOrRevoked` + E5/E6 dashboards 200 | ✅ COMPLIANT |
| Per-Request Session Validation | Expired or revoked session | same test (absolute-expiry and revoked → null) + E7 post-logout 302 | ✅ COMPLIANT |
| Logout Revocation | Repeated logout | `AuthSessionTest::testLogoutIsIdempotent` + E7 `revoked_rows=1` (COALESCE keeps first timestamp) | ✅ COMPLIANT |
| Login Lockout | Lockout trigger | blocking half proven (`LoginRateLimitTest::testDummyHashPathAndLockoutBlocksGenerically` + E3 6th attempt blocked generically), but "a lockout audit event is recorded" FAILS at runtime — E8 shows 0 `auth.lockout` rows (unit test only asserts against an in-memory spy) | ⚠️ PARTIAL |
| Login Lockout | Success clears attempts | `LoginRateLimitTest::testSuccessClearsAttemptsAndAuditMetadataIsSafe` (failure rows → 0 after success) | ✅ COMPLIANT |
| Auth Audit Events | Failed login audit safety | service emits safe events (identifier_hash 64-hex, request_id passthrough, no plaintext — `LoginRateLimitTest`, `AuditServiceTest`), but the runtime path writes NOTHING to `audit_log` (E8: 0 `auth.login_failed` rows; admin passes no-op sink and `null` request id); also no `auth.logout` emission exists anywhere | ⚠️ PARTIAL → requirement FAIL |
| Auth Runtime Migration 002 | Tables created | `AuthSessionTest::testMigration002CreatesAuthTablesAndIndexes` + exercise setup ran 001+002 on scratch DB | ✅ COMPLIANT |
| Auth Scope Guard | Deferred auth features absent | `AdminController::handle()` exposes only GET/POST login, GET dashboard, POST logout; no remember-me/reset/operation-login code in repo (`ScopeGuardTest` green); no dedicated negative HTTP probe | ✅ COMPLIANT (static + suite) |

### rbac-authorization (6 requirements / 9 scenarios) — 6 PASS

| Requirement | Scenario | Test / Evidence | Result |
|---|---|---|---|
| Permission Guard | Permission allowed | `PermissionGuardTest::testPermissionAllowedAndDeniedByRolePermissionChain` (owner has `payments.verify_transfer`) | ✅ COMPLIANT |
| Permission Guard | Permission denied | same test (limited user denied) + `testDirectDeniedRequestReturnsJson403AndAuditWithRequestId` → 403 JSON + `authz.denied` audit row with `request_id='req-denied'` | ✅ COMPLIANT |
| Branch Scope Guard | Explicit branch assignment required | `testOperationalActionsRequireExplicitBranchAssignment` (no `user_branches` row → deny) | ✅ COMPLIANT |
| Branch Scope Guard | Assigned branch allowed | same test (insert row → allow; middleware 200 with branch) | ✅ COMPLIANT |
| Global Action Semantics | Global permission path | `testGlobalActionsRequirePermissionOnlyWithoutBranch` (`users.manage`, `settings.manage`, `reports.view` with no branch → 200) | ✅ COMPLIANT |
| Server-Side Enforcement | Direct request denied | `testDirectDeniedRequestReturnsJson403AndAuditWithRequestId` calls the server-side middleware directly → 403 | ✅ COMPLIANT |
| CSRF Middleware | Valid authenticated POST | `CsrfMiddlewareTest` (hash_equals accept) + `AdminHttpTest::testLoginDashboardAndLogoutFlow` (valid token POST proceeds) + E7 | ✅ COMPLIANT |
| CSRF Middleware | Invalid authenticated POST | `AdminHttpTest::testCsrfRejectedLogoutKeepsSessionActive` (419, zero state change: 0 revoked rows, dashboard still 200) + E6 | ✅ COMPLIANT |
| Authorization Scope Guard | Deferred guarded modules absent | no business endpoints exist to guard; `AuthorizationMiddleware` not wired to any deferred module; static + `ScopeGuardTest` | ✅ COMPLIANT (static + suite) |

### admin-shell (6 requirements / 8 scenarios) — 6 PASS

| Requirement | Scenario | Test / Evidence | Result |
|---|---|---|---|
| Admin Front Controller | Admin entry served | `public_html/admin/index.php` via `public_html/router.php`; private code outside `public_html` (`FoundationRuntimeTest::testPrivatePublicLayoutExists`); real-server E1–E7 | ✅ COMPLIANT |
| Login Form CSRF | Login form contains token | E1 (200, "Ingresar al panel", 64-hex token) + `AdminHttpTest::testCsrfRejectedLoginDoesNotAuthenticate` (token required) | ✅ COMPLIANT |
| Login Submission | Valid login reaches dashboard | `AdminHttpTest::testLoginDashboardAndLogoutFlow` + E4 (302 `Location=/admin/`) | ✅ COMPLIANT |
| Login Submission | Invalid login remains generic | `AdminHttpTest` (generic message, no enumeration) + E2/E3 | ✅ COMPLIANT |
| Protected Dashboard Placeholder | Dashboard shell | E5 (business "Demo Store", user "Encargado", placeholder sections only) + `AdminHttpTest` | ✅ COMPLIANT |
| Protected Dashboard Placeholder | Unauthenticated dashboard redirect | `AdminHttpTest` first assertion + E7 (`302 Location=/admin/login`) | ✅ COMPLIANT |
| Logout Form | Logout succeeds | E7 (revoke + redirect; subsequent dashboard access redirects) + `AdminHttpTest` | ✅ COMPLIANT |
| Admin Shell Scope Guard | Business features absent | admin shell renders only login/dashboard/logout ("Módulo pendiente" placeholders); no catalog/orders/payments/delivery/customer/user-management routes; static + `ScopeGuardTest` | ✅ COMPLIANT (static + suite) |

### foundation-runtime (2 requirements / 4 scenarios) — 2 PASS

| Requirement | Scenario | Test / Evidence | Result |
|---|---|---|---|
| Method-Aware Routing | POST route dispatch | `FoundationRuntimeTest::testPostDispatchWrongMethodAndFormHelper` (POST handler executes) | ✅ COMPLIANT |
| Method-Aware Routing | Wrong method rejected | same test (GET on POST-only path → 405) + `HttpSmokeTest::testNotFoundAndMethodNotAllowed` (`POST /health` → 405) | ✅ COMPLIANT |
| Form POST Body Parsing | Form body available | same `FoundationRuntimeTest` test (`$request->post('name')` returns submitted field) | ✅ COMPLIANT |
| Form POST Body Parsing | JSON boundary unchanged | `FoundationRuntimeTest::testJsonGetBehaviorUnchanged` + `HttpSmokeTest::testHealthRouteReturnsSafeJsonAndHeaders` (status/headers/body unchanged) | ✅ COMPLIANT |

### testing-bootstrap (2 requirements / 3 scenarios) — 2 PASS

| Requirement | Scenario | Test / Evidence | Result |
|---|---|---|---|
| Authenticated HTTP Test Flows | Cookie jar persists login | `tools/run-tests.php` `InstallerTestServer` cookie jar reused across login → dashboard (`AdminHttpTest::testLoginDashboardAndLogoutFlow`) + independent exercise jar | ✅ COMPLIANT |
| Authenticated HTTP Test Flows | Logout clears effective auth | `AdminHttpTest` (post-logout `/admin/` → 302) + E7 | ✅ COMPLIANT |
| Auth Scratch DB Tables | Scratch auth schema lifecycle | `AuthScratchDatabase`/`AuthHttpScratchDatabase` create `vo_auth_test_<rand>` per run, drop in `finally`; only reference to `vo_test` in tests is a negative assertion (`InstallerSeederTest.php:37`) | ✅ COMPLIANT |

**Compliance summary**: 34/36 scenarios fully compliant, 2 PARTIAL, 0 UNTESTED, 0 FAILING-tests. Requirement totals: 23 PASS, 1 PARTIAL (Login Lockout — audit clause), 1 FAIL (Auth Audit Events — runtime persistence absent).

## Correctness (Static Evidence)

| Area | Status | Notes |
|---|---|---|
| Migration 002 DDL | ✅ Implemented | matches design sketch: `auth_sessions` (unique `sid_hash`, user/revoked, absolute expiry, last_seen indexes, FK users) and `login_attempts` (lookup + stale-cleanup indexes), InnoDB utf8mb4, additive only |
| Password handling | ✅ Implemented | `password_verify()` + constant dummy-hash path when user missing (`LoginService.php:28-29`) |
| Hash-only persistence | ✅ Implemented | `auth_sessions.sid_hash`, `login_attempts.identifier_hash/ip_hash` all SHA-256; E8 confirms 64-hex only, no plaintext anywhere |
| Session hardening | ✅ Implemented | `session_regenerate_id(true)` on login; 30m idle / 12h absolute; HttpOnly+SameSite=Lax(+Secure on HTTPS) cookies |
| CSRF | ✅ Implemented | 32-byte hex per-session token, `hash_equals` validation, 419 rejection without state change |
| RBAC | ✅ Implemented | permission-key chain via `user_roles → role_permissions → permissions`; explicit `user_branches`; 403 JSON + `authz.denied` audit with request_id (`AuthorizationMiddleware` defaults to real `AuditService`) |
| Admin shell | ✅ Implemented | 4 routes only; Spanish templates escaped via `Template::e` (htmlspecialchars ENT_QUOTES); shared renderer with installer wrapper (`api/app/Installer/Template.php` delegates to `VO\Support\Template`) |

## Coherence (Design)

| Decision | Followed? | Notes |
|---|---|---|
| Native PHP session + DB `auth_sessions` validation | ✅ Yes | `AuthSession` validates/revokes per request |
| Dedicated `public_html/admin/index.php` HTML front controller | ✅ Yes | installer-style front controller, private code outside public root |
| Shared `api/app/Support/Template` with installer wrapper | ✅ Yes | installer tests stayed green (5/5) |
| Guard by permission key + explicit `user_branches` | ✅ Yes | no role-name checks, no magic scope |
| Data flow: login records attempt/audit | ❌ No | audit is wired to a no-op sink in the admin controller — the CRITICAL finding |
| Data flow: protected admin = `request_id -> security headers -> session -> ...` | ❌ No | admin pipeline has no request id and sets no security headers (JSON pipeline has both via `RequestIdMiddleware`/`SecurityHeadersMiddleware`) |
| Additive migration 002, no baseline rewrite | ✅ Yes | baseline tests green |

## Issues Found

**CRITICAL**:

1. **Auth audit events are never persisted at runtime** — spec `auth-runtime` "Auth Audit Events" MUST audit login success, login failure, lockout trigger, and logout with request_id. Real-server evidence (E8): after 1 unknown-user failure, 5 wrong-password failures, 1 lockout block, 1 successful login, and 1 logout, `audit_log` contains ONLY `installer.completed` (0 rows for all four `auth.*` actions). Root causes:
   - `api/app/Admin/AdminController.php:52` — `$audit = fn (array $e) => null;` passes a no-op sink into `LoginService`.
   - `api/app/Admin/AdminController.php:53` — `$requestId` argument is `null`; the admin pipeline never generates a request id.
   - No `auth.logout` audit emission exists anywhere (`AuthSession::logout()` and `AdminController::logout()` write nothing; grep confirms no `auth.logout` in production code).
   - Test blind spot: `tests/LoginRateLimitTest.php` asserts audit via an in-memory spy closure, never against `audit_log`. Contrast: `AuthorizationMiddleware` correctly defaults to a real `AuditService` and its test verifies the DB row.
   - Remediation sketch: construct `AuditService` in `AdminController`, pass `[$auditService, 'append']`-style closure (mapping LoginService event shape), generate/propagate a request id in the admin pipeline, emit `auth.logout` in `AdminController::logout()`, and add an HTTP-level test asserting the `audit_log` rows.

**WARNING**:

1. **Admin pipeline deviates from design data flow** (`openspec/changes/vo-auth/design.md` "Data Flow"): protected admin requests were designed to pass `request_id -> security headers -> session -> middleware -> route`; the delivered `AdminController` has no request id and sends no security headers (no CSP / `X-Content-Type-Options` / `Referrer-Policy` on admin HTML responses; those exist only in the JSON pipeline). Partially overlaps CRITICAL 1 (request id) but the security-headers gap is independent.
2. **Lockout-trigger scenario audit clause unmet at runtime** (same root cause as CRITICAL 1): "login is blocked generically AND a lockout audit event is recorded" — blocking works (E3), recording does not (E8). Scenario remains PARTIAL until CRITICAL 1 is fixed.

**SUGGESTION**:

1. Add negative HTTP probes for the three scope-guard scenarios (e.g., `GET /admin/password-reset`, `GET /admin/users`, `GET /admin/catalog` → 404 "No encontrado") so the "deferred features absent" scenarios are runtime-proven instead of inspection-proven.
2. `AdminController::validCsrf()` (api/app/Admin/AdminController.php:104) accepts a cookie-equality fallback (`vo_csrf` cookie vs submitted token) in addition to the per-session `hash_equals` check; consider session-only validation to keep a single CSRF verification mechanism.

## Task Status

| Task | Checked | Real evidence |
|---|---|---|
| A.1 AuthSessionTest (RED) | ✅ | 4 tests passed in full suite |
| A.2 LoginRateLimitTest (RED) | ✅ | 3 tests passed |
| A.3 CsrfMiddlewareTest + FoundationRuntimeTest ext. (RED) | ✅ | 1 + 4 tests passed |
| A.4 Migration 002 | ✅ | tables + indexes verified live on scratch DB |
| A.5 Auth classes | ✅ | all behaviors exercised at runtime (E1–E7) |
| A.6 Request/Router/bootstrap deltas | ✅ | POST dispatch, 405, form helper, JSON unchanged all green |
| A.7 Unit A verify + scope guard | ✅ | full suite 46/46 re-run today; no deferred routes |
| B.1 PermissionGuardTest (RED) | ✅ | 4 tests passed |
| B.2 AuditServiceTest (RED) | ✅ | 2 tests passed |
| B.3 AuditService | ✅ | append-only wrapper verified against `audit_log` |
| B.4 Guard classes | ✅ | allow/deny/global/branch/403+audit verified |
| B.5 Unit B verify + scope guard | ✅ | full suite green; no user/role UI |
| C.1 AdminHttpTest (RED) | ✅ | 3 tests passed |
| C.2 admin front controller + AdminController | ✅ | served real traffic E1–E7 |
| C.3 Support/Template + installer wrapper | ✅ | wrapper delegates to shared renderer; installer tests 5/5 |
| C.4 Spanish templates + admin.css | ✅ | rendered at runtime; `public_html/admin/assets/admin.css` exists |
| C.5 Unit C verify + scope guard | ✅ | full suite green; shell has placeholders only |

## Conclusion

**Verdict: FAIL**

The vo-auth implementation is functionally strong — 46/46 tests pass and the real-server exercise confirms the Spanish login boundary, generic errors, lockout, CSRF, revocation, and hash-only persistence — but one MUST requirement (`auth-runtime` / Auth Audit Events) is violated by the delivered runtime: no login/lockout/logout audit rows are ever written because the admin controller discards audit events and no request id exists in the admin pipeline. Next phase: **remediation** (wire `AuditService` + request id into the admin path, emit `auth.logout`, add an HTTP-level audit test), then re-verify. Do NOT archive yet.

---

## Re-verification (post-remediation)

**Date**: 2026-08-19
**Verifier**: focused re-verification worker (fresh context), per `sdd-verify` skill contract
**Scope**: the 1 CRITICAL + 2 WARNING from the original report only. All other dimensions retain their prior verdicts (17/17 tasks real, runtime security behaviors E1–E7 unchanged and re-confirmed green below).

### Remediation code review (delta since original verification)

| File / line | Change | Finding addressed |
|---|---|---|
| `api/app/Admin/AdminController.php:27` | constructs real `AuditService($this->pdo)` (was no-op sink) | CRITICAL 1 |
| `api/app/Admin/AdminController.php:39,63` | request id generated via `Request::newRequestId()` when absent and passed into `LoginService::attempt(..., $this->requestId, ...)` | CRITICAL 1, W1 |
| `api/app/Admin/AdminController.php:59-62` | audit closure maps LoginService event shape (`action`, `actor_id`, `request_id`, `metadata`) into `AuditService::append` | CRITICAL 1 |
| `api/app/Admin/AdminController.php:84` | `auth.logout` emitted with actor + request id after session destruction | CRITICAL 1 |
| `public_html/admin/index.php:5-11` | `X-Request-Id` + CSP / `X-Content-Type-Options` / `Referrer-Policy` / `Permissions-Policy` sent on every admin response (reuses `RequestIdMiddleware::HEADER_NAME` and `SecurityHeadersMiddleware::HEADERS` constants) | W1 |
| `tests/AdminHttpTest.php::testAuthAuditEventsArePersistedWithRequestId` (new) | HTTP-level test: request id + all 4 security headers on `/admin/login`; `audit_log` rows for `auth.login_failed` / `auth.login_success` / `auth.logout` with non-empty `request_id`; plaintext scan | Closes the prior test blind spot (audit asserted against `audit_log`, not an in-memory spy) |

`LoginService` already emitted `auth.login_failed` / `auth.lockout` / `auth.login_success` with request-id passthrough and hash-only metadata (`api/app/Auth/LoginService.php:23-37`); the admin shell now persists them through the same real `AuditService` used by the JSON pipeline.

### Full suite

```text
Command: D:\xampp\php\php.exe tools/run-tests.php
Exit code: 0
Output tail: 47 tests, 0 failures
Output SHA-256: 1819f8cc99f59c8c1d53f4ba18bec825e4a78218d8bb864167ba2eceea411ff9
```

47/47 (was 46/46; the +1 is `Tests\AdminHttpTest::testAuthAuditEventsArePersistedWithRequestId`). Suite run twice; both runs 47/47, exit 0.

### Independent runtime proof (beyond the suite's own test)

Harness: TWO consecutive independent exercises, each on a fresh scratch MariaDB (`vo_auth_test_rv33b40479fc`, then `vo_auth_test_rv87c9a4b245` — created/dropped, `vo_test` never touched), migrations 001+002 via `MigrationRunner`, `InstallerSeeder` (Demo Store, `owner@example.test`), second user `manager@example.test`; `php -S 127.0.0.1:28117 -t public_html public_html/router.php` with `VO_INSTALLED_CONFIG_PATH` lock; cookie-jar HTTP client (redirects not followed); audit assertions via direct SQL against the scratch DB. Both runs fully green. Exact transcript of run 2 (file bytes hashed as `evidence_revision` above):

```text
[SETUP] scratch DB `vo_auth_test_rv87c9a4b245` migrated (001+002) + InstallerSeeder + manager@example.test
[OK] php -S started on 127.0.0.1:28117 with public_html/router.php (pid 24440)
[OK] E1 GET /admin/login -> 200 (got 200)
[OK] E1 X-Request-Id present + 32-hex (b25f78a1bdbc84d5121662996423171f)
[OK] E1 Content-Security-Policy exact value
[OK] E1 X-Content-Type-Options: nosniff
[OK] E1 Referrer-Policy: no-referrer
[OK] E1 Permissions-Policy exact value
[OK] E1 Spanish login form rendered
[OK] E1 CSRF token (64-hex) extracted from form
[OK] E2 unknown-user login -> 200 generic error
[OK] E2 no identifier leak in response body
[OK] E3 wrong-password#1 -> 200 generic
[OK] E3 wrong-password#2 -> 200 generic
[OK] E3 wrong-password#3 -> 200 generic
[OK] E3 wrong-password#4 -> 200 generic
[OK] E3 wrong-password#5 -> 200 generic
[OK] E4 correct-password-while-locked -> 200 generic (blocked, no enumeration)
[OK] E4 X-Request-Id present on lockout response
[OK] E5 manager login (owner locked, same IP) -> 302 (got 302)
[OK] E5 Location: /admin/
[OK] E5 X-Request-Id present on login-success response
[OK] E6 dashboard 200 shows business + manager user
[OK] E6 X-Request-Id on dashboard response
[OK] E6 logout CSRF extracted
[OK] E7 logout -> 302 /admin/login
[OK] E7 X-Request-Id present on logout response
[OK] E7 post-logout dashboard -> 302 /admin/login
[E8] audit action summary: [auth.lockout=1, auth.login_failed=6, auth.login_success=1, auth.logout=1, installer.completed=1]
[OK] E8 `auth.login_failed` rows with non-empty request_id: 6 (need >= 6)
[OK] E8 `auth.lockout` rows with non-empty request_id: 1 (need >= 1)
[OK] E8 `auth.login_success` rows with non-empty request_id: 1 (need >= 1)
[OK] E8 `auth.logout` rows with non-empty request_id: 1 (need >= 1)
[OK] E8 auth.login_success row request_id === X-Request-Id observed on its response
[OK] E8 auth.lockout row request_id === X-Request-Id observed on its response
[OK] E8 auth.logout row request_id === X-Request-Id observed on its response
[OK] E8 zero plaintext identifiers/secrets across audit_log columns (scanned 10 rows)
[OK] E8 auth.login_* metadata identifier_hash/ip_hash all 64-hex (8/8 rows, bad=0)
[CLEANUP] php -S terminated
[CLEANUP] dropped `vo_auth_test_rv87c9a4b245`
REVERIFY_RESULT: PASS (all independent runtime checks green)
```

Post-run hygiene: `vo_auth_test_*` databases: 0; stray `php -S` processes: 0; `vo_test` untouched.

Key correlation proof: the `request_id` stored in each of the `auth.login_success`, `auth.lockout`, and `auth.logout` rows is byte-identical to the `X-Request-Id` header observed on that exact HTTP response — audit rows are attributable to individual requests end-to-end.

### Per-finding resolution

| Original finding | Resolution | Evidence |
|---|---|---|
| **CRITICAL 1** — auth audit events never persisted at runtime | **RESOLVED** | E8 direct SQL: 6× `auth.login_failed`, 1× `auth.lockout`, 1× `auth.login_success`, 1× `auth.logout`, all with non-empty `request_id`; request-id ↔ `X-Request-Id` header correlation on success/lockout/logout responses; zero plaintext across every column of all 10 `audit_log` rows (identifiers, passwords); `identifier_hash`/`ip_hash` metadata 64-hex (8/8). Reinforced by suite test `testAuthAuditEventsArePersistedWithRequestId`. |
| **WARNING 1** — admin pipeline lacks request id + security headers (design data flow) | **RESOLVED** | E1: `X-Request-Id` (32-hex) + exact CSP / `X-Content-Type-Options: nosniff` / `Referrer-Policy: no-referrer` / `Permissions-Policy` on `/admin/login`; `X-Request-Id` also verified on lockout, login-success, dashboard, and logout responses. Source: `public_html/admin/index.php:5-11`. |
| **WARNING 2** — lockout audit clause unmet at runtime | **RESOLVED** | Direct runtime proof (not merely code-path identity): 5 wrong-password failures followed by a correct-password attempt while locked produced exactly 1 `auth.lockout` row with non-empty `request_id` matching the blocked response's `X-Request-Id`; response stayed generic with no enumeration. Login Lockout scenario now fully COMPLIANT. |
| SUGGESTION 1 — negative scope-guard HTTP probes | OPEN (non-blocking) | Unchanged; static + suite evidence still stands. |
| SUGGESTION 2 — CSRF cookie-equality fallback in `AdminController::validCsrf()` | OPEN (non-blocking) | Fallback still present (`api/app/Admin/AdminController.php:115`). |

### Matrix deltas (supersede the corresponding original rows)

- auth-runtime / **Auth Audit Events** (failed-login audit safety): ⚠️ PARTIAL → **✅ COMPLIANT**
- auth-runtime / **Login Lockout — Lockout trigger** (blocked generically AND lockout audit recorded): ⚠️ PARTIAL → **✅ COMPLIANT**
- Coherence / "Data flow: login records attempt/audit": ❌ No → **✅ Yes**
- Coherence / "Data flow: protected admin = `request_id -> security headers -> session -> ...`": ❌ No → **✅ Yes**

Updated totals: requirements **25/25 PASS**, scenarios **36/36 COMPLIANT**, blockers **0**.

### Final verdict (re-verification)

**PASS** — The CRITICAL and both WARNINGs from the original verification are resolved with independent runtime evidence; the only open items are the two original non-blocking SUGGESTIONS. Next phase: **sdd-archive**.
