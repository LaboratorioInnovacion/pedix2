# Payments Specification

## Purpose

Defines Phase 8 payment persistence, state transitions, proof handling, Mercado Pago confirmation, and payment settings.

## Requirements

### Requirement R1: Payment record lifecycle
The system MUST create one payment row for non-cash orders with integer amount/currency snapshots: transfer starts `pending_verification`, Mercado Pago starts `pending`, and cash creates no payment row.

#### Scenario: Non-cash order gets payment
- GIVEN an order confirmed with transfer or Mercado Pago
- WHEN payment initiation runs
- THEN a payment row stores order, business, method, amount cents, currency, and initial state.

#### Scenario: Cash stays order-only
- GIVEN an order confirmed with cash
- WHEN payment initiation runs
- THEN no payment row is created.

### Requirement R2: Payment state machine
The system MUST allow only `pending`, `approved`, `rejected`, `cancelled`, `pending_verification`, `verified`, `refund_pending`, `refund_completed`, with refund states present but not reachable by V1 public/admin operations.

#### Scenario: Illegal transition rejected
- GIVEN a transfer payment is `verified`
- WHEN rejection is requested
- THEN the transition is rejected and state remains unchanged.

### Requirement R3: Transfer verification flow
The system MUST let customers upload a transfer proof for their public order token and MUST let authorized admins verify or reject `pending_verification` transfers with audit records.

#### Scenario: Admin verifies transfer
- GIVEN a transfer payment with a valid proof
- WHEN an admin with `payments.verify_transfer` verifies it
- THEN payment becomes `verified` with verifier/timestamp and an audit record is written.

#### Scenario: Unauthorized verification blocked
- GIVEN an admin without the payment permission
- WHEN they submit verification
- THEN the request is denied and no payment state changes.

### Requirement R4: Mercado Pago webhook flow
The system MUST validate webhook signatures, confirm critical status through Mercado Pago GET, persist provider events, and apply idempotent state transitions without trusting the webhook body alone.

#### Scenario: Valid duplicate webhook
- GIVEN a Mercado Pago event was already processed
- WHEN the same signed webhook arrives again
- THEN the endpoint returns success without duplicating state changes.

#### Scenario: Forged webhook rejected
- GIVEN an invalid `x-signature`
- WHEN the webhook is posted
- THEN the endpoint returns unauthorized and no payment transition occurs.

### Requirement R5: Paid orders auto-accept
The system MUST auto-transition the linked order from `pending` to `accepted` exactly once when payment becomes `verified` or `approved`.

#### Scenario: Pending order accepted
- GIVEN a pending order has a transfer payment
- WHEN payment becomes `verified`
- THEN the order becomes `accepted`.

#### Scenario: Non-pending order unchanged
- GIVEN the linked order is not `pending`
- WHEN payment becomes `approved`
- THEN the order state is not overwritten.

### Requirement R6: Proof storage security
The system MUST store proofs outside `public_html`, use generated filenames, allow only jpg/png/pdf with real MIME checks, enforce a 5MB limit, and expose downloads only through authorized admin routes.

#### Scenario: Invalid proof rejected
- GIVEN an upload has a disallowed extension, MIME, or size
- WHEN proof upload is submitted
- THEN it is rejected and no public file URL is stored.

### Requirement R7: Payment settings and lazy expiry
The system MUST store `mp_access_token`, `mp_webhook_secret`, `mp_enabled`, `transfer_instructions`, and expiry hours in business settings, and MUST lazily cancel stale `pending`/`pending_verification` payments on payment reads.

#### Scenario: Stale payment expires
- GIVEN a pending payment is older than configured expiry hours
- WHEN the payment is read
- THEN it becomes `cancelled` and the linked order remains unchanged.
