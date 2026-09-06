# Proposal: VO Auth

## Intent

Deliver Phase 3 admin-only auth: login/logout, DB-backed revocation, CSRF, lockout, RBAC, audit logging, and the first server-rendered `/admin/` shell.

## Scope

### In Scope
- Admin login/logout using `password_verify()`, regenerated sessions, `auth_sessions`, and secure cookies.
- CSRF for authenticated state changes; rate limiting/lockout through `login_attempts`.
- RBAC by permission key plus branch scope when required.
- Audit via `audit_log` for login events, authz denials, and request_id.
- Spanish `/admin/` login/dashboard shell.
- Migration `002`; router POST/method routing.

### Out of Scope
- Operation/delivery login, remember-me, password reset, role/user UI, catalog, orders, payments, delivery, customer accounts.

## Assumptions
- HTTPS: `Secure` cookies detect direct `$_SERVER['HTTPS']`; no `X-Forwarded-Proto` in V1. Production HTTPS is documented, not dev-forced; reversible with trusted proxy config.
- Sessions: 30-minute idle and 12-hour absolute lifetime in `auth_sessions`; later `business_settings` configurable.
- Global actions: `users.manage`, `settings.manage`, `reports.view` skip branch scope; `orders.*` and `deliveries.assign` require it.
- New branches/owner: no auto-grant; explicit `user_branches` until users-admin.
- Failed-login audit: SHA-256 normalized identifier hash plus safe metadata only.

## Capabilities

### New Capabilities
- `auth-runtime`: login/logout, sessions, revocation, lockout, CSRF, migration `002` tables.
- `rbac-authorization`: permission guards, branch scope, authz-denial audit.
- `admin-shell`: `/admin/` front controller, Spanish login UI, protected dashboard/logout.

### Modified Capabilities
- `foundation-runtime`: add POST and method-aware routing.
- `schema-baseline`: none. Migration `002` is additive runtime auth schema and belongs to `auth-runtime`, not baseline.

## Approach

Follow Route → Middleware → Controller → Service → Repository → MySQL. Keep auth outside installer namespaces. Use PHP session cookies plus DB-backed `auth_sessions`, not a full DB handler. Use a dedicated HTML admin front controller.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `api/app/Http/*` | Modified | Request parsing, POST routing, CSRF/auth |
| `api/app/Auth/*` | New | Sessions, login, lockout, guards |
| `api/app/Audit/*` | New | `audit_log` wrapper |
| `api/database/migrations/002_*` | New | Auth runtime tables |
| `public_html/admin/` | New | Login/dashboard shell |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Scope creep | Med | Keep Phase 3 admin-only |
| HTTPS ambiguity | Med | Direct detection now; document production requirement |
| Review budget overrun | High | Preserve three-unit split |
| HTML/JSON mismatch | Med | Dedicated admin front controller |

## Rollback Plan

Remove `/admin/`, auth/audit services, and router POST deltas; drop auth tables and migration `002` before production data depends on them. Baseline remains intact.

## Dependencies

- Completed foundation/installer phases, RBAC seed data, PHP sessions, PDO/MySQL.
- No new production dependency on Node.js, Docker, Redis, workers, WebSockets, SSH, or Composer.

## Success Criteria

- [ ] Login creates a DB-backed session; logout revokes it.
- [ ] Failed logins are generic, lockable, and audited without plaintext identifiers.
- [ ] CSRF blocks authenticated state-changing POSTs without valid tokens.
- [ ] Permission/branch guards allow or deny correctly and audit denials.
- [ ] `/admin/` exposes only Spanish login/dashboard skeleton behavior.
