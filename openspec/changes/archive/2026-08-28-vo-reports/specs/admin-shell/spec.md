# Delta for Admin Shell

## ADDED Requirements

### Requirement: Reportes dashboard navigation

The dashboard MUST include a "Reportes" navigation card linking to `/admin/reportes`, replacing the current "Módulo pendiente" placeholder card.

#### Scenario: Dashboard links the report

- GIVEN an authenticated admin
- WHEN the dashboard renders
- THEN a "Reportes" card links to `/admin/reportes`.

## MODIFIED Requirements

### Requirement: Admin Shell Scope Guard

The admin shell MUST NOT implement delivery, customer account management, password reset, remember-me, or user/role management UI. Catalog, orders, operations, promotions, payments, configuration, notifications-log, and reports pages MAY exist only as explicitly specified by their feature specs; the operation board (`/admin/operacion`) is spec-governed by the operations capability, the notifications log (`/admin/notificaciones`) is spec-governed by the notifications capability, and the Ventas report (`/admin/reportes`) is spec-governed by the reports capability — none is an out-of-scope boundary.

(Previously: the scope guard named operacion and notificaciones as spec-governed exceptions; reports is now added as a third spec-governed page.)

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
