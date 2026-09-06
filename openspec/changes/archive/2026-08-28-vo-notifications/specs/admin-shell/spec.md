# Delta for Admin Shell

## MODIFIED Requirements

### Requirement: Payment settings page

The admin shell MUST expose `/admin/configuracion` to users with `settings.manage` for Mercado Pago credentials, MP enablement, transfer instructions, payment expiry settings, and the notifications section: master `notifications_enabled`, channel flags `notifications_email_enabled` / `notifications_whatsapp_enabled` (checkboxes), SMTP fields (`smtp_host`, `smtp_port`, `smtp_username`, `smtp_password`, `smtp_from_email`, `smtp_from_name`), and WhatsApp fields (`whatsapp_bridge_url`, `whatsapp_bridge_token`). `smtp_password` and `whatsapp_bridge_token` MUST follow the existing write-only secret policy: empty input keeps the stored value, saved values are never echoed back, and the `settings.updated` audit entry records changed key names only.
(Previously: the page covered only Mercado Pago and transfer settings.)

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

## ADDED Requirements

### Requirement A1: Notifications log page

The admin shell MUST expose `GET /admin/notificaciones` as a read-only notifications log defined by the notifications capability: authentication required, visibility gated by `settings.manage` with audited 403 denials, newest-first listing of `notification_events`, and a best-effort dispatch sweep on load.

#### Scenario: Log page requires settings.manage

- GIVEN an authenticated admin without `settings.manage`
- WHEN `/admin/notificaciones` is requested
- THEN the response is 403 and an `authz.denied` audit entry records the missing permission.

#### Scenario: Authorized admin sees the log

- GIVEN an authorized admin and existing notification rows
- WHEN `/admin/notificaciones` is requested
- THEN the page lists event, channel, recipient, state, attempts, and last error, with no mutation actions.

### Requirement A2: Notifications dashboard navigation

The dashboard MUST include a "Notificaciones" navigation card linking to `/admin/notificaciones`.

#### Scenario: Dashboard links the log

- GIVEN an authenticated admin
- WHEN the dashboard renders
- THEN a "Notificaciones" card links to `/admin/notificaciones`.

## MODIFIED Requirements (scope guard)

### Requirement: Admin Shell Scope Guard

The admin shell MUST NOT implement delivery, customer account management, password reset, remember-me, or user/role management UI. Catalog, orders, operations, promotions, payments, configuration, and notifications-log pages MAY exist only as explicitly specified by their feature specs; the operation board (`/admin/operacion`) is spec-governed by the operations capability and the notifications log (`/admin/notificaciones`) is spec-governed by the notifications capability — neither is an out-of-scope boundary.
(Previously: the permitted-pages list did not include the notifications log.)

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
