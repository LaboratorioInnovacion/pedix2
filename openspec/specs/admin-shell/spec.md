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

### Requirement: Payments admin section
The admin shell MUST expose `/admin/pagos` for authenticated scoped users to list payments by state/method and view payment detail/actions without leaking proofs directly.

#### Scenario: Payment list filters
- GIVEN an authenticated admin with branch scope
- WHEN `/admin/pagos?state=pending_verification&method=transfer` is opened
- THEN only matching scoped payments are listed.

#### Scenario: Proof download protected
- GIVEN a stored proof exists
- WHEN an authorized admin opens the proof route
- THEN the file is streamed through the application.

### Requirement: Transfer payment actions
The admin shell MUST require CSRF and `payments.verify_transfer` for transfer verify/reject actions and MUST audit successful actions.

#### Scenario: Verify action succeeds
- GIVEN an authorized admin and pending transfer
- WHEN they submit verify with CSRF
- THEN payment becomes `verified`, order auto-accept may run, and audit is written.

#### Scenario: Reject action succeeds
- GIVEN an authorized admin and pending transfer
- WHEN they submit reject with CSRF
- THEN payment becomes `rejected`, order remains pending, and audit is written.

### Requirement: Payment settings page
The admin shell MUST expose `/admin/configuracion` to users with `settings.manage` for Mercado Pago credentials, MP enablement, transfer instructions, payment expiry settings, and the notifications section: master `notifications_enabled`, channel flags `notifications_email_enabled` / `notifications_whatsapp_enabled` (checkboxes), SMTP fields (`smtp_host`, `smtp_port`, `smtp_username`, `smtp_password`, `smtp_from_email`, `smtp_from_name`), and WhatsApp fields (`whatsapp_bridge_url`, `whatsapp_bridge_token`). `smtp_password` and `whatsapp_bridge_token` MUST follow the existing write-only secret policy: empty input keeps the stored value, saved values are never echoed back, and the `settings.updated` audit entry records changed key names only.

#### Scenario: Settings saved
- GIVEN an authorized admin posts valid settings with CSRF
- WHEN the settings form is saved
- THEN business settings are updated and secrets are not echoed in full.

#### Scenario: Notifications flags saved
- GIVEN an authorized admin submits the form with `notifications_enabled=1` and both channel flags
- WHEN the form is saved
- THEN the three flags persist as `'1'` for the business.

#### Scenario: SMTP and bridge secrets follow write-only policy
- GIVEN `smtp_password` and `whatsapp_bridge_token` already stored
- WHEN the settings page renders and a new form is saved leaving those inputs empty
- THEN the page never contains the stored values and the stored values remain unchanged.

### Requirement: Notifications log page
The admin shell MUST expose `GET /admin/notificaciones` as a read-only notifications log defined by the notifications capability: authentication required, visibility gated by `settings.manage` with audited 403 denials, newest-first listing of `notification_events`, and a best-effort dispatch sweep on load.

#### Scenario: Log page requires settings.manage
- GIVEN an authenticated admin without `settings.manage`
- WHEN `/admin/notificaciones` is requested
- THEN the response is 403 and an `authz.denied` audit entry records the missing permission.

#### Scenario: Authorized admin sees the log
- GIVEN an authorized admin and existing notification rows
- WHEN `/admin/notificaciones` is requested
- THEN the page lists event, channel, recipient, state, attempts, and last error, with no mutation actions.

### Requirement: Notifications dashboard navigation
The dashboard MUST include a "Notificaciones" navigation card linking to `/admin/notificaciones`.

#### Scenario: Dashboard links the log
- GIVEN an authenticated admin
- WHEN the dashboard renders
- THEN a "Notificaciones" card links to `/admin/notificaciones`.

### Requirement: Operation board
The admin shell MUST expose `/admin/operacion` as the Spanish operations board defined by the operations capability: authentication required, visibility gated by `orders.view`, POST transition actions CSRF-protected and gated by each transition's permission, with all denials audited as `authz.denied`.

#### Scenario: Board requires view permission
- GIVEN an authenticated admin without `orders.view`
- WHEN `/admin/operacion` is requested
- THEN the response is 403
- AND an `authz.denied` audit entry records the missing permission.

### Requirement: Order transition actions
Order detail (`/admin/pedidos/{id}`) MUST show the same legal transition buttons as the board plus a cancel form requiring a reason; each action MUST require a valid CSRF token and the per-transition permission and MUST redirect back to the detail after success. The orders list (`/admin/pedidos`) MUST stay free of transition buttons.

#### Scenario: Detail cancel with reason
- GIVEN an authorized operator opens a `pending` order detail
- WHEN they submit cancel with a reason and valid CSRF
- THEN the order is cancelled
- AND the page redirects back to the detail.

#### Scenario: Detail action without CSRF rejected
- GIVEN a valid operator session
- WHEN a transition is posted without a valid CSRF token
- THEN the request is rejected with 419 and the order is unchanged.

### Requirement: Operation board navigation
The dashboard MUST include an "Operación" navigation card linking to `/admin/operacion`.

#### Scenario: Dashboard links the board
- GIVEN an authenticated admin
- WHEN the dashboard renders
- THEN an "Operación" card links to `/admin/operacion`.

### Requirement: Reportes dashboard navigation
The dashboard MUST include a "Reportes" navigation card linking to `/admin/reportes`, replacing the current "Módulo pendiente" placeholder card.

#### Scenario: Dashboard links the report
- GIVEN an authenticated admin
- WHEN the dashboard renders
- THEN a "Reportes" card links to `/admin/reportes`.

### Requirement: Admin Shell Scope Guard
The admin shell MUST NOT implement delivery, customer account management, password reset, remember-me, or user/role management UI. Catalog, orders, operations, promotions, payments, configuration, notifications-log, and reports pages MAY exist only as explicitly specified by their feature specs; the operation board (`/admin/operacion`) is spec-governed by the operations capability, the notifications log (`/admin/notificaciones`) is spec-governed by the notifications capability, and the Ventas report (`/admin/reportes`) is spec-governed by the reports capability — none is an out-of-scope boundary.

#### Scenario: Out-of-scope features absent
- GIVEN the admin shell is reachable
- WHEN delivery, customer account, reset, remember-me, role, or user-management behavior is requested
- THEN this change provides no such behavior.

#### Scenario: Operation board is in scope
- GIVEN the operations capability is specified
- WHEN `/admin/operacion` is requested by an authorized operator
- THEN the admin shell serves the board instead of treating it as an out-of-scope boundary.

#### Scenario: Notifications log is in scope
- GIVEN the notifications capability is specified
- WHEN `/admin/notificaciones` is requested by an authorized admin
- THEN the admin shell serves the read-only log instead of treating it as an out-of-scope boundary.

#### Scenario: Reports page is in scope
- GIVEN the reports capability is specified
- WHEN `/admin/reportes` is requested by an authorized admin
- THEN the admin shell serves the Ventas report instead of treating it as an out-of-scope boundary.
