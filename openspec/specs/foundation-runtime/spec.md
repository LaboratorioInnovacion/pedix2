# Foundation Runtime

## Requirements

### Requirement: Layout
Private code/config/logs/migrations/storage MUST stay under `api/`; only `public_html/` SHALL serve web.
#### Scenario: Private path blocked
- GIVEN deployment
- WHEN public request targets `api/`
- THEN private content is not served

### Requirement: Bootstrap config
Bootstrap MUST load private paths/config; missing config MUST fail closed with non-secret error.
#### Scenario: Missing config
- GIVEN missing required config
- WHEN bootstrap starts
- THEN startup fails without secrets

### Requirement: Health route
`public_html/index.php` MUST route requests; this change SHALL expose only `/health`.
#### Scenario: Health route
- GIVEN bootstrapped runtime
- WHEN `/health` is requested
- THEN health response returns

### Requirement: PDO boundary
Database access MUST use lazy PDO, fail closed, and prepare statements only.
#### Scenario: Prepared query
- GIVEN SQL parameters
- WHEN it executes
- THEN prepared statement is used

### Requirement: Migrations
Migrations MUST create `schema_migrations`, apply pending versions once, record versions, and refuse duplicates.
#### Scenario: Run twice
- GIVEN pending migration
- WHEN migrations run twice
- THEN it applies once and records version

### Requirement: Middleware order
Request ID middleware MUST run first; security headers MUST cover every response.
#### Scenario: Error response
- GIVEN failing request
- WHEN middleware completes
- THEN request ID and headers exist

### Requirement: Security headers
Responses MUST include CSP, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, and `frame-ancestors`.
#### Scenario: Public response
- GIVEN public response
- WHEN emitted
- THEN baseline headers exist

### Requirement: Foundation scope guard
This change MUST NOT introduce auth, catalog, pricing engine, orders, payments, or delivery logic.
#### Scenario: Business route absent
- GIVEN foundation complete
- WHEN deferred business behavior is requested
- THEN this change does not implement it

### Requirement: Generated Config Overlay

Runtime config loading MUST merge `api/config/installed.php` over static defaults when the private overlay exists, while preserving private path boundaries and non-secret failure messages.

#### Scenario: Overlay present
- GIVEN static defaults and a private installed overlay
- WHEN `Config` loads runtime configuration
- THEN overlay values take precedence
- AND the overlay path remains outside `public_html`.

#### Scenario: Overlay missing
- GIVEN no private installed overlay exists
- WHEN installer status is evaluated
- THEN the system treats installation as not installed
- AND normal private path secrecy remains unchanged.

### Requirement: Runtime Scope Guard

This change MUST NOT add runtime login/auth endpoints, admin panel behavior, catalog, orders, payments, delivery, customer accounts, updater, or backups to foundation runtime.

#### Scenario: Runtime remains minimal
- GIVEN the config overlay behavior exists
- WHEN runtime routes are reviewed
- THEN deferred product features are still absent.

<!-- ===== Added by change vo-auth (archived 2026-08-19) ===== -->

### Requirement: Method-Aware Routing

Runtime routing MUST support method-aware routes for at least GET and POST, MUST dispatch by both path and method, and MUST return 405 when a known path is requested with an unsupported method.

#### Scenario: POST route dispatch
- GIVEN a POST route is registered
- WHEN a matching POST request arrives
- THEN the POST handler is executed.

#### Scenario: Wrong method rejected
- GIVEN a path exists for GET only
- WHEN the same path is requested with POST
- THEN the response status is 405.

### Requirement: Form POST Body Parsing

The HTTP request boundary MUST provide a helper for `application/x-www-form-urlencoded` POST body values while preserving existing JSON response behavior.

#### Scenario: Form body available
- GIVEN a form-encoded POST request
- WHEN the request is inspected by a controller
- THEN submitted fields are available through the request helper.

#### Scenario: JSON boundary unchanged
- GIVEN an existing JSON runtime route
- WHEN the route returns a JSON response
- THEN existing JSON response behavior and headers remain unchanged.
