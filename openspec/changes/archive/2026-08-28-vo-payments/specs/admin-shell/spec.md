# Delta for Admin Shell

## ADDED Requirements

### Requirement R1: Payments admin section
The admin shell MUST expose `/admin/pagos` for authenticated scoped users to list payments by state/method and view payment detail/actions without leaking proofs directly.

#### Scenario: Payment list filters
- GIVEN an authenticated admin with branch scope
- WHEN `/admin/pagos?state=pending_verification&method=transfer` is opened
- THEN only matching scoped payments are listed.

#### Scenario: Proof download protected
- GIVEN a stored proof exists
- WHEN an authorized admin opens the proof route
- THEN the file is streamed through the application.

### Requirement R2: Transfer payment actions
The admin shell MUST require CSRF and `payments.verify_transfer` for transfer verify/reject actions and MUST audit successful actions.

#### Scenario: Verify action succeeds
- GIVEN an authorized admin and pending transfer
- WHEN they submit verify with CSRF
- THEN payment becomes `verified`, order auto-accept may run, and audit is written.

#### Scenario: Reject action succeeds
- GIVEN an authorized admin and pending transfer
- WHEN they submit reject with CSRF
- THEN payment becomes `rejected`, order remains pending, and audit is written.

### Requirement R3: Payment settings page
The admin shell MUST expose `/admin/configuracion` to users with `settings.manage` for Mercado Pago credentials, MP enablement, transfer instructions, and payment expiry settings.

#### Scenario: Settings saved
- GIVEN an authorized admin posts valid settings with CSRF
- WHEN the settings form is saved
- THEN business settings are updated and secrets are not echoed in full.

## MODIFIED Requirements

### Requirement: Admin Shell Scope Guard
The admin shell MUST NOT implement delivery, customer account management, operation/delivery boundaries, password reset, remember-me, or user/role management UI. Catalog, orders, promotions, payments, and configuration pages MAY exist only as explicitly specified by their feature specs.
(Previously: the shell forbade catalog, orders, payments, delivery, customer, role, and user-management behavior.)

#### Scenario: Out-of-scope features absent
- GIVEN the admin shell is reachable
- WHEN delivery, customer account, operation/delivery, reset, remember-me, role, or user-management behavior is requested
- THEN this change provides no such behavior.
