# Delta for Testing Bootstrap

## ADDED Requirements

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
