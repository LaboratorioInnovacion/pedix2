# Tasks: vo-notifications — Outbox, Transports, Hooks, Admin Log

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~950 total (≈280 A / ≈410 B / ≈260 C incl. tests) |
| 400-line budget risk | Medium |
| Chained PRs recommended | Yes |
| Suggested split | Unit A → Unit B → Unit C (each <400 lines; keep B lean) |
| Delivery strategy | ask-on-risk |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: Medium

Preflight note: run is NO-COMMIT / NO-TDD — units verified on working tree; chain/PR decision deferred to orchestrator/user.

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| A | Migration 010 + transports with typed outcomes | PR 1 | `D:\xampp\php\php.exe tools\run-tests.php --filter NotificationTransportTest` | Scratch DB `vo_notif11_test_<rand>` (MariaDB, serial); fake socket/HTTP | Revert Unit A files; drop migration 010 artifacts |
| B | NotificationService/Repository + Spanish templates + hooks in 4 services | PR 2 | `D:\xampp\php\php.exe tools\run-tests.php --filter NotificationServiceTest` | Scratch DB + seeded orders/deliveries; fake transports | Revert Unit B files; no rows enqueue; flows unchanged |
| C | Settings section + `/admin/notificaciones` log + dashboard link + sweep wiring | PR 3 | `D:\xampp\php\php.exe tools\run-tests.php --filter NotificationsAdminHttpTest` | HTTP harness per existing pattern | Revert Unit C files; board/settings unchanged |

## Phase 1: Unit A — Schema + Transports

- [x] 1.1 Create `api/database/migrations/010_notifications.sql.php` with the exact DDL from design.md (`notification_events`: channel/state ENUMs, ids-only `context_json`, attempts cap columns, 3 lookup indexes, FK to businesses; NO permission/settings seeds). Refs schema-baseline S1, N1.
- [x] 1.2 Fixture: `tests/BaselineSchemaTest.php` — migration list += `'010 notifications'`; expected tables += `'notification_events'` (sorted). Refs schema-baseline modified.
- [x] 1.3 Fixture: `tests/CatalogSchemaTest.php` + `tests/OrdersSchemaTest.php` — migration lists += `'010 notifications'`. Refs schema-baseline modified.
- [x] 1.4 Create `api/app/Notifications/NotificationTransport.php` (interface, `send(to,subject,body): array{ok,error}`) + `SmtpClient.php`: raw sockets, ≥5s timeout, EHLO → optional STARTTLS → AUTH LOGIN → MAIL FROM → RCPT TO → DATA → QUIT, injectable `?Closure $connect`, every Throwable → `['ok'=>false,'error'=>…]`. Refs N5.
- [x] 1.5 Create `api/app/Notifications/WhatsAppClient.php`: POST JSON `{to,message}` with bearer token to configured bridge URL, ≥5s timeout, injectable `?Closure $executor`, 2xx = ok, anything else typed error. Refs N6.
- [x] 1.6 Create `tests/NotificationTransportTest.php`: full SMTP dialogue over `stream_socket_pair` fake (asserts EHLO/AUTH/DATA sequence + from/to/body), connect failure + protocol failure typed, WhatsApp fake executor payload/token assert + non-2xx typed, 010 schema pins (columns/defaults/indexes). Verify Unit A focused command green.

## Phase 2: Unit B — Service, Templates, Hooks

- [x] 2.1 Create `api/app/Notifications/NotificationRepository.php`: `enqueue()` INSERT pending, `findPending(limit, maxAttempts=3)` (state pending, attempts < cap, ORDER BY id), `markSent()`, `markFailed()` (attempts+1; cap → state `failed`, `last_error` ≤500). Refs N1, N4.
- [x] 2.2 Create `api/app/Notifications/NotificationService.php` — `enqueueForOrder(event, orderRow)`: reads business settings, master + per-channel flag gate (absent key = off), recipient from `customer_email`/`customer_phone` snapshot, per enabled channel one row, context ids only (`order_id`, `delivery_id`). Refs N1, N8.
- [x] 2.3 Add templates to `NotificationService`: Spanish code constants per design event map (14 events), `{number}`/`{status}`/`{pin}` placeholders, rendered at dispatch; `delivery.assigned` WhatsApp body appends PIN via `Pin::code(ensurePinKey, deliveryId, orderId)`; email bodies never contain `{pin}`. Refs N2, N7.
- [x] 2.4 Implement `dispatchPendingLazy(?businessId, limit=20)` in `NotificationService`: per-row try/catch + top-level try/catch, transport from settings factory (unconfigured → typed `canal no configurado` outcome), render→send→`markSent`/`markFailed`, never throws. Refs N3, N4, N10.
- [x] 2.5 Hook orders: optional `?NotificationService` ctor param in `OrderService` (`order.created` after insert) + `OrderOperationsService` (event map incl. `order.change_approved`, pickup-only `order.completed`, none for `expired`) inside `transitionWithinTransaction`. Refs N2, N10.
- [x] 2.6 Hook payments + delivery: optional param in `PaymentService` (`payment.verified/approved/rejected`; none on cancelled; load order row by `order_id`; pass service through `operations()`) and `DeliveryService` (`delivery.assigned` on assign+reassign with `delivery_id` context, `delivery.picked_up`, `delivery.delivered`; none on fail/cancel). Refs N2.
- [x] 2.7 Create `tests/NotificationServiceTest.php`: same-tx enqueue (rollback case), per-channel gating (flags/missing recipient), catalog map assertions incl. dedup, sweep ok/typed-failure/cap-3, PIN present in sent WA body but absent from stored row + email body, transport exception → failed row with flow intact, null-service construction leaves transitions unchanged. Verify Unit B focused command + `DeliveryServiceTest` green.

## Phase 3: Unit C — Admin Settings, Log Page, Sweep Wiring

- [x] 3.1 Extend `SettingsAdminController.php` + `templates/settings.php`: "Notificaciones" section — 3 checkboxes, SMTP fields (host/port/username/from email/from name + password type=password), bridge URL + token (password); save flags '1'/'0', text fields trimmed, secrets only when input non-empty (keep-if-empty), never rendered back; audit `changed_keys` names only. Refs N8, N9, admin-shell modified.
- [x] 3.2 Create `api/app/Admin/NotificationsAdminController.php` + `templates/notifications.php`: `GET /admin/notificaciones`, `settings.manage` guard (403 + audited `authz.denied`), best-effort sweep on load, read-only table (created, event, channel, recipient, state, attempts, last_error) newest-first, Spanish labels. Refs N3, N11, admin-shell A1.
- [x] 3.3 Modify `AdminController.php` (route dispatch `/admin/notificaciones`) + `templates/dashboard.php` ("Notificaciones" card). Refs admin-shell A2.
- [x] 3.4 Wire sweep best-effort (`try/catch (Throwable)`) into `OperationsAdminController::board`, `OrdersAdminController::listing`, `OrderPageController`, and `AdminController::dashboard`; pass the notification service through existing `operations()`/`deliveries()` factories + `api/bootstrap/app.php`. Refs N3.
- [x] 3.5 Create `tests/NotificationsAdminHttpTest.php`: settings POST saves flags + secrets and never echoes/audits them; log page 403 without `settings.manage` and 200 listing seeded rows with state/attempts/last_error; page load flips a seeded pending row to `failed` (unconfigured transport → typed last_error) proving sweep wiring; dashboard contains the card link. Verify Unit C focused command.
- [x] 3.6 Verify `PaymentsAdminHttpTest` green (settings page regression + payment hooks) and `DeliveryAdminHttpTest` green (delivery hooks in admin actions).

## Phase 4: Full Verification

- [ ] 4.1 Run full suite serially: `D:\xampp\php\php.exe tools\run-tests.php` — 133 prior + new tests green on scratch DBs (`vo_notif11_test_<rand>`); MariaDB verified up before run.
- [ ] 4.2 Confirm success criteria: every catalog event enqueues once per enabled channel in-tx; transport failure → failed row + last_error, cap 3, flows unaffected; no PIN/secret at rest in outbox/logs; settings secrets never echoed; log page guarded by `settings.manage`.
- [ ] 4.3 Confirm non-interference: with flags OFF, order/payment/delivery HTTP flows behave identically to the pre-change suite (no rows, no errors).
