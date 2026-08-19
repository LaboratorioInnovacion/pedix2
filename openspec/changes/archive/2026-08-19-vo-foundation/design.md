# Design: VO Foundation

## Technical Approach

Create a minimal Composer-free PHP foundation for shared hosting: private runtime under `api/`, public boundary under `public_html/`, and tiny tests under `tools/`/`tests/`. This maps to `foundation-runtime`, `domain-primitives`, and `testing-bootstrap`, while deferring all business flows.

## Architecture Decisions

| Topic | Choice | Alternatives considered | Rationale |
|---|---|---|---|
| Autoloading | `api/bootstrap/autoload.php` with PSR-4-ish `spl_autoload_register` for `VO\` => `api/app/` | Composer | Target environment has no Composer and production forbids extra dependencies. |
| Config | PHP arrays in `api/config/*.php`, loaded by `Config` after `paths.php` resolves `api_root`, `public_root`, `storage_root` | `.env`, public config | Private PHP config is shared-hosting-safe; missing required keys fail closed with a generic JSON error. |
| Runtime | `public_html/index.php` builds request -> middleware -> router -> response | Multiple public scripts | One front controller makes `/health`, 404, 405, headers, logging, and error envelopes consistent. |
| Database | Lazy PDO wrapper, exception mode, prepared-only API, guarded transactions | Raw PDO everywhere | Centralizes fail-closed DB behavior required by sec.25 SQL and sec.22 repositories. |
| Scope | Foundation primitives only | Auth/catalog/orders/payments now | Keeps review well below 400 lines and respects foundation scope guard. |

## Data Flow

```text
HTTP -> public_html/index.php -> request_id -> security_headers -> router
     -> /health controller -> JsonResponse -> emit
Errors -> global handler -> log line with request_id -> safe JSON envelope
tools/run-migrations.php -> bootstrap -> MigrationRunner -> schema_migrations
```

## File Changes

This change subset, minimal target: 23 files, about 650-750 authored LOC.

| File | Action | Description |
|---|---|---|
| `api/bootstrap/paths.php` | Create | Resolves roots from private `api/`, public sibling, storage/log paths. |
| `api/bootstrap/autoload.php` | Create | Registers `VO\` classes from `api/app/`. |
| `api/bootstrap/app.php` | Create | Loads paths/config, logger, router, middleware, exception handler. |
| `api/config/app.php` | Create | `app_env`, debug false by default, timezone, required-key list. |
| `api/config/database.php` | Create | DSN/user/password placeholders outside web root. |
| `api/app/Config/Config.php` | Create | Merges config arrays; throws non-secret config exception when required keys are absent. |
| `api/app/Http/*` | Create | Request, JsonResponse, Router, middleware interfaces, RequestId and SecurityHeaders middleware. |
| `api/app/Support/*` | Create | Logger and error handler. |
| `api/app/Database/*` | Create | Connection interface, PdoConnection, MigrationRunner. |
| `api/app/Domain/*` | Create | Money, StateMachine, Idempotency interfaces/in-memory implementation. |
| `api/database/migrations/.gitkeep` | Create | Empty migration folder. |
| `api/storage/logs/.gitkeep` | Create | Private log folder. |
| `public_html/index.php` | Create | Public front controller with only `/health`. |
| `tools/run-tests.php` | Create | Composer-free test runner. |
| `tools/run-migrations.php` | Create | Explicit migration entry; never called on normal web requests. |
| `tests/TestCase.php`, `tests/*Test.php` | Create | Tiny assertions and smoke tests. |

## Interfaces / Contracts

Routes are arrays: `['GET /health' => fn(Request $r): JsonResponse]`; unknown path returns `404`, unsupported method returns `405`. JSON envelope: success `{"ok":true,"data":...,"request_id":"..."}`; error `{"ok":false,"error":{"code":"...","message":"..."},"request_id":"..."}`.

Middleware contract: `handle(Request $request, callable $next): JsonResponse`; order is request ID, security headers, later session, CSRF, auth. Headers: `Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=()`, plus CSP `frame-ancestors`.

DB contract: `select(string $sql,array $params=[]): array`, `execute(string $sql,array $params=[]): int`, `transaction(callable $fn): mixed`; PDO connects lazily with `ERRMODE_EXCEPTION`, refuses nested transactions, rolls back on throwable.

Migrations: files named `NNN_slug.sql.php` returning SQL strings/callables, or `NNN_slug.sql`; `schema_migrations(version varchar(32) primary key, name varchar(191), applied_at datetime not null)`. Runner scans, sorts, skips recorded, inserts after success, and rejects duplicate versions.

Money is immutable: `fromCents(int,string='ARS')`, `add`, `sub`, `pct(int $basisPoints)`, `format`; no floats; half-even integer division for percentage ties. StateMachine accepts states and `from => [to...]`, exposes `can`, `transition`, throws `InvalidTransition`, and calls optional `TransitionHistoryHook`. Idempotency: `attempt(string $key, callable $work): IdempotencyOutcome`; duplicate keys return the first outcome marker; PDO store is only an interface for later.

## Testing Strategy

| Layer | What to Test | Approach |
|---|---|---|
| Unit | Money rounding, state transitions, idempotency duplicate contract | Pure PHP asserts in `tests/*Test.php`. |
| Integration | Router health/error envelopes, middleware order, migration idempotency with fakes | Harness-discovered tests. |
| E2E | None | Deferred until product UI/API slices. |

Harness discovers `tests/*Test.php`, provides `assertTrue`, `assertSame`, `assertThrows`, prints pass/fail, exits `0` green and `1` red. Document Windows command: `C:\tools\php-8.3\php.exe tools\run-tests.php`.

## Threat Matrix

| Boundary | Applicability | Design response | Planned RED tests |
|---|---|---|---|
| Documentation-like paths | N/A: no executable-file classification. | None. | None. |
| Git repository selection | N/A: no VCS automation. | None. | None. |
| Commit state | N/A: no VCS automation. | None. | None. |
| Push state | N/A: no VCS automation. | None. | None. |
| PR commands | N/A: no PR automation. | None. | None. |

## Migration / Rollout

No product data migration is required. The runner can create `schema_migrations` only when `tools/run-migrations.php` is explicitly invoked.

## Open Questions

- [ ] None blocking.

## Explicit Non-Design / Later Phases

Auth, sessions beyond placeholders, CSRF enforcement, repositories, services, business entities, installer/updater UI, backups, pricing engine, catalog, checkout, orders, payments, inventory, delivery, notifications, uploads, admin/operation/customer UIs, and demo data.
