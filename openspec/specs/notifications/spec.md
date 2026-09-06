# Notifications Specification

## Purpose

Defines Phase 11 customer notifications: a transactional outbox (`notification_events`), the spec-20 event catalog, lazy dispatch with retry cap, raw-SMTP and WhatsApp-bridge transports, Spanish dispatch-time templates (PIN never at rest), per-business opt-in flags, and failure isolation so notifications never break the order/payment/delivery flow (master spec secs 17, 18, 20, 25, 29, 30, 38.6).

## Requirements

### Requirement N1: Transactional outbox enqueue

For every catalog event the system MUST enqueue exactly one `notification_events` row per enabled channel inside the SAME database transaction as the triggering state change; a rollback of the triggering operation MUST also roll back its pending notifications. `context_json` MUST carry only resolution ids (order id, delivery id, business id); it MUST NOT contain the delivery PIN, credentials, or customer data beyond the recipient column.

#### Scenario: Enqueue shares the triggering transaction

- GIVEN notifications are enabled with an email recipient on the order
- WHEN an operator accepts an order
- THEN one `pending` row with event `order.accepted` and channel `email` commits in the same transaction as the status change.

#### Scenario: Rollback removes the notification

- GIVEN a transition whose later side effect fails
- WHEN the transaction rolls back
- THEN no `notification_events` row for that transition remains.

#### Scenario: Context carries ids only

- GIVEN any enqueued notification
- WHEN `context_json` is inspected
- THEN it contains only integer identifiers and no PIN, token, password, or address data.

### Requirement N2: Event catalog and delivered dedup

The system MUST notify exactly this catalog: `order.created`, `order.accepted`, `order.rejected`, `order.in_progress`, `order.ready`, `order.cancelled`, `order.change_approved` (accepted coming from `change_proposed`), `payment.verified`, `payment.approved`, `payment.rejected`, `delivery.assigned` (assign and reassign), `delivery.picked_up`, `delivery.delivered`, plus `order.completed` ONLY for pickup-fulfillment orders. The "entregado" event MUST be deduplicated: delivery orders notify via `delivery.delivered`, pickup orders via `order.completed`. `order.expired`, `payment.cancelled`, and `delivery.failed` MUST NOT enqueue notifications.

#### Scenario: Change approval uses its own event

- GIVEN an order in `change_proposed`
- WHEN it is accepted
- THEN the enqueued event is `order.change_approved`, not `order.accepted`.

#### Scenario: Delivered is not notified twice

- GIVEN a delivery order is marked `delivered` and its order auto-completes
- WHEN both transitions run
- THEN only `delivery.delivered` is enqueued; no `order.completed` row exists.

#### Scenario: Excluded states never enqueue

- GIVEN an order expires, a payment is cancelled, or a delivery fails
- WHEN those transitions commit
- THEN no notification row is created for them.

### Requirement N3: Lazy dispatch sweep

The system MUST dispatch pending notifications through `dispatchPendingLazy()`: select `pending` rows with `attempts < 3`, render and send each, and record the outcome. The sweep MUST run best-effort on existing read points (admin dashboard, operations board, orders list, notifications log, public tracking page) wrapped so it never blocks or fails the read. No worker, cron, or new endpoint is required in V1.

#### Scenario: Sweep sends and marks sent

- GIVEN a pending row and a working transport
- WHEN any wired read point loads
- THEN the row becomes `sent` with a non-null `sent_at`.

#### Scenario: Sweep failure never breaks the read

- GIVEN a transport that hangs or errors
- WHEN the board loads
- THEN the page renders normally and the row records the failure.

### Requirement N4: Typed transport outcomes and attempts cap

Every send MUST return a typed outcome `['ok' => bool, 'error' => ?string]`; transports and the sweep MUST NOT throw to callers. A failed send MUST increment `attempts`, store `last_error` (max 500 chars), keep the row `pending` while `attempts < 3`, and mark it `failed` once the cap is reached. A channel enabled without configured credentials MUST produce the same typed failure per attempt.

#### Scenario: Attempt recorded

- GIVEN a pending row and a transport returning an error
- WHEN the sweep runs
- THEN `attempts` becomes 1, `last_error` holds the message, and the row stays `pending`.

#### Scenario: Cap reached

- GIVEN a pending row already at 3 failed attempts
- WHEN the sweep selects rows
- THEN the row is `failed` and is never selected again.

### Requirement N5: SMTP transport contract

The email transport MUST be a minimal raw-socket SMTP client (no Composer): connect with a timeout of at least 5 seconds, EHLO, optional STARTTLS, AUTH LOGIN, MAIL FROM, RCPT TO, DATA with Spanish-encoded subject/from headers, body, terminator, QUIT. It MUST accept an injectable connection factory for tests and MUST convert any socket/protocol failure into a typed error outcome.

#### Scenario: Successful dialogue

- GIVEN an SMTP endpoint completing the dialogue
- WHEN `send(to, subject, body)` runs
- THEN the outcome is `ok` and the message carries the configured from-address.

#### Scenario: Connect failure is typed

- GIVEN an unreachable host
- WHEN `send` runs
- THEN the outcome is `ok=false` with a non-empty error and no exception escapes.

### Requirement N6: WhatsApp bridge transport contract

The WhatsApp transport MUST POST JSON `{to, message}` to a configurable bridge URL carrying the configured token, with a timeout of at least 5 seconds, treating the bridge as opaque (master spec sec 18): any non-2xx or transport error is a typed failure, and the system operates normally without it. It MUST accept an injectable HTTP executor for tests.

#### Scenario: Message posted

- GIVEN a configured bridge URL and token
- WHEN `send` runs and the bridge answers 2xx
- THEN the outcome is `ok` and the POST body contained the recipient and rendered message.

#### Scenario: Bridge down is tolerated

- GIVEN a bridge URL that refuses connections
- WHEN `send` runs
- THEN the outcome is `ok=false` and no exception escapes.

### Requirement N7: Spanish templates rendered at dispatch

Templates MUST be Spanish code constants keyed by event, rendered at dispatch time from `context_json` ids and the order row (order number, Spanish status label). The `delivery.assigned` WhatsApp body MUST include the delivery PIN recomputed at send time via `Pin::code` from the per-business pin key; the PIN MUST NEVER be persisted in the outbox, rendered into email bodies, or written to logs.

#### Scenario: Body rendered at send, not at enqueue

- GIVEN a pending `order.ready` row enqueued earlier
- WHEN it is dispatched
- THEN the rendered body contains the order number and the label "listo" resolved at that moment.

#### Scenario: PIN only in the WhatsApp assigned message

- GIVEN a `delivery.assigned` row for a WhatsApp recipient
- WHEN it is dispatched
- THEN the body contains the recomputed 6-digit PIN, while the stored row and any email variant contain none.

### Requirement N8: Recipient resolution and opt-in flags

The system MUST resolve recipients from the order contact snapshot: `customer_email` for the email channel, `customer_phone` for the WhatsApp channel. Enqueue MUST require, per channel: the master flag `notifications_enabled`, that channel's flag (`notifications_email_enabled` / `notifications_whatsapp_enabled`, default OFF), and a non-empty recipient; otherwise no row is created. Flags are per business.

#### Scenario: Everything enabled enqueues both channels

- GIVEN all flags on and an order with email and phone
- WHEN a catalog event fires
- THEN one email row and one WhatsApp row are enqueued.

#### Scenario: Disabled channel enqueues nothing

- GIVEN `notifications_whatsapp_enabled` off
- WHEN a catalog event fires
- THEN no WhatsApp row is created.

#### Scenario: Missing recipient enqueues nothing

- GIVEN email enabled but the order has no `customer_email`
- WHEN a catalog event fires
- THEN no email row is created.

### Requirement N9: Secrets never echoed or audited

`smtp_password` and `whatsapp_bridge_token` MUST be write-only: the settings page MUST render them as empty password inputs with a keep-if-empty policy, MUST NOT echo current values, and the settings audit entry MUST record changed key names only, never secret values. Secrets MUST NOT appear in `notification_events`, `last_error`, or logs.

#### Scenario: Saved secret is not echoed

- GIVEN a saved `smtp_password`
- WHEN the settings page renders
- THEN the value does not appear in the HTML.

#### Scenario: Secret is not audited

- GIVEN a settings POST that saves `whatsapp_bridge_token`
- WHEN the `settings.updated` audit metadata is inspected
- THEN it lists key names only and contains no token value.

### Requirement N10: Non-interference guarantee

Notification dispatch failures MUST NEVER break the triggering flow (master spec sec 38.6): transport errors, sweep errors, and unconfigured channels are recorded as outbox outcomes only, while order, payment, and delivery operations and all wired reads complete normally. Hook wiring MUST be additive and null-safe so existing flows behave identically when no notification service is injected.

#### Scenario: Transport failure leaves the order flow intact

- GIVEN all notification flags on and a transport that always fails
- WHEN an operator accepts an order
- THEN the order becomes `accepted`, the action succeeds, and the outbox row accumulates the failure.

#### Scenario: Hooks are optional

- GIVEN services constructed without a notification service
- WHEN existing order/payment/delivery operations run
- THEN behavior and results are identical to the pre-change suite.

### Requirement N11: Admin read-only log

The admin shell MUST expose `GET /admin/notificaciones` for users with `settings.manage`, listing recent `notification_events` (created, event, channel, recipient, state, attempts, last_error) newest-first, read-only (no retry, edit, or delete actions), running the dispatch sweep best-effort on load. Access MUST be denied (403, audited `authz.denied`) otherwise.

#### Scenario: Log lists events with state

- GIVEN sent and failed rows exist
- WHEN an authorized admin opens `/admin/notificaciones`
- THEN rows render with their event, channel, recipient, state, attempts, and last error, newest first.

#### Scenario: Guarded by settings.manage

- GIVEN an authenticated admin without `settings.manage`
- WHEN `/admin/notificaciones` is requested
- THEN the response is 403 and an `authz.denied` audit entry is written.
