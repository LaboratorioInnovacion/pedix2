# RBAC Authorization Specification

## Purpose

Defines server-side permission, branch-scope, authorization audit, and CSRF requirements for authenticated admin operations.

## Requirements

### Requirement: Permission Guard

The system MUST provide `requirePermission(key)` semantics that authorize by permission key, not role name, and denied requests MUST return 403 with an audit denial containing request_id.

#### Scenario: Permission allowed
- GIVEN an authenticated user with a role granting `settings.manage`
- WHEN `requirePermission('settings.manage')` runs
- THEN the request is allowed.

#### Scenario: Permission denied
- GIVEN an authenticated user without the required permission
- WHEN `requirePermission(key)` runs
- THEN the response is 403
- AND an authorization-denial audit event includes request_id.

### Requirement: Branch Scope Guard

Operational branch-scoped actions MUST call `requireBranchScope(branchId)` and MUST require an explicit `user_branches` assignment for that user and branch.

#### Scenario: Explicit branch assignment required
- GIVEN a user has the permission but no matching `user_branches` row
- WHEN a branch-scoped action is requested
- THEN access is denied with 403.

#### Scenario: Assigned branch allowed
- GIVEN a user has the permission and explicit branch assignment
- WHEN a branch-scoped action is requested
- THEN access is allowed.

### Requirement: Global Action Semantics

Global actions `users.manage`, `settings.manage`, and `reports.view` MUST require permission only and MUST NOT require branch scope.

#### Scenario: Global permission path
- GIVEN a user has `users.manage` and no branch assignment
- WHEN a users-management guarded action is checked
- THEN authorization depends only on the permission.

### Requirement: Server-Side Enforcement

Authorization guards MUST run server-side for protected behavior regardless of UI visibility, client-side checks, or hidden controls.

#### Scenario: Direct request denied
- GIVEN a client calls a protected endpoint directly
- WHEN the server-side guard fails
- THEN the request is denied even if the UI would have hidden the action.

### Requirement: CSRF Middleware

The system MUST issue a per-session CSRF token, compare submitted tokens with `hash_equals`, and require a valid token on all authenticated state-changing POST requests; failure MUST produce a 419-style rejection and no state change.

#### Scenario: Valid authenticated POST
- GIVEN an authenticated session and matching CSRF token
- WHEN a state-changing POST is submitted
- THEN the request may proceed to authorization.

#### Scenario: Invalid authenticated POST
- GIVEN an authenticated session with missing or bad CSRF token
- WHEN a state-changing POST is submitted
- THEN the response is 419-style rejection
- AND no state change occurs.

### Requirement: Authorization Scope Guard

This phase MUST NOT add operation/delivery boundaries, business modules, user or role management UI, catalog, orders, payments, delivery, or customer behavior.

#### Scenario: Deferred guarded modules absent
- GIVEN authorization guards exist
- WHEN deferred module URLs or screens are requested
- THEN no deferred business behavior is implemented.
