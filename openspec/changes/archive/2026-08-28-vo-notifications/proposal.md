# Proposal: vo-notifications — Notifications (Phase 11)

## Intent
Spec sec 20 requires per-business, per-event customer notifications; today nothing notifies anyone. Spec 17 requires the delivery PIN to be "enviado por OpenWA" (public token page is only the fallback built in Phase 10). Notifications must never break order flow (sec 38.6) and retries must run without workers (sec 29).

## Scope
### In Scope
- Migration 010: `notification_events` outbox table (event, channel, recipient, state pending/sent/failed/skipped, attempts, last_error, context ids; no secrets/PIN at rest).
- `NotificationService`: transactional enqueue at transition points, `dispatchPendingLazy()` sweep (attempts cap 3), Spanish code-constant templates rendered at dispatch, recipient resolution from order contact snapshot.
- Transports: minimal `SmtpClient` (raw sockets, no Composer) + `WhatsAppClient` (HTTP POST to configurable OpenWA-bridge URL/token), injectable fakes for tests; failures become typed outbox outcomes.
- Hooks: orders (created/accepted/rejected/in_progress/ready/cancelled/change-approved), payments (verified/approved/rejected), delivery (assigned/picked_up/delivered).
- Settings: notifications/email/WhatsApp enable flags + SMTP creds + bridge URL/token (secrets write-only, `settings.manage`).
- Admin read-only log `/admin/notificaciones` + sweep wiring on existing read points.

### Out of Scope
- Push, SMS, email marketing; template editor (templates are code constants); retry schedules beyond lazy sweep; cron-HTTP sweep endpoint; third-party mail services beyond raw SMTP; full OpenWA API (bridge POST only); driver-facing WhatsApp proposals; admin-recipient notifications; notifying `expired`/`payment.cancelled`/`delivery.failed` (public page covers them); general `scheduled_jobs` table; encrypting existing settings secrets (unchanged precedent).

## Capabilities
### New Capabilities
- `notifications`: event catalog per spec 20, outbox dispatch with failure isolation, transports, PIN-over-WhatsApp per spec 17/18, per-business enable flags.

### Modified Capabilities
- `admin-shell`: settings page notifications section (secrets never echoed), `/admin/notificaciones` log route, dashboard link.
- `schema-baseline`: migration 010 (notification_events + settings seeding pattern).

(orders/payments/delivery specs unchanged: hook wiring is additive code; their state-machine guarantees untouched. Requirements for the wiring live in `notifications`.)

## Approach
DB outbox + lazy sweep (exploration option 2): enqueue inside the triggering transaction; sweep pending rows best-effort on board/orders-list/public-tracking reads, mirroring `expireStaleLazy`. Dedup "entregado": `delivery.delivered` for delivery orders, `order.completed` only for pickup. PIN recomputed via `Pin::code` at send time, never persisted. Default flags off (opt-in after SMTP/bridge config).

## Affected Areas
| Area | Impact | Description |
|------|--------|-------------|
| `api/database/migrations/010_notifications.sql.php` | New | Outbox table + settings seeding |
| `api/app/Notifications/{NotificationService,NotificationRepository,SmtpClient,WhatsAppClient,NotificationTransport}.php` | New | Outbox, sweep, templates, transports |
| `api/app/Orders/OrderService.php`, `OrderOperationsService.php` | Modified | Enqueue hooks (try-safe) |
| `api/app/Payments/PaymentService.php` | Modified | Enqueue hooks |
| `api/app/Delivery/DeliveryService.php` | Modified | Enqueue hooks (incl. PIN message) |
| `api/app/Admin/SettingsAdminController.php`, `templates/settings.php` | Modified | Notifications settings section |
| `api/app/Admin/AdminController.php`, `templates/notifications.php`, `dashboard.php` | Modified | Log route + dashboard link |
| `tests/Notification*.php` | New | Fake transports, hooks, admin HTTP |

## Risks
| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Raw-socket SMTP incompatibilities | Med | Per-channel flag, typed failures in admin log, attempts cap |
| Sweep starvation (no reads) | Low | Ops panel polled in normal operation; cron endpoint deferred |
| Bridge contract drift | Med | Opaque URL+token POST; sec 18 tolerance (system runs without it) |
| Slow sweep blocking reads | Low | Best-effort try/catch, cap 3 attempts, socket timeouts |

## Rollback Plan
Set `notifications_enabled=0` (transports stop; outbox rows stay harmless). Full revert: drop `notification_events`, remove Notifications classes and hook lines — additive changes only, no existing behavior modified.

## Dependencies
None new (PDO, sockets; no Composer). Real MariaDB scratch DB for tests per harness.

## Success Criteria
- [ ] Every spec-20 event enqueues exactly one notification per enabled channel, inside the triggering transaction.
- [ ] Transport failure marks the row failed with `last_error`, increments attempts, stops at 3; order/payment/delivery flow unaffected (existing 133 tests stay green).
- [ ] PIN message renders a recomputed PIN at send; no PIN/secret stored in `notification_events` or logs (sec 25/30).
- [ ] Settings UI saves SMTP/bridge config without echoing secrets back.
- [ ] `/admin/notificaciones` lists recent events with state; access guarded by `settings.manage`.
