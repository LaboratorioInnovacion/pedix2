# Design: vo-notifications — Outbox, Transports, Hooks, Admin Log

## Technical Approach

DB outbox + lazy sweep (exploration option 2): hooks inside OrderService / OrderOperationsService / PaymentService / DeliveryService enqueue `notification_events` rows in the SAME transaction as the state change; `NotificationService::dispatchPendingLazy()` sweeps pending rows (attempts cap 3) best-effort on existing read points, mirroring `expireStaleLazy`. Transports are a minimal raw-socket `SmtpClient` and an opaque-POST `WhatsAppClient`, both behind `NotificationTransport`, both converting every failure into a typed `['ok'=>bool,'error'=>?string]` outcome. Spanish templates are code constants rendered at dispatch; the delivery PIN is recomputed via `Pin::code` at send and never stored. Implements specs: notifications N1–N11, admin-shell (settings section + log page + scope guard), schema-baseline 010.

## Architecture Decisions

| # | Decision | Alternatives | Rationale |
|---|----------|--------------|-----------|
| 1 | Outbox + lazy sweep | Sync send in try/catch; hybrid | Zero latency on transitions; transactional consistency; retry = sec 29; sec 38.6 isolation. |
| 2 | `state ENUM('pending','sent','failed')` — proposal's `skipped` dropped | Keep `skipped` | Disabled channel / missing recipient simply never enqueues (N8); no state needed. |
| 3 | Log page + settings section both guarded by **`settings.manage`** (no new permission) | New `notifications.log`; reuse `reports.view` | Proposal success criteria names `settings.manage`; zero fixture/seed churn (InstallerSeeder untouched); log exposes recipients = config-adjacent owner data. |
| 4 | Migration 010 seeds no settings | Seed flag rows per business | Absent key = OFF (opt-in); avoids per-business fan-out; UI writes keys when configured. |
| 5 | Same-transaction enqueue without try/catch swallow | Best-effort enqueue | Outbox write is a local DB write like `audit` (same precedent); atomicity is the point of the outbox. Non-interference (sec 38.6) targets external transports — those are isolated in the sweep. |
| 6 | Hooks optional-ctor-param null-safe (mirror `?DeliveryRepository` in OrderOperationsService) | Hard ctor deps | Existing wirings/tests unchanged; `operations()`/`deliveries()` factories pass the service when present. |
| 7 | PIN only in the WhatsApp `delivery.assigned` body, recomputed at send via `Pin::code($key,$deliveryId,$orderId)` | PIN in all channels; PIN at enqueue | Sec 17 pins OpenWA as the PIN channel; context_json keeps ids only (N1/N7). |
| 8 | Reassign re-enqueues `delivery.assigned` | Notify first assignment only | Courier change is a new "asignado" fact for the customer; state stays `assigned`. |
| 9 | `payment.verified`/`payment.approved` enqueue, and the auto-accept enqueues `order.accepted` separately | Suppress order.accepted on auto-accept | Sec 20 lists "pago aprobado" and "aceptado" as distinct events. |
| 10 | Transport built per dispatch from settings via injectable factory; unconfigured channel = typed error outcome per attempt | Global transports; silent skip | Per-business creds; visible failure in admin log consumes attempts instead of churning forever. |

### Event→hook map (all enqueues inside the triggering transaction)

| Service method | Transition | Event |
|---|---|---|
| `OrderService::createFromCart` | → pending | `order.created` |
| `OrderOperationsService::transitionWithinTransaction` | pending→accepted | `order.accepted` (incl. payment auto-accept) |
| 〃 | change_proposed→accepted | `order.change_approved` |
| 〃 | rejected / in_progress / ready / cancelled | `order.rejected` / `order.in_progress` / `order.ready` / `order.cancelled` |
| 〃 | ready→completed, fulfillment=pickup | `order.completed` (delivery orders: covered by `delivery.delivered`) |
| 〃 | → expired | none |
| `PaymentService::transitionWithinTransaction` | verified / approved / rejected | `payment.verified` / `payment.approved` / `payment.rejected`; cancelled → none |
| `DeliveryService::assign`/`reassign` | →assigned | `delivery.assigned` (WhatsApp body carries PIN) |
| `DeliveryService::markPickedUp` / `deliver` | picked_up / delivered | `delivery.picked_up` / `delivery.delivered`; fail/cancel → none |

Payment hooks load the order row by `order_id` (same transaction) and call `enqueueForOrder`. `expired`, `payment.cancelled`, `delivery.failed`, `delivery.cancelled` never enqueue (N2).

### Settings keys

`notifications_enabled`, `notifications_email_enabled`, `notifications_whatsapp_enabled` ('1'/'0', default off); `smtp_host`, `smtp_port`, `smtp_username`, `smtp_password`*, `smtp_from_email`, `smtp_from_name`; `whatsapp_bridge_url`, `whatsapp_bridge_token`* (* = write-only secret: empty input keeps value, never echoed, audited as key names only — existing `mp_access_token` pattern).

### Sweep triggers (each `try { …dispatchPendingLazy(); } catch (Throwable) {}`)

`/admin/` dashboard, `/admin/notificaciones`, `/admin/operacion` board, `/admin/pedidos` listing, `/pedido/{token}` public tracking.

## Data Flow

```
transition (order/payment/delivery) ── same transaction ──► notification_events (pending, ids-only ctx)
        ▲                                                              │
 reads: dashboard / board / orders list / log page / tracking          │ dispatchPendingLazy()
        │                                                              ▼
        └── best-effort sweep ──► NotificationService: render Spanish template
                                  (number, label, PIN via Pin::code for delivery.assigned/WA)
                                  → transport per channel+settings → markSent / markFailed(attempts≤3)
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `api/database/migrations/010_notifications.sql.php` | Create | DDL below; no seeds |
| `api/app/Notifications/NotificationTransport.php` | Create | interface, typed outcome |
| `api/app/Notifications/SmtpClient.php` | Create | raw-socket SMTP (EHLO/STARTTLS/AUTH LOGIN/DATA), ≥5s timeout, injectable connector |
| `api/app/Notifications/WhatsAppClient.php` | Create | JSON POST `{to,message}` + token, ≥5s timeout, injectable executor |
| `api/app/Notifications/NotificationRepository.php` | Create | enqueue / findPending(limit, cap=3) / markSent / markFailed |
| `api/app/Notifications/NotificationService.php` | Create | enqueueForOrder (flag+recipient resolution), templates, dispatchPendingLazy (never throws) |
| `api/app/Orders/OrderService.php` | Modify | optional `?NotificationService` ctor param; enqueue `order.created` |
| `api/app/Orders/OrderOperationsService.php` | Modify | optional param; event map in `transitionWithinTransaction` (incl. pickup completed dedup, change_approved) |
| `api/app/Payments/PaymentService.php` | Modify | optional param; `payment.*` hooks; pass through `operations()` |
| `api/app/Delivery/DeliveryService.php` | Modify | optional param; `delivery.*` hooks; PIN ids in context |
| `api/app/Admin/SettingsAdminController.php` + `templates/settings.php` | Modify | notifications section (flags + SMTP + bridge; secret policy) |
| `api/app/Admin/NotificationsAdminController.php` + `templates/notifications.php` | Create | read-only log, `settings.manage` guard, sweep on load |
| `api/app/Admin/AdminController.php`, `templates/dashboard.php` | Modify | route dispatch + "Notificaciones" card |
| `api/app/Admin/OperationsAdminController.php`, `Admin/OrdersAdminController.php`, `Orders/OrderPageController.php` | Modify | sweep wiring (best-effort) + pass notification service in factories |
| `api/bootstrap/app.php` | Modify | construct NotificationService with repo + PDO-backed Connection |
| `tests/NotificationTransportTest.php`, `tests/NotificationServiceTest.php`, `tests/NotificationsAdminHttpTest.php` | Create | per-unit tests |
| `tests/BaselineSchemaTest.php`, `CatalogSchemaTest.php`, `OrdersSchemaTest.php` | Modify | migration lists += `'010 notifications'`; Baseline tables += `notification_events` |

### Migration 010 DDL (exact)

```sql
CREATE TABLE notification_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,business_id BIGINT UNSIGNED NOT NULL,event VARCHAR(64) NOT NULL,channel ENUM('email','whatsapp') NOT NULL,recipient VARCHAR(190) NOT NULL,subject VARCHAR(190) NULL,context_json JSON NULL,state ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,last_error VARCHAR(500) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,sent_at DATETIME NULL,KEY idx_notification_events_business_state (business_id,state),KEY idx_notification_events_event (event),KEY idx_notification_events_created_at (created_at),CONSTRAINT fk_notification_events_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## Interfaces / Contracts

```php
namespace VO\Notifications;
interface NotificationTransport { /** @return array{ok:bool,error:?string} */ public function send(string $to, string $subject, string $body): array; }
final class SmtpClient implements NotificationTransport { // host,port,username,password,fromEmail,fromName,bool $starttls=false,?\Closure $connect=null
final class WhatsAppClient implements NotificationTransport { // bridgeUrl,token,?\Closure $executor=null (POST {to,message}, Bearer token)
final class NotificationRepository { // (Connection $db) enqueue(businessId,event,channel,recipient,subject,?array ctx):int
  // findPending(int $limit,int $maxAttempts=3):array; markSent(int $id):void; markFailed(int $id,string $err):void /* attempts+1; cap→failed */
final class NotificationService { // (Connection $db, NotificationRepository $repo, ?\Closure $transportFactory=null)
  public function enqueueForOrder(string $event, array $order): void;            // flags+recipient gate, ids-only context
  public function dispatchPendingLazy(?int $businessId = null, int $limit = 20): void; // never throws
```

Templates: `const TEMPLATES = [event => ['subject'=>…, 'body'=>…]]` (Spanish; placeholders `{number}`, `{status}`, `{pin}` — `{pin}` only in the WhatsApp `delivery.assigned` body). Unconfigured transport → outcome `ok=false` (`canal no configurado`) consumed by the attempts cap.

## Testing Strategy

| Layer | What | How |
|---|---|---|
| Unit A | SMTP dialogue over fake socket pair (EHLO/STARTTLS/AUTH/DATA), connect/protocol failure typed, WhatsApp payload + non-2xx typed | `NotificationTransportTest` (scratch `vo_notif11_test_<rand>`, serial) |
| Unit B | in-tx enqueue per enabled channel; catalog map (change_approved, pickup completed dedup, excluded states); sweep send/failed/cap; PIN rendered at send only; transport exception → typed failure; null-service hooks | `NotificationServiceTest` + re-run `DeliveryServiceTest` |
| Unit C | settings flags/secrets save (never echoed/audited); log page 403 guard + listing; dashboard card; sweep wiring consumes pending rows on page load | `NotificationsAdminHttpTest` + re-run `PaymentsAdminHttpTest` |
| Fixtures | 3 schema tests `+= '010 notifications'`; Baseline table list `+= notification_events` | explicit tasks 1.2–1.3 |

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary. Security boundaries covered: secrets write-only + never audited (N9), ids-only context (N1), PIN recomputed-at-send never at rest (N7), `settings.manage` guard with audited denials, CSRF on the settings POST (existing middleware).

## Migration / Rollout

Migration 010 is additive (one table, no seeds, no column changes). Rollback: `notifications_enabled=0` stops sends (rows stay harmless); full revert = drop `notification_events`, remove Notifications classes + hook lines — no existing behavior modified.

## Open Questions

- None blocking. Decision 3 (permission reuse) and decision 2 (state enum) resolve the proposal's open points.
