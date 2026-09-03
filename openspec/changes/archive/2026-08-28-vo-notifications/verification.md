# Verification Report — vo-notifications (Phase 11)

**Verdict: PASS** — 18/18 requirements compliant (notifications 11, admin-shell 4, schema-baseline 3), 0 CRITICAL, 0 WARNING.
Inline bounded verification (established route). NO TDD per maintainer preference.

## Evidence

| Check | Result |
|---|---|
| Phase-end suite, 3 serial chunks | Chunk 1 (Admin*/Auth/Baseline/Cart/Catalog/Config) **38/38** · Chunk 2 (Csrf→Mp + NotificationTransport) **40/40** · Chunk 3 (NotificationService/Order/Operations/Payment/Permission/Pricing/Public/Scope/State/Stock/Delivery) **72/72** on rerun (first run had 1 flake during MariaDB cold-start race; rerun clean) ⇒ **150/150, 0 failures** |
| Focused verification reruns | NotificationTransportTest **10/10** · NotificationServiceTest **7/7** · NotificationsAdminHttpTest **3/3** |

## Requirement traceability (highlights)

- **Outbox (N1-N2)**: same-transaction enqueue proven by rollback test (rolled-back order → zero outbox rows); enqueue never swallows (mirrors audit precedent).
- **Event catalog + dedup (N3)**: hooks fire on real flows — cart creation → `order.created`; verifyTransfer → `payment.verified` + `order.accepted`; reassign re-enqueues `delivery.assigned`; delivery orders emit `delivery.delivered` only, pickup emits `order.completed`.
- **Lazy dispatch (N4)**: sweep attempts cap 3, per-row failure isolation (throwing transport → row failed, loop continues), board/dashboard/log triggers wired.
- **Transports (N5-N6)**: SmtpClient raw-socket dialogue proven end-to-end over a real loopback fake SMTP server (EHLO/AUTH/MAIL FROM/RCPT TO/DATA → row `sent`); WhatsAppClient opaque bridge POST; credentials never in error strings.
- **Templates (N7)**: 14 Spanish templates rendered at dispatch; PIN recomputed via `Pin::code` only inside the WhatsApp `delivery.assigned` body — never at rest, never in context_json, never audited.
- **Settings + admin UX (N8-N11)**: flags default off (opt-in), write-only secrets (empty POST preserves, never echoed/audited), port/email validation Spanish, log page read-only with filters/pagination/truncation, guard 403 + `authz.denied`, dashboard card, production transport factory wiring (settings-built clients incl. MP webhook branch).

## Findings

**CRITICAL:** none. **WARNING:** none.
**SUGGESTION:** (1) sweep starvation if no admin reads occur — cron-HTTP sweep endpoint deferred (documented); (2) MariaDB non-strict sql_mode truncates invalid ENUMs silently — behavioral pins must use structural checks (SHOW COLUMNS) on this environment; (3) scratch-DB hygiene matters: 52 leaked DBs from killed runs degraded DDL ~10s/op — sweep `vo_*_test_*` before long suite runs (keep `vo_test`); (4) dashboard sweep runs on every load (cheap, best-effort — acceptable).

## Verdict

Transactional outbox with lazy dispatch, exactly the spec-20 event catalog with the delivered dedup rule, Spanish dispatch-time templates, raw-socket SMTP and bridge-POST WhatsApp transports with typed failures, opt-in settings with write-only secrets, and a read-only admin log — all match specs 20/29/30/25/38 and the three capability deltas. **PASS — ready for archive.**
