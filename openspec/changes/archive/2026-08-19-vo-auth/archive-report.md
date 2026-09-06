# Archive Report: VO Auth

## Closure State

**Change**: `vo-auth`
**Project**: `pedix2`
**Archive date**: 2026-08-19
**Archived to**: `openspec/changes/archive/2026-08-19-vo-auth/`
**Artifact store**: hybrid (`openspec` + Engram)
**Review gate**: disabled/unmanaged because the receipt-driven development kill switch is OFF for this archive; no review receipt was required for closure.

## Final State

Phase 3 (admin auth/RBAC/admin shell) is complete and verified PASS after remediation. The terminal re-verification section of `verification.md` records **25/25 requirements PASS, 36/36 scenarios COMPLIANT, 0 blockers, 0 critical findings**, with the full suite at **47 tests / 0 failures** (run twice, both green, exit 0). Independent runtime proof on fresh scratch databases confirmed the audit remediation end-to-end: `request_id` stored in each `auth.login_failed` / `auth.lockout` / `auth.login_success` / `auth.logout` row is byte-identical to the `X-Request-Id` header observed on that exact HTTP response, with zero plaintext identifiers or secrets anywhere in `audit_log`.

## Unit Breakdown

| Unit | Scope | Key artifacts |
|---|---|---|
| A — Auth core | Migration `002` (`auth_sessions`, `login_attempts`), `AuthSession`, `LoginService`, `LockoutService`, `CsrfService`, method-aware POST routing + form body parsing in `Request`/`Router`/bootstrap | `api/app/Auth/*`, `api/database/migrations/002_create_auth_runtime.sql.php` |
| B — RBAC + audit | `PermissionGuard` (permission-key chain), `BranchScope` (explicit `user_branches`), `AuthorizationMiddleware` (403 JSON + `authz.denied` audit), `AuditService` append-only wrapper | `api/app/Audit/*`, `api/app/Auth/{PermissionGuard,BranchScope,AuthorizationMiddleware}.php` |
| C — Admin shell | `public_html/admin/` front controller, `AdminController` (4 routes only), shared `Support/Template` with installer wrapper, Spanish templates + `admin.css` | `api/app/Admin/*`, `public_html/admin/*` |
| Remediation (~55 lines) | Real `AuditService` wired into `AdminController` (was no-op sink), request id generated/propagated in admin pipeline, `auth.logout` audit emission, security headers + `X-Request-Id` on every admin response, new HTTP-level audit test `testAuthAuditEventsArePersistedWithRequestId` | `api/app/Admin/AdminController.php`, `public_html/admin/index.php`, `tests/AdminHttpTest.php` |

## Verification Journey

1. **Initial verify: FAIL** — one CRITICAL finding: auth audit events were never persisted at runtime (no-op audit sink + `null` request id in `AdminController`; no `auth.logout` emission anywhere; unit tests masked this by asserting against an in-memory spy instead of `audit_log`). Suite was 46/46 but the `auth-runtime` "Auth Audit Events" requirement was violated by the delivered runtime.
2. **Remediation** — ~55-line fix wiring the real `AuditService`, request id, `auth.logout` emission, and admin security headers, plus the new HTTP-level audit test (+1 test).
3. **Re-verify: PASS** — CRITICAL and both WARNINGs resolved with independent runtime evidence (two consecutive green exercises on fresh scratch DBs); requirements 25/25, scenarios 36/36.

## No-Commit Mode Note

The user explicitly requested no git add/commit/push/branch operations for this archive phase. All archive work was performed as file operations only. Commit/push steps from the archive skill were skipped.

All vo-auth implementation is **working-tree only and uncommitted** — nothing has been committed since vo-installer Unit A (`0439b22` on `feat/vo-installer-foundation`). Uncommitted vo-auth files include: `api/app/Auth/*`, `api/app/Audit/*`, `api/app/Admin/*`, `api/app/Support/Template.php`, `api/database/migrations/002_create_auth_runtime.sql.php`, `public_html/admin/*`, `public_html/router.php`, modifications to `api/app/Http/*`, `api/bootstrap/app.php`, `tests/*` (new auth/admin/audit tests + harness updates), `tools/run-tests.php`, plus the vo-installer Unit B files and prior archive moves that were already pending.

## Spec Sync Result

| Capability | Action | Result |
|---|---|---|
| `auth-runtime` | Created | New main spec at `openspec/specs/auth-runtime/spec.md` with 9 requirements / 12 scenarios. |
| `rbac-authorization` | Created | New main spec at `openspec/specs/rbac-authorization/spec.md` with 6 requirements / 9 scenarios. |
| `admin-shell` | Created | New main spec at `openspec/specs/admin-shell/spec.md` with 6 requirements / 8 scenarios. |
| `foundation-runtime` | Updated | Appended 2 ADDED requirements (Method-Aware Routing, Form POST Body Parsing) under a clearly-marked section; existing content preserved. |
| `testing-bootstrap` | Updated | Appended 2 ADDED requirements (Authenticated HTTP Test Flows, Auth Scratch DB Tables) under a clearly-marked section; existing content preserved. |

No destructive, removed, renamed, or scope-changing deltas were merged.

## Archive Contents

- `proposal.md` ✅
- `design.md` ✅
- `tasks.md` ✅ — 17/17 implementation tasks checked complete
- `verification.md` ✅ — final verdict PASS (post-remediation re-verification), 25/25 requirements, 36/36 scenarios, 47/47 tests
- `specs/` ✅ — auth-runtime, rbac-authorization, admin-shell, foundation-runtime, testing-bootstrap
- `archive-report.md` ✅

## Engram Traceability

| Artifact | Topic |
|---|---|
| Proposal | `sdd/vo-auth/proposal` |
| Spec | `sdd/vo-auth/spec` |
| Design | `sdd/vo-auth/design` |
| Tasks | `sdd/vo-auth/tasks` |
| Verify report | `sdd/vo-auth/verify-report` |
| Archive report | `sdd/vo-auth/archive-report` |

## Open Follow-ups

Non-blocking, carried from verification (final state — still open at close):

1. **SUGGESTION 1**: add negative HTTP scope-guard probes (e.g., `GET /admin/password-reset`, `GET /admin/users`, `GET /admin/catalog` → 404) so "deferred features absent" scenarios are runtime-proven instead of inspection-proven.
2. **SUGGESTION 2**: `AdminController::validCsrf()` still accepts a cookie-equality fallback (`vo_csrf` cookie vs submitted token) in addition to the per-session `hash_equals` check; consider session-only validation for a single CSRF verification mechanism.
3. **Ledger cosmetic**: budget flags recorded on the verify report docs for `vo-foundation-verify` and `vo-auth-verify2` are cosmetic bookkeeping only; no action required in this repo.

## Risks

- All vo-auth implementation and this archive's file/spec moves are uncommitted by explicit user instruction; accidental working-tree loss remains possible until the user chooses a persistence strategy.

## Next Recommended

Phase 4: **catalog** — start via the SDD cycle (`/sdd-new` or the orchestrator's next change launch) when the user is ready.
