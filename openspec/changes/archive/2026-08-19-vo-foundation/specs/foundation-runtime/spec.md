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
