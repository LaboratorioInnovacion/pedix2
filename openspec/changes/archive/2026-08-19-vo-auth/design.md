# Design: VO Auth

## Technical Approach

Extend the existing Composer-free PHP runtime using the current `Request`/`Router`/middleware pattern for JSON and a separate server-rendered `/admin/` front controller like the installer. Auth lives in `api/app/Auth`, audit in `api/app/Audit`, templates in shared `api/app/Support/Template`, and persistence remains PDO/MySQL with additive migration `002`. This maps to `auth-runtime`, `rbac-authorization`, `admin-shell`, `foundation-runtime`, and `testing-bootstrap` while preserving Phase 3 admin-only scope.

## Architecture Decisions

| Topic | Choice | Alternatives / Rationale |
|---|---|---|
| Session model | Native PHP session cookie plus DB `auth_sessions` validation on each admin request. | Full DB session handler is larger; PHP-only sessions cannot satisfy revocation. |
| Admin boundary | Dedicated `public_html/admin/index.php` with HTML responses. | Avoid forcing HTML through `JsonResponse` middleware; follows installer front-controller pattern. |
| Template reuse | Move installer-compatible renderer to `api/app/Support/Template`; keep API-compatible wrapper if needed. | Prevents parallel renderers; no installer template behavior change. |
| Authorization | Guard by permission key and explicit `user_branches` rows. | Role-name checks and magic owner/all-branch semantics are deferred and unsafe. |

## Data Flow

`GET /admin/login` starts session -> `CsrfService::issue()` -> Spanish form.  
`POST /admin/login` -> session -> login CSRF -> `LockoutService` check -> normalized identifier lookup -> dummy hash if missing -> `password_verify()` -> record attempt/audit -> on success `session_regenerate_id(true)` -> insert `auth_sessions` -> redirect `/admin/`.  
Protected admin: `request_id -> security headers -> session -> AuthMiddleware(except login) -> Csrf(on POST) -> route`. Invalid/missing/revoked/expired sessions revoke local state and redirect to `/admin/login`; API-style denials use JSON status, admin HTML uses redirect/page.

## Unit Split / File Changes

### Unit A — Auth core, POST routing, tests (~370 LOC)

| File | Action | Purpose |
|---|---|---|
| `api/database/migrations/002_create_auth_runtime.sql.php` | Create | `auth_sessions`, `login_attempts`. |
| `api/app/Auth/AuthSession.php` | Create | Harden cookie, start/regenerate, DB validate/revoke/update `last_seen_at`. |
| `api/app/Auth/LoginService.php` | Create | Normalize identifier, dummy hash, verify, success/failure/audit orchestration. |
| `api/app/Auth/LockoutService.php` | Create | Defaults: threshold 5, 15-minute window/duration; clear on success. |
| `api/app/Auth/CsrfService.php` | Create | 32-byte hex per-session token; `hash_equals` verify. |
| `api/app/Http/Request.php` | Modify | Add headers/cookies/query/clientIp and `post($key,$default)` form helper. |
| `api/app/Http/Router.php` | Modify | Method-aware table, `post()`, 405 for known path wrong method. |
| `api/bootstrap/app.php` | Modify | Keep JSON routes; wire method routing without admin HTML coupling. |
| `tests/{AuthSessionTest,LoginRateLimitTest,CsrfMiddlewareTest}.php` | Create | Scratch DB unit/HTTP coverage. |

### Unit B — RBAC, branch scope, audit (~330 LOC)

| File | Action | Purpose |
|---|---|---|
| `api/app/Auth/PermissionGuard.php` | Create | `requirePermission(string)` via `user_roles -> role_permissions -> permissions`. |
| `api/app/Auth/BranchScope.php` | Create | `requireBranch(user, branchId)` via `user_branches`. |
| `api/app/Auth/AuthorizationMiddleware.php` | Create | Admin/API denial mapping and audit integration. |
| `api/app/Audit/AuditService.php` | Create | Append to existing `audit_log`. |
| `tests/{PermissionGuardTest,AuditServiceTest}.php` | Create | Allow/deny, request_id audit, global-vs-branch behavior. |

### Unit C — Admin shell (~350 LOC)

| File | Action | Purpose |
|---|---|---|
| `public_html/admin/index.php` | Create | Bootstrap app, session, routes: GET login, POST login, GET dashboard, POST logout. |
| `api/app/Admin/AdminController.php` | Create | Login/dashboard/logout handlers. |
| `api/app/Support/Template.php` | Create | Shared escaped PHP renderer; installer wrapper remains compatible. |
| `api/app/Admin/templates/{layout,login,dashboard}.php` | Create | Spanish UI only. |
| `public_html/admin/assets/admin.css` | Create | Minimal shell styling. |
| `tests/AdminHttpTest.php` | Create | Cookie jar login, redirect, logout revoke, revoked/expired denial. |

## Interfaces / Contracts

Migration `002` DDL sketch, InnoDB utf8mb4:

- `auth_sessions`: `id BIGINT UNSIGNED PK`, `sid_hash CHAR(64) UNIQUE`, `user_id BIGINT UNSIGNED NOT NULL` FK `users(id)`, `ip_hash CHAR(64) NULL`, `created_at`, `last_seen_at`, `absolute_expires_at`, `revoked_at NULL`, indexes `sid_hash`, `(user_id,revoked_at)`, `(absolute_expires_at)`, `(last_seen_at)`.
- `login_attempts`: `id BIGINT UNSIGNED PK`, `identifier_hash CHAR(64) NOT NULL`, `ip_hash CHAR(64) NOT NULL`, `attempted_at DATETIME NOT NULL`, `success TINYINT(1) NOT NULL DEFAULT 0`, index `(identifier_hash, ip_hash, attempted_at)` and stale cleanup index `(attempted_at)`.
- `AuditService::append(?array $actor, string $action, ?string $entity, array $metadata, ?string $requestId): void` writes `actor_type`, `actor_id`, `action`, `entity_type`, `entity_id`, safe `metadata_json`, `request_id`.

## Testing Strategy

| Unit | Coverage |
|---|---|
| A | Login success/failure, dummy hash path, lockout before verify, clear attempts, CSRF reject, 405/POST routing, session regeneration/revoke/expiry. |
| B | Permission allow/deny, branch allow/deny, global actions skip branch, 403 JSON/admin denial and audit request_id. |
| C | `/admin/` unauth redirect, login form token, generic bad login, valid dashboard, logout idempotent revoke, cookie jar reuse, scratch DB cleanup. |

Run focused filters with `D:\xampp\php\php.exe tools/run-tests.php --filter ...`, then full suite.

## Threat Matrix

| Boundary | Applicability | Design response | Planned RED tests |
|---|---|---|---|
| Documentation-like paths | N/A: no executable-file classification. | No path execution. | None. |
| Git repository selection | N/A: no VCS automation. | No git commands. | None. |
| Commit state | N/A: no commit automation. | No index mutation. | None. |
| Push state | N/A: no push automation. | No remote mutation. | None. |
| PR commands | N/A: no PR automation. | No command composition. | None. |

## Migration / Rollout

Additive migration `002`; no baseline rewrite. Rollback before production dependency: remove `/admin/`, auth/audit classes, router POST deltas, and drop `auth_sessions`/`login_attempts`.

## Explicit Non-Design / Deferred

Operation/delivery login, customer accounts, remember-me, password reset, role/user management UI, catalog, orders, payments, delivery, reports/config screens, background cleanup jobs, trusted proxy HTTPS support, full DB session handler.

## Open Questions

None blocking; proposal assumptions stand: direct HTTPS detection, 30-minute idle, 12-hour absolute lifetime, explicit branch assignments.
