# Admin Shell Specification

## Purpose

Defines the first Spanish server-rendered `/admin/` shell for login, protected dashboard placeholder, and logout only.

## Requirements

### Requirement: Admin Front Controller

`public_html/admin/` MUST serve server-rendered admin pages through a dedicated front controller and MUST keep private code outside `public_html`.

#### Scenario: Admin entry served
- GIVEN the system is installed
- WHEN `/admin/` is requested
- THEN the admin front controller handles the request.

### Requirement: Login Form CSRF

`GET /admin/login` MUST render a Spanish login form and issue a per-session CSRF token for the pre-auth login POST, following the installer pre-auth POST pattern.

#### Scenario: Login form contains token
- GIVEN an unauthenticated browser
- WHEN `GET /admin/login` is requested
- THEN a Spanish login form is returned
- AND a CSRF token is available for `POST /admin/login`.

### Requirement: Login Submission

`POST /admin/login` MUST be rate-limited, MUST require the login CSRF token, and MUST use generic auth errors.

#### Scenario: Valid login reaches dashboard
- GIVEN a login form session and valid credentials
- WHEN `POST /admin/login` is submitted with a valid token
- THEN the user is authenticated
- AND the response leads to `/admin/`.

#### Scenario: Invalid login remains generic
- GIVEN a login form session
- WHEN invalid credentials are submitted
- THEN the page shows a generic Spanish failure
- AND no user enumeration is possible.

### Requirement: Protected Dashboard Placeholder

`GET /admin/` MUST require authentication and MUST show only the business name, current user, and placeholder admin sections without business data features.

#### Scenario: Dashboard shell
- GIVEN an authenticated admin session
- WHEN `GET /admin/` is requested
- THEN the dashboard shell shows business name, user, and placeholder sections.

#### Scenario: Unauthenticated dashboard redirect
- GIVEN no valid authenticated session
- WHEN `GET /admin/` is requested
- THEN the response redirects to `/admin/login`.

### Requirement: Logout Form

`POST /admin/logout` MUST require authentication and CSRF, revoke the session, and return the browser to the login boundary.

#### Scenario: Logout succeeds
- GIVEN an authenticated admin session
- WHEN `POST /admin/logout` is submitted with a valid CSRF token
- THEN the session is revoked
- AND subsequent dashboard access redirects to login.

### Requirement: Admin Shell Scope Guard

The admin shell MUST NOT implement catalog, orders, payments, delivery, customer behavior, operation/delivery boundaries, password reset, remember-me, or user/role management UI.

#### Scenario: Business features absent
- GIVEN the admin shell is reachable
- WHEN catalog, orders, payments, delivery, customer, role, or user-management behavior is requested
- THEN this change provides no such behavior.
