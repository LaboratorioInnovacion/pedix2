# Operations Specification

## Purpose

Defines the operator-facing order lifecycle (Phase 9): one audited transition gateway with per-transition permissions, the `/admin/operacion` board, cancellation semantics with stock and payment side effects, stock consume-on-accept, and lazy order expiry.

## Requirements

### Requirement O1: Single transition gateway

All operator order status changes (accept, reject, start preparation, mark ready, complete, cancel) MUST go through one service gateway that validates the current status and enforces the order state map; illegal transitions MUST be rejected without any side effect. The board and order detail MUST only expose actions legal for the current status; no manual expire action is exposed (expiry is sweep-owned).

#### Scenario: Kitchen chain executes

- GIVEN an order is `accepted`
- WHEN an operator starts preparation, then marks ready, then completes it
- THEN status becomes `in_progress`, then `ready`, then `completed`.

#### Scenario: Illegal transition rejected

- GIVEN an order is `completed`
- WHEN accept is requested through the gateway
- THEN the gateway rejects the transition
- AND status, stock, and payments are unchanged.

### Requirement O2: Per-transition permission enforcement

The gateway MUST require the seeded permission for the target status before mutating: accept→`orders.accept`, reject→`orders.reject`, cancel→`orders.cancel`, start preparation→`orders.prepare`, mark ready→`orders.mark_ready`, complete→`orders.prepare`. Denials MUST NOT mutate the order and MUST be audited as `authz.denied`. Board visibility requires `orders.view`.

#### Scenario: Operator without cancel permission denied

- GIVEN an operator lacks `orders.cancel`
- WHEN they submit cancel for a `ready` order
- THEN the request is rejected, the order stays `ready`, and `authz.denied` is audited.

#### Scenario: Authorized accept succeeds

- GIVEN an operator holds `orders.accept`
- WHEN they accept a `pending` order
- THEN the order becomes `accepted`.

### Requirement O3: Audited transitions

Every successful transition MUST write one audit entry `orders.{target}` with entity `order:{id}` and metadata containing from, to, reason (when present), `refund_required` when applicable, and the current request id. Sweep expiries MUST be audited the same way with a system actor.

#### Scenario: Accept audit trail

- GIVEN a `pending` order
- WHEN an operator accepts it
- THEN audit_log contains `orders.accepted` with from `pending`, to `accepted`, actor user id, and non-empty request id.

### Requirement O4: Operations board

`GET /admin/operacion` MUST render Spanish sections for `pending`, `change_proposed`, `accepted`, `in_progress`, and `ready`, scoped to the operator's branches, where each order card shows number, branch, item count, total, and age, plus POST-form buttons (CSRF) for that status's legal transitions. The board MUST offer a branch filter and manual refresh; `?auto=1` MUST add a 30-second meta-refresh. Unauthenticated requests MUST redirect to login; users without `orders.view` MUST get 403 with an `authz.denied` audit.

#### Scenario: Board sections render

- GIVEN orders exist in `pending` and `ready` statuses within the operator's branch
- WHEN the board is loaded
- THEN each order appears under its status section with number, branch, total, age, and only legal action buttons.

#### Scenario: Branch filter narrows the board

- GIVEN orders exist for two branches
- WHEN the board is filtered to one branch
- THEN only that branch's orders are shown.

### Requirement O5: Cancellation semantics

Cancel MUST require a non-empty reason and MUST release the order's reserved simple stock with reason `release_cancelled`. With a linked payment: `pending`/`pending_verification` payments MUST be cancelled; `approved` payments MUST stay approved and the cancellation audit MUST carry `refund_required=true`; rejected/cancelled payments MUST be left untouched. Reject MUST release reserved stock with reason `release_rejected` and MAY record a reason.

#### Scenario: Cancel cash order releases stock

- GIVEN a `pending` cash order with 3 reserved units of one simple item
- WHEN an operator cancels with reason "cliente se arrepintió"
- THEN the order is `cancelled`, reserved quantity drops by 3, and a `release_cancelled` movement is appended.

#### Scenario: Cancel without reason rejected

- GIVEN a `pending` order
- WHEN cancel is submitted with an empty reason
- THEN the gateway rejects it and the order stays `pending`.

#### Scenario: Cancel cancels pending payment

- GIVEN a `pending` order with a `pending_verification` payment
- WHEN the order is cancelled
- THEN the payment becomes `cancelled`.

#### Scenario: Approved payment stays with refund flag

- GIVEN an `accepted` order whose payment is `approved`
- WHEN the order is cancelled
- THEN the payment stays `approved` and the audit entry has `refund_required=true`.

### Requirement O6: Stock consume on acceptance

Accepting an order MUST consume its reserved simple stock: physical and reserved quantities both decrease by the ordered quantity and a `consume_accepted` movement is appended. Consume MUST be guarded so a repeated consume for the same lines is a no-op without negative quantities or duplicate movements. Payment-driven auto-accept MUST consume stock through the same gateway path.

#### Scenario: Accept consumes once

- GIVEN a `pending` order with 2 reserved units
- WHEN accept runs twice (retry)
- THEN stock and reserved quantities decreased exactly once and exactly one `consume_accepted` movement exists.

#### Scenario: Auto-accept consumes stock

- GIVEN a `pending` transfer order whose payment becomes `verified`
- WHEN the order auto-accepts
- THEN reserved simple stock is consumed with a `consume_accepted` movement.

### Requirement O7: Lazy expiry sweep

Orders in `pending`/`change_proposed` older than the configured TTL (business setting `orders.order_expiry_hours`, default 24) MUST transition to `expired` when the sweep runs; the sweep MUST release their reserved stock (`release_expired`), cancel linked `pending`/`pending_verification` payments, and audit each expiry with a system actor. The sweep MUST run lazily on operations board load, admin orders list load, and public order page reads, and MUST return how many orders expired.

#### Scenario: Stale order expires with stock released

- GIVEN a `pending` order created 25 hours ago with reserved stock and TTL 24
- WHEN any trigger read runs
- THEN the order is `expired`, its reservation is released, and `orders.expired` is audited.

#### Scenario: Fresh order untouched

- GIVEN a `pending` order created 2 hours ago
- WHEN the sweep runs
- THEN the order stays `pending` and stock remains reserved.
