# Web Installer Specification

## Purpose

Defines the pre-auth shared-hosting installer that provisions the first business, branch, admin, baseline schema, generated private config, and fail-closed lock.

## Requirements

### Requirement: Wizard Flow

The installer MUST expose only this ordered flow: requirements check, DB test, business/branch/admin form, review/install, completion.

#### Scenario: Step order enforced
- GIVEN an uninstalled system
- WHEN a user opens `/install/`
- THEN the requirements step is shown first
- AND later steps are unavailable until earlier required inputs pass.

### Requirement: Requirement Checks

The installer MUST verify PHP >=8.1, `pdo_mysql`, writable `api/config`, writable `api/storage`, and no existing config overlay before continuing.

#### Scenario: Requirement failure blocks setup
- GIVEN any required check fails
- WHEN the requirements step is submitted
- THEN DB and admin steps MUST NOT be reachable
- AND the response shows only sanitized remediation text.

### Requirement: DB Test Sanitization

The installer MUST test MySQL/MariaDB connectivity and MUST NOT echo credentials, tokens, full DSNs, or password hashes.

#### Scenario: Bad DB credentials
- GIVEN invalid DB credentials
- WHEN the DB test runs
- THEN the response reports connection failure
- AND submitted secrets are absent from HTML, logs, and session after failure.

### Requirement: Initial Data Validation

The installer MUST require business name, branch name, valid admin email, and a minimum-strength confirmed password; branch address and phone MAY be optional.

#### Scenario: Valid initial data accepted
- GIVEN valid business, branch, and admin values
- WHEN the form is submitted
- THEN the review step can proceed
- AND the admin password is stored only as a `password_hash()` result.

### Requirement: CSRF Protection

Every installer POST MUST require a valid pre-auth session CSRF token.

#### Scenario: Missing CSRF rejected
- GIVEN a POST without a valid token
- WHEN any installer action is submitted
- THEN the action is rejected
- AND no DB, config, or session state change occurs.

### Requirement: Install Execution and Failure Safety

Install MUST run migrations, seed data transactionally where feasible, write audit entry, then atomically write the config/lock LAST; failures MUST leave no valid lock and SHOULD allow safe re-run without duplicate partial state where feasible.

#### Scenario: Successful install locks last
- GIVEN valid review data and DB access
- WHEN installation runs
- THEN migrations, business, branch, owner role, permissions, admin, assignments, settings, and audit entry exist
- AND the private config/lock is written only after success.

#### Scenario: Partial failure remains rerunnable
- GIVEN a migration or seed failure
- WHEN installation aborts
- THEN no valid installed lock exists
- AND re-running installation does not create duplicate feasible seed state.

### Requirement: Fail-Closed Lock

The installer MUST check the private installed lock before rendering any step; after lock, `/install/` MUST return a locked response with no forms and no config disclosure, even if DB is down.

#### Scenario: Installed access blocked
- GIVEN the installed lock exists
- WHEN any installer URL is requested
- THEN the locked response is returned before DB access
- AND no form, secret, or config detail is disclosed.

### Requirement: Installer Scope Guard

The installer MUST NOT implement runtime login/auth endpoints, admin panel behavior, catalog, orders, payments, delivery, customer accounts, updater, or backups.

#### Scenario: Deferred feature absent
- GIVEN installer completion
- WHEN deferred runtime functionality is requested
- THEN this change provides no such behavior.
