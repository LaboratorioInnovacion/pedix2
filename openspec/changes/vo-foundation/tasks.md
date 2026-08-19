# Tasks: VO Foundation

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 650-750 total; Unit A 330-390; Unit B 320-380 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR A: runtime/test harness -> PR B: DB/domain/docs |
| Delivery strategy | ask-on-risk, resolved by user approval for Work Unit A only |
| Chain strategy | stacked-to-main |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| A | Shared-hosting layout, bootstrap, HTTP `/health`, headers, errors, logging, first harness | PR 1 | `C:\tools\php-8.3\php.exe tools\run-tests.php` | Request `/health` through `public_html/index.php` | Remove `api/bootstrap`, `api/config`, `api/app/{Config,Http,Support}`, `public_html/index.php`, harness smoke tests |
| B | PDO/migrations, Money/state/idempotency, unit tests, run docs | PR 2 | `C:\tools\php-8.3\php.exe tools\run-tests.php` | `C:\tools\php-8.3\php.exe tools\run-migrations.php` against configured DB when available; otherwise fake-covered | Remove `api/app/{Database,Domain}`, `tools/run-migrations.php`, related tests/docs |

## Work Unit A: Runtime Foundation

- [x] 1.1 Create private/public layout and placeholders: `api/{app,bootstrap,config,database/migrations,storage/logs}`, `public_html/`. Verify with tests. Accepts Layout/Private path blocked.
- [x] 1.2 Add config and bootstrap: `api/bootstrap/{paths.php,autoload.php,app.php}`, `api/config/{app.php,database.php}`, `api/app/Config/Config.php`. Verify missing config safe failure. Accepts Bootstrap config.
- [x] 1.3 Add support layer: `api/app/Support/{Logger.php,ErrorHandler.php}` with safe JSON envelope and request-id logging. Accepts non-secret errors.
- [x] 1.4 Add HTTP primitives: `api/app/Http/{Request.php,JsonResponse.php,Router.php,Middleware.php}`. Accepts `/health`, 404, 405, no business routes.
- [x] 1.5 Add middleware and front controller: `RequestIdMiddleware.php`, `SecurityHeadersMiddleware.php`, `public_html/index.php`. Accepts order and baseline headers on success/error.
- [x] 1.6 Add harness skeleton and smoke tests: `tools/run-tests.php`, `tests/TestCase.php`, `tests/FoundationRuntimeTest.php`, `tests/HttpSmokeTest.php`. Verify `C:\tools\php-8.3\php.exe tools\run-tests.php`. Commit boundary: `feat(foundation): add runtime bootstrap and health boundary`; tests stay in same commit. No auth/catalog/pricing/order/payment/delivery logic.

## Work Unit B: Data and Domain Primitives

- [ ] 2.1 Add PDO boundary: `api/app/Database/{Connection.php,PdoConnection.php}` plus tests proving lazy connection, prepared parameters, fail-closed transactions. Accepts PDO boundary/Prepared query.
- [ ] 2.2 Add migrations: `api/app/Database/MigrationRunner.php`, `tools/run-migrations.php`, migration tests with fakes. Accepts Run twice and duplicate-version refusal.
- [ ] 2.3 Add Money VO: `api/app/Domain/Money.php`, `tests/MoneyTest.php`. Accepts integer cents, rejects floats, same-currency add/subtract, half-even percentage.
- [ ] 2.4 Add state helper: `api/app/Domain/{StateMachine.php,InvalidTransition.php,TransitionHistoryHook.php}`, `tests/StateMachineTest.php`. Accepts invalid transition exception and optional history hook.
- [ ] 2.5 Add idempotency helper: `api/app/Domain/{IdempotencyStore.php,InMemoryIdempotencyStore.php,IdempotencyOutcome.php}`, `tests/IdempotencyTest.php`. Accepts duplicate attempt returns first marker.
- [ ] 2.6 Add README run instructions and scope guard checks: `README.md`, `tests/ScopeGuardTest.php`. Verify `C:\tools\php-8.3\php.exe tools\run-tests.php`; optional `php tools/run-tests.php` where PHP is on PATH. Commit boundary: `feat(foundation): add data and domain primitives`; docs/tests stay with code. No business workflow decisions.
