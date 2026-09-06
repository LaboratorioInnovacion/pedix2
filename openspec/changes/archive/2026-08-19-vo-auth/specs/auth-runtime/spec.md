# Auth Runtime Specification

## Purpose

Defines admin auth.

## Requirements

### Requirement: Admin Login Boundary

The system MUST accept admin login only through `POST /admin/login`, verify with `password_verify()`, return generic failures for unknown, inactive, locked, or invalid credentials, and use a dummy hash check when no user hash exists.

#### Scenario: Successful login
- GIVEN an active admin user with a valid password hash
- WHEN `POST /admin/login` submits valid credentials
- THEN authentication succeeds.

#### Scenario: Generic failed login
- GIVEN an unknown, inactive, locked, or wrong-password login attempt
- WHEN `POST /admin/login` is submitted
- THEN the response uses one generic failure message
- AND no plaintext secret is disclosed.

### Requirement: Session Creation

On successful auth, the system MUST regenerate the PHP session id, set a hardened cookie, and create an `auth_sessions` row containing SHA-256 session id hash, user id, created, last_seen, absolute_expiry, and revoked_at.

#### Scenario: New authenticated session
- GIVEN valid credentials
- WHEN login completes
- THEN the PHP session id is regenerated
- AND an unrevoked `auth_sessions` row exists for the new id hash.

### Requirement: Session Cookie Policy

Session cookies MUST be `HttpOnly`, `SameSite=Lax`, and `Secure` when direct HTTPS is detected.

#### Scenario: HTTPS login cookie
- GIVEN the request is served over direct HTTPS
- WHEN login sets the session cookie
- THEN the cookie includes all three flags.

### Requirement: Per-Request Session Validation

Authenticated requests MUST validate the DB session exists, is not revoked, idle age is under 30 minutes, and absolute age is under 12 hours; valid requests MUST update last_seen.

#### Scenario: Valid session refresh
- GIVEN an unrevoked session within time limits
- WHEN an authenticated request runs
- THEN access is allowed
- AND last_seen is updated.

#### Scenario: Expired or revoked session
- GIVEN a missing, revoked, or expired session
- WHEN an authenticated request runs
- THEN authentication is rejected.

### Requirement: Logout Revocation

Logout MUST revoke the current `auth_sessions` row immediately and MUST be idempotent.

#### Scenario: Repeated logout
- GIVEN an authenticated or already revoked session
- WHEN logout is submitted repeatedly
- THEN the session is revoked
- AND repeated submissions do not create an error state.

### Requirement: Login Lockout

Failed logins MUST be tracked in `login_attempts` by normalized identifier hash plus IP hash; threshold and duration MUST be configurable, defaulting to 5 failures per window and 15 minutes, and successful login MUST clear attempts.

#### Scenario: Lockout trigger
- GIVEN five failures for the same identifier and IP window
- WHEN another login is attempted before lockout expiry
- THEN login is blocked generically
- AND a lockout audit event is recorded.

#### Scenario: Success clears attempts
- GIVEN prior failed attempts below lockout
- WHEN valid credentials are submitted
- THEN login succeeds
- AND the attempt state is cleared or marked successful.

### Requirement: Auth Audit Events

The system MUST audit login success, login failure, lockout trigger, and logout with request_id, safe metadata, SHA-256 identifier hash, and no plaintext secrets.

#### Scenario: Failed login audit safety
- GIVEN an invalid login attempt
- WHEN audit is written
- THEN metadata includes request_id and identifier hash
- AND excludes passwords, raw session ids, tokens, and plaintext identifiers.

### Requirement: Auth Runtime Migration 002

Migration `002` MUST create `auth_sessions` and `login_attempts` with indexes for session lookup, revocation/expiry cleanup, lockout lookup, and stale attempt cleanup.

#### Scenario: Tables created
- GIVEN migrations run on an installed database
- WHEN migration `002` completes
- THEN both tables and indexes exist.

### Requirement: Auth Scope Guard

This phase MUST NOT implement remember-me, password reset, operation or delivery login, user/role management UI, catalog, orders, payments, delivery, or customer behavior.

#### Scenario: Deferred auth features absent
- GIVEN Phase 3 auth runtime is installed
- WHEN deferred auth or business routes are requested
- THEN this change provides no such behavior.
