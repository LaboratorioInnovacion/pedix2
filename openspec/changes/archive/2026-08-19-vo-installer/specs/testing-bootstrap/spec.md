# Delta for Testing Bootstrap

## ADDED Requirements

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
