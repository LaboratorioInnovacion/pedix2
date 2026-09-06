# Testing Bootstrap

## Requirements

### Requirement: Single command harness
Harness MUST run as `php tools/run-tests.php` or portable PHP, without Composer/PHPUnit.
#### Scenario: Composer-free run
- GIVEN PHP available
- WHEN test command runs
- THEN tests execute without vendors

### Requirement: Discovery and exits
Harness MUST discover `tests/`, return zero for green, non-zero for red.
#### Scenario: Red run
- GIVEN discovered failure
- WHEN the harness finishes
- THEN exit code is non-zero

#### Scenario: Green run
- GIVEN all tests pass
- WHEN the harness finishes
- THEN exit code is zero

### Requirement: Smoke coverage
Smoke tests MUST cover harness execution, Money, state transitions, and idempotency.
#### Scenario: Smoke tests found
- GIVEN foundation tests
- WHEN discovery runs
- THEN required smoke areas are included

### Requirement: Testing scope guard
This change MUST NOT test auth, catalog, pricing engine, orders, payments, or delivery logic.
#### Scenario: Deferred tests absent
- GIVEN foundation tests list
- WHEN scope is reviewed
- THEN only runtime, primitives, harness are verified

### Requirement: HTTP Installer Harness

The Composer-free harness MUST support HTTP-level installer tests by launching PHP's built-in server against `public_html` with isolated cookies/session state.

#### Scenario: Installer HTTP request
- GIVEN a test server is started on a local random port
- WHEN the test client requests installer GET and POST flows
- THEN response status, headers, cookies, CSRF behavior, and HTML lock behavior are observable.

### Requirement: Scratch DB Isolation

Installer integration tests MUST create a scratch database per test run and drop it afterward; tests MUST NOT use the shared `vo_test` schema directly.

#### Scenario: Isolated schema lifecycle
- GIVEN installer integration tests start
- WHEN a scratch DB name is allocated
- THEN migrations and seeds run only in that DB
- AND teardown drops it without touching non-scratch schemas.

### Requirement: Installer Test Mode

The harness MUST provide installer test mode for scratch config paths, scratch storage paths, and scratch DB credentials.

#### Scenario: Test paths prevent real lock writes
- GIVEN installer test mode is enabled
- WHEN installation completes in tests
- THEN generated config and storage writes target scratch paths
- AND production `api/config/installed.php` is not created or modified.

### Requirement: Testing Scope Guard

Installer tests MUST NOT introduce runtime login/auth endpoint tests, admin panel tests, catalog, orders, payments, delivery, customer account, updater, or backup tests.

#### Scenario: Deferred test areas absent
- GIVEN test discovery runs
- WHEN test names and fixtures are reviewed
- THEN only runtime, schema, and installer behavior are covered.

<!-- ===== Added by change vo-auth (archived 2026-08-19) ===== -->

### Requirement: Authenticated HTTP Test Flows

The Composer-free HTTP harness MUST support authenticated browser-like flows by reusing a cookie jar across multiple requests, including login, CSRF-protected POSTs, dashboard access, and logout.

#### Scenario: Cookie jar persists login
- GIVEN an HTTP test client with a reusable cookie jar
- WHEN login succeeds and a later dashboard request is sent
- THEN the dashboard request includes the authenticated session cookie.

#### Scenario: Logout clears effective auth
- GIVEN an authenticated HTTP test flow
- WHEN logout is submitted
- THEN later protected requests are unauthenticated or redirected.

### Requirement: Auth Scratch DB Tables

Auth integration tests MUST create scratch database auth tables per run and MUST NOT use or mutate the shared `vo_test` schema.

#### Scenario: Scratch auth schema lifecycle
- GIVEN auth integration tests start
- WHEN migrations including `002` run
- THEN auth runtime tables exist only in the scratch DB
- AND teardown removes the scratch DB without touching non-scratch schemas.
