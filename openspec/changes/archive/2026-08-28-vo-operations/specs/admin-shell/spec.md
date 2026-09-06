# Delta for Admin Shell

## ADDED Requirements

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

## MODIFIED Requirements

### Requirement: Admin Shell Scope Guard

The admin shell MUST NOT implement delivery, customer account management, password reset, remember-me, or user/role management UI. Catalog, orders, operations, promotions, payments, and configuration pages MAY exist only as explicitly specified by their feature specs; the operation board (`/admin/operacion`) is spec-governed by the operations capability and is not an out-of-scope boundary.
(Previously: the guard listed "operation/delivery boundaries" among MUST-NOT-implement features with no operation page allowed.)

#### Scenario: Out-of-scope features absent

- GIVEN the admin shell is reachable
- WHEN delivery, customer account, reset, remember-me, role, or user-management behavior is requested
- THEN this change provides no such behavior.

#### Scenario: Operation board is in scope

- GIVEN the operations capability is specified
- WHEN `/admin/operacion` is requested by an authorized operator
- THEN the admin shell serves the board instead of treating it as an out-of-scope boundary.
