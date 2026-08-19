# Proposal: VO Foundation

## Intent

Bootstrap the Core V1 foundation so later slices can be implemented and tested safely on shared hosting. This change creates the private/public skeleton, runtime primitives, and a Composer-free PHP test harness before any business feature work.

## Scope

### In Scope
- Repository layout matching section 2: private `api/` root and public `public_html/`.
- Autoload/bootstrap primitives; private config file architecture with no `.env` requirement.
- Minimal front controller and route table for one boundary: health response only.
- PDO connection wrapper interface; no business repositories.
- Migration runner skeleton with `schema_migrations` creation.
- `Money` value object using integer cents, base state-machine helper, idempotency record helper.
- Request ID and security headers middleware.
- Pure-PHP micro test harness, Windows portable PHP command documentation, and smoke tests proving the harness runs.

### Out of Scope
- All roadmap Phases 2-12: installer UI, auth, catalog, pricing engine, checkout, orders, inventory, payments, operation, delivery, notifications, reports, backups, updater, demo data.
- Product UI beyond a minimal health page.
- Composer/PHPUnit, production Node/Docker/Redis/workers/WebSockets/SSH dependencies.

## Capabilities

### New Capabilities
- `foundation-runtime`: shared-hosting-safe layout, bootstrap, config, routing, middleware, DB and migration primitives.
- `domain-primitives`: Money, state transitions, and idempotency helpers without business workflows.
- `testing-bootstrap`: Composer-free PHP harness and runnable smoke tests.

### Modified Capabilities
- None.

## Assumptions Pending User Validation

- Sec.14: transfer `verified` is treated as transfer-specific approval; rationale: avoids merging semantics too early; reversible by payment spec.
- Sec.13/14/37: Mercado Pago sequencing is not implemented; foundation only provides idempotency hooks; reversible in payments phase.
- Sec.15: `assigned` remains reserved but unused by the base helper; rationale: preserve spec enum; reversible in delivery spec.
- Sec.11/12/15: completion invariants are deferred; rationale: no order flow here; reversible in orders/delivery phases.
- Sec.13: no-stock and unlimited-stock behavior is deferred; rationale: no inventory logic; reversible in inventory phase.
- Sec.17/18: OpenWA fallback is deferred; rationale: no notification delivery; reversible in notifications phase.
- Sec.19: ticket bridge is excluded; rationale: foundation only; reversible in printing phase.
- Sec.7: promotion minimum set is deferred; rationale: no pricing engine; reversible in promotions phase.
- Sec.28: backup mechanism is deferred; rationale: no installer/updater yet; reversible in updater phase.
- Sec.40: demo profiles/seeds are excluded; rationale: not required for foundation; reversible in final hardening phase.

## Approach

Use small plain-PHP primitives aligned with Route -> Middleware -> Controller -> Service -> Repository -> MySQL, but stop before services/repositories. Keep secrets and logs outside `public_html`, expose only minimal public entry points, and make the first deliverable testability itself.

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `api/` | New | Private app, config, database, storage, bootstrap roots. |
| `public_html/` | New | Health front controller and public asset boundary. |
| `tools/`, `tests/` | New | Micro test runner, smoke/unit tests, Windows command docs. |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Overbuilding business behavior | Med | Specs must reject auth/catalog/order/payment code in this change. |
| Local PHP unavailable | High | Document portable PHP command; harness has no Composer dependency. |
| Shared-hosting path differences | Med | Centralize path resolution in bootstrap/config primitives. |

## Rollback Plan

Delete the new `api/`, `public_html/`, `tools/`, and `tests/` bootstrap files. No business data migrations are introduced beyond a skeleton capable of creating `schema_migrations` when run.

## Dependencies

- PHP 8.x runtime, including portable PHP for Windows verification.
- MySQL/MariaDB only when exercising migration/connection primitives.

## Success Criteria

- [ ] Layout matches the master spec private/public hosting tree.
- [ ] Health route works without exposing private files.
- [ ] Test harness runs through the documented Windows portable PHP command.
- [ ] Smoke tests cover harness execution plus Money/state/idempotency primitives.
