# Archive Report — vo-notifications (Phase 11)

**Archived:** 2026-08-28 · **Verdict:** verified PASS · **Suite at close:** 150 tests / 0 failures (3 serial chunks: 38 + 40 + 72)

## Scope delivered

Transactional notification outbox with email + WhatsApp channels (Phase 11 of Vender Online Core V1):

- **Unit A** — migration `010_notifications` (`notification_events` outbox: ids-only context_json, state pending/sent/failed, attempts cap, FK-scoped, 3 lookup indexes; no seeds — flags are opt-in), `NotificationTransport` interface (never throws), `SmtpClient` (raw sockets: EHLO → optional STARTTLS → AUTH LOGIN → MAIL FROM/RCPT TO/DATA with dot-stuffing; injectable connect), `WhatsAppClient` (opaque bridge POST with Bearer token), transport suite backed by a real loopback fake SMTP server process.
- **Unit B** — `NotificationRepository` (same-transaction plain-INSERT enqueue), `NotificationService` (master + per-channel flag gating with recipient resolution from the order contact snapshot; 14 Spanish templates rendered at dispatch; PIN recomputed via `Pin::code` only in the WhatsApp `delivery.assigned` body — never at rest; `dispatchPendingLazy` with attempts cap 3 and per-row failure isolation), null-safe hooks in OrderService/OrderOperationsService/PaymentService/DeliveryService implementing the spec-20 event catalog with the delivered dedup rule (delivery orders → `delivery.delivered`; pickup → `order.completed`), board sweep wiring.
- **Unit C** — settings section (flags + SMTP + bridge fields; `smtp_password`/`whatsapp_bridge_token` write-only), `NotificationTransportFactory` (settings-built clients; disabled/unconfigured → typed per-attempt failure), `/admin/notificaciones` read-only log (settings.manage guard, sweep on load, state/channel filters, pagination, error truncation), dashboard card, production wiring incl. admin transition enqueues and the MP webhook branch.

## Verification summary

- 18/18 requirements (notifications 11, admin-shell 4, schema-baseline 3).
- Suite: 150/150 across 3 serial chunks (chunk 3 first run had 1 flake in the MariaDB cold-start race; rerun clean).
- Focused reruns: NotificationTransportTest 10/10 · NotificationServiceTest 7/7 · NotificationsAdminHttpTest 3/3.
- End-to-end proof: seeded pending row dispatched through settings-built SmtpClient over real HTTP against the fake SMTP server (full dialogue captured; row → `sent` with `sent_at`).

## Execution notes

- NO TDD (maintainer preference); velocity mode. All three units ran as workers.
- Environmental discoveries recorded: MariaDB non-strict sql_mode truncates invalid ENUMs (structural pins required); 52 leaked scratch DBs from killed runs degraded DDL speed ~2x (hygiene sweep added to pre-suite routine).
- STARTTLS default reconciled to `true` (design sketch showed false; both paths tested).

## Specs synced

- NEW: `openspec/specs/notifications/` (11 requirements).
- MERGED: `admin-shell` Payment settings page rewritten (notifications section + write-only policy spelled out) + Notifications log page + dashboard navigation + scope guard (notifications log now spec-governed); `schema-baseline` Baseline Tables + Scope Guard through migration 010 + new Migration 010 requirement.
- Main spec count: **21 capabilities**.

## Open follow-ups (non-blocking)

1. Sweep starvation if no admin reads occur — cron-HTTP sweep endpoint deferred by proposal.
2. Non-strict sql_mode on this MariaDB truncates invalid ENUMs — keep structural pins.
3. Scratch-DB hygiene sweep (`vo_*_test_*`, keep `vo_test`) before long suite runs.
4. Carried: MariaDB service install; per-business MP routing; IdempotencyConflict→409; prorated-lines legend; suite sharding.
5. Maintainer commit decision: working tree carries Phases 2B-11 uncommitted on `cb66ded`.

## Traceability

Engram: `sdd/vo-notifications/{explore(3995),proposal(3996),spec(3997),design(3998),tasks(3999),apply-progress(4014),verify-report}`. Focused test files: `tests/NotificationTransportTest.php`, `tests/NotificationServiceTest.php`, `tests/NotificationsAdminHttpTest.php`.
