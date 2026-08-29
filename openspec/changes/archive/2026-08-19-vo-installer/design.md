# Design: VO Installer

## Technical Approach

Extend the Phase 1 Composer-free PHP foundation, not a parallel app: schema and seeds use `VO\Database\MigrationRunner`/`PdoConnection`; runtime config keeps `Config::load()` but overlays a private generated `api/config/installed.php`; the installer is a separate HTML front controller at `public_html/install/index.php` because normal runtime is JSON/config-first. Specs covered: `web-installer`, `schema-baseline`, `foundation-runtime`, `testing-bootstrap`; master sections 2, 4, 5, 28, 30, 32, 38.

## Architecture Decisions

| Decision | Choice | Alternatives considered | Rationale |
|---|---|---|---|
| Installer boundary | Dedicated `public_html/install/index.php` with small installer services/templates under `api/app/Installer` | Add `/install` to JSON router | Installer must run before DB config, return HTML, and lock before normal bootstrap. |
| Lock/config | `api/config/installed.php` returns an array overlay, written via temp file + `rename()`, containing DB creds, generated `app.key`, install metadata | DB-only flag or tracked config edit | File lock is fail-closed before DB/session and survives DB outage; tracked defaults stay deployable. |
| RBAC scope | Roles/permissions are global-to-install; user/branch assignments carry scope | Business-scoped roles | Master section 2 is one install per customer/one business at install; global roles avoid false multi-tenant complexity while preserving branch assignment. |
| Retry safety | Run migrations first; seed inside one transaction with natural-key guards/upserts; write lock last | Delete partial data on retry | DDL is versioned by `schema_migrations`; guarded seeds make partial retry safe without destructive cleanup. |

## Data Flow

`GET /install/` → lock check (`file_exists`) → session+CSRF → requirements → DB test → form → review → run → `MigrationRunner` → transactional seed → audit row → atomic `installed.php` → clear session → done. If lock exists, return a locked 410/404-style HTML page with no forms and no DB access.

## File Changes / Unit Split

### Unit A — schema/config/seeds/tests, no HTML (~360-390 LOC)

| File | Action | Purpose |
|---|---|---|
| `api/database/migrations/001_create_baseline.sql.php` | Create | 11 baseline tables; `schema_migrations` remains runner-owned. |
| `api/app/Config/Config.php` | Modify | Merge `installed.php` overlay after static defaults; keep sanitized missing-config failures. |
| `api/app/Installer/InstalledConfig.php` | Create | Lock path, atomic write, generated `app.key`, no overwrite unless test path. |
| `api/app/Installer/InstallerSeeder.php` | Create | Idempotent guarded seed transaction for business, branch, owner/admin/RBAC/settings/audit. |
| `tests/ConfigOverlayTest.php` | Create | Overlay precedence and missing overlay behavior. |
| `tests/BaselineSchemaTest.php` | Create | Scratch DB migration/table/FK/unique assertions. |
| `tests/InstallerSeederTest.php` | Create | Seed data, owner permissions, retry guards, password hash. |

### Unit B — wizard/session/HTTP tests (~360-400 LOC)

| File | Action | Purpose |
|---|---|---|
| `public_html/install/index.php` | Create | Installer front controller/state machine. |
| `public_html/install/assets/install.css` | Create | Minimal mobile-first styling. |
| `api/app/Installer/InstallerController.php` | Create | Steps, validation, execution orchestration, sanitized errors. |
| `api/app/Installer/InstallerSession.php` | Create | Session cookie flags, CSRF token, step state, secret clearing. |
| `api/app/Installer/RequirementsChecker.php` | Create | PHP/ext/writable/random/lock checks. |
| `api/app/Installer/Template.php` | Create | Plain PHP render helper with `htmlspecialchars` escape. |
| `api/app/Installer/templates/*.php` | Create | Requirements, DB, form, review, done, locked, error views. |
| `tools/run-tests.php` | Modify | Built-in-server helper and scratch env lifecycle. |
| `tests/InstallerHttpTest.php` | Create | GET/POST CSRF, HTML contains/status, lock behavior. |

## Schema Design

All tables: `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`; ids `BIGINT UNSIGNED AUTO_INCREMENT`; timestamps `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`, plus `updated_at ... ON UPDATE CURRENT_TIMESTAMP` for mutable rows.

- `businesses`: `id PK`, `name varchar(191) not null`, `slug varchar(191) null unique`, `timezone varchar(64) not null default 'UTC'`, `is_active tinyint(1) not null default 1`, timestamps.
- `branches`: `id PK`, `business_id FK businesses(id) ON DELETE RESTRICT`, `name varchar(191)`, `address varchar(255) null`, `phone varchar(64) null`, `is_active tinyint(1) default 1`, timestamps, unique `(business_id,name)`.
- `business_settings`: `id PK`, `business_id FK ... ON DELETE CASCADE`, `setting_key varchar(191)`, `setting_value text null`, timestamps, unique `(business_id,setting_key)`.
- `branch_settings`: same, `branch_id FK branches(id) ON DELETE CASCADE`, unique `(branch_id,setting_key)`.
- `users`: `id PK`, `business_id FK businesses(id) ON DELETE RESTRICT`, `name varchar(191)`, `email varchar(191) not null unique`, `password_hash varchar(255) not null`, `is_active tinyint(1) default 1`, `last_login_at datetime null`, timestamps.
- `roles`: `id PK`, `name varchar(64) not null unique`, `label varchar(191) not null`, `is_system tinyint(1) default 0`, timestamps.
- `permissions`: `id PK`, `permission_key varchar(191) not null unique`, `label varchar(191) not null`, timestamps.
- `role_permissions`: `role_id FK roles ON DELETE CASCADE`, `permission_id FK permissions ON DELETE CASCADE`, PK `(role_id,permission_id)`.
- `user_roles`: `user_id FK users ON DELETE CASCADE`, `role_id FK roles ON DELETE RESTRICT`, PK `(user_id,role_id)`.
- `user_branches`: `user_id FK users ON DELETE CASCADE`, `branch_id FK branches ON DELETE CASCADE`, PK `(user_id,branch_id)`.
- `audit_log`: `id PK`, `actor_type varchar(32) not null`, `actor_id bigint unsigned null`, `action varchar(191) not null`, `entity_type varchar(64) null`, `entity_id bigint unsigned null`, `metadata_json json null`, `request_id char(32) null`, `created_at datetime not null default current_timestamp`, index `(action)`, `(request_id)`; append-only by convention.

Permission seed keys: `orders.view`, `orders.accept`, `orders.reject`, `orders.modify`, `orders.prepare`, `orders.mark_ready`, `orders.cancel`, `products.edit_price`, `products.change_availability`, `products.manage_stock`, `deliveries.assign`, `deliveries.reassign`, `payments.verify_transfer`, `settings.manage`, `users.manage`, `reports.view`. Seed role: `owner` with all permissions.

## Interfaces / Contracts

`installed.php` returns: `['installed'=>true,'installed_at'=>..., 'version'=>'1.0.0', 'app'=>['key'=>...], 'database'=>['dsn'=>'mysql:host=...;port=...;dbname=...;charset=utf8mb4','user'=>...,'password'=>...]]`. Installer POSTs require `csrf`; tokens are session-bound and compared with `hash_equals`. Templates escape via `Template::e()`.

## Testing Strategy

| Layer | What | Approach |
|---|---|---|
| Unit | config overlay, requirements, CSRF/session, atomic writer | Existing pure-PHP `TestCase`, temp dirs. |
| Integration | baseline schema/seeds/retry | Create/drop `vo_installer_test_<random>` via XAMPP root/empty; never use `vo_test` directly. |
| HTTP | wizard order, sanitized errors, lock | `tools/run-tests.php` launches `php -S 127.0.0.1:<port> -t public_html` with router; cleanup uses `proc_terminate` then Windows `taskkill /F /T` fallback; assert status and string containment. |

## Threat Matrix

| Boundary | Applicability | Design response | Planned RED tests |
|---|---|---|---|
| Documentation-like paths | N/A: no executable-file classification. | None. | None. |
| Git repository selection | N/A: no VCS automation. | None. | None. |
| Commit state | N/A: no VCS automation. | None. | None. |
| Push state | N/A: no VCS automation. | None. | None. |
| PR commands | N/A: no PR automation. | None. | None. |
| Routing/process boundary | Applicable: new `/install/` route and test subprocess. | Lock check precedes session/DB; built-in-server args are fixed, no user shell interpolation. | Locked installer makes no DB call; server cleanup kills child process. |

## Security / Failure Behavior

Installer session uses secure cookie parameters when HTTPS is detected, `HttpOnly`, `SameSite=Lax`, and regeneration on start. No secrets in HTML/logs/session after DB failure. Human error pages are sanitized; private logs allow only `request_id`, step, exception class. Seed audit event uses `actor_type=installer`, action `installer.completed`, entity `business`, metadata JSON with created ids and migration versions, plus request_id. Lock is written LAST; before then re-run is allowed.

## Migration / Rollout

First committed migration version is `001` because `api/database/migrations/` currently has only `.gitkeep`; `schema_migrations` is auto-created by `MigrationRunner`. Rollout is install-time only. Existing production installs are out of scope until updater phases.

## Explicit Non-Design / Phase 3+

Runtime login/session middleware, admin dashboard, role management UI, catalog, orders, payments, delivery, customers/phone verification, updater, backups, scheduled jobs, module tables, and production Apache rewrite tuning are deferred.

## Open Questions

None blocking; proposal assumptions stand: PHP `>=8.1`, email-only admin identifier, first branch name required with optional address/phone, installer remains hard-locked after completion.
