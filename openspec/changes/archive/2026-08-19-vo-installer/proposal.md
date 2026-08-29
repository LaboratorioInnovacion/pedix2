# Proposal: VO Installer

## Intent

Deliver Phase 2: a shared-hosting-safe web installer that creates the first business, branch, admin, and schema baseline without SSH, then locks itself fail-closed.

## Scope

### In Scope
- Dedicated `public_html/install/` server-rendered wizard: requirements check, DB test, initial business/branch/admin form, review/install, completion.
- Generated private config/lock file, migrations via existing `MigrationRunner`, admin/role/permission seeds, and installer audit entry.
- Baseline schema: `businesses`, `branches`, `business_settings`, `branch_settings`, `users`, `roles`, `permissions`, `role_permissions`, `user_roles`, `user_branches`, `audit_log`.
- Minimal mobile-friendly installer HTML/CSS, installer sessions, CSRF on POSTs, sanitized errors, and HTTP tests with PHP built-in server + scratch DB.

### Out of Scope
- Runtime auth/login, admin panel beyond installer, catalog, orders, payments, delivery, updater, backups, customer phone verification, and production-only dependencies.

## Capabilities

### New Capabilities
- `web-installer`: Pre-auth installation wizard, config generation, locking, and first admin provisioning.
- `schema-baseline`: Initial tenant, branch, RBAC, settings, and audit database structures.

### Modified Capabilities
- `foundation-runtime`: Load generated private config overlay and preserve private/public path boundaries.
- `testing-bootstrap`: Cover installer unit/integration/HTTP behavior in the Composer-free harness.

## Approach

Use a separate `/install/` front controller because normal runtime is JSON/config-first. Check a private installed marker before rendering any installer state. Write generated config atomically under `api/config/installed.php`, run migrations, seed data transactionally where possible, write audit metadata, then clear installer session secrets.

## Assumptions Pending User Validation

- PHP minimum is `>= 8.1`; rationale: widest shared-hosting compatibility with modern syntax; reversible by changing requirements/tests.
- Admin login identifier is email-only; rationale: phone verification is customer-facing later; reversible via auth spec.
- First branch requires only name; address/phone optional; rationale: complete branch setup belongs in admin; reversible by tightening validation.
- Installer files remain but hard-locked; rationale: FTP updates are simpler; reversible with deletion docs/tooling.
- No `system_settings` table now; rationale: install metadata fits `business_settings`; reversible in updater/backups phase.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `public_html/install/` | New | Wizard entrypoint/templates/CSS |
| `api/config/installed.php` | New | Generated private config and lock |
| `api/app/Config/Config.php` | Modified | Optional generated overlay |
| `api/database/migrations/` | New | Baseline schema migrations |
| `tests/`, `tools/run-tests.php` | Modified | Installer/schema HTTP coverage |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Partial install leaves unsafe state | Med | Write lock only after successful migrations/seeds |
| Shared hosting permissions block config | Med | Preflight writable checks before DB/admin steps |
| Scope creep into auth/admin | High | Keep login/runtime UI deferred to Phase 3 |

## Rollback Plan

Before production install, delete the change files/migrations. After a failed scratch install, drop the scratch DB and generated config. After real install, rollback requires DB backup restore plus removal of `api/config/installed.php` by an operator.

## Dependencies

- PHP `>=8.1`, `pdo_mysql`, writable private config/storage paths, MySQL/MariaDB, existing `MigrationRunner`.

## Success Criteria

- [ ] Fresh install creates baseline tables, business, branch, owner role, permissions, admin hash, branch assignment, settings, audit entry.
- [ ] `/install/` locks fail-closed after completion and does not expose secrets.
- [ ] Composer-free unit/integration/HTTP tests pass with scratch DB isolation.
