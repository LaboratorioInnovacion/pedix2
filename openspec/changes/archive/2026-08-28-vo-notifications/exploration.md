# Exploration: vo-notifications (Phase 11 — Notificaciones)

## Current State
- Phases 1–10 complete: 133/133 tests green, 20 main specs. No notification code exists anywhere.
- Master spec section map (NOTE: differs from earlier assumptions): **17 = PIN de entrega**, **18 = OpenWA**, **20 = Notificaciones**, **25 = Seguridad/Tokens**, **26 = Integraciones y secretos**, **29 = Tareas diferidas sin workers**, **30 = Auditoría y logs**, **38 = Principios no negociables**.
- Transition hook points (real code):
  - `OrderService::createFromCart` — order created (`pending`), contact snapshot already on order row (`customer_email`, `customer_phone`, `public_token`).
  - `OrderOperationsService::transitionWithinTransaction` — accepted / rejected / in_progress / ready / completed / cancelled, one transaction per operation; `expireStaleLazy` for expiry. `accepted` from `change_proposed` = "aprobación de cambios".
  - `PaymentService::transitionWithinTransaction` — verified/approved (auto-accepts order), rejected, cancelled.
  - `DeliveryService` — assign/reassign (PIN hashed, recomputable via `Pin::code`), picked_up, delivered (drives order completed), failed, cancelled.
- Lazy-sweep precedent: `expireStaleLazy()` invoked best-effort in `OperationsAdminController::board`, `OrdersAdminController::listing`, `OrderPageController` — never blocks reads.
- Settings pattern: `SettingsRepository` (business_settings k/v) + `SettingsAdminController` (settings.manage; secrets write-only, never echoed — current tokens stored plaintext, an accepted precedent).
- PIN display on public token page already exists (spec 17 fallback channel; spec D9).
- Tests: real-MariaDB scratch DBs, fake injection via nullable constructor params, serial runs via `tools/run-tests.php`.

## Spec wording anchors (quoted)
- Sec 20: "Configurables por comercio y por evento. Eventos posibles: pedido recibido; aceptado; rechazado; preparando; listo; delivery asignado; en camino; entregado; cancelado; pago aprobado/rechazado; aprobación de cambios. Arquitectura orientada a eventos para poder sumar email/SMS/push en el futuro."
- Sec 17: PIN "se envía por OpenWA; también se muestra en seguimiento seguro; se almacena hasheado".
- Sec 18: "OpenWA es canal de notificación, no fuente de verdad... Si OpenWA falla, el sistema sigue operando desde paneles."
- Sec 29: "`scheduled_jobs` en MySQL. Ejemplos: ... reintentar notificación ... Ejecución posible por: 1. actividad normal; 2. polling de operación; 3. cron HTTP opcional. El Core no depende de un worker permanente."
- Sec 38.6: "Servicios externos no deben convertirse en puntos únicos de falla innecesarios."

## Approaches (transport/queue decision)
1. **Synchronous send in try/catch** — send at transition time.
   - Pros: simplest, instant.
   - Cons: SMTP/bridge latency inside every transition; no retry; hung socket stalls checkout/board actions; contradicts sec 29 retry model. Effort: Low.
2. **DB outbox + lazy sweep (RECOMMENDED)** — `notification_events` row inserted in the SAME transaction as the triggering operation; `dispatchPendingLazy()` sweeps pending rows (attempts cap 3) on existing read points (board, orders list, public tracking), best-effort try/catch.
   - Pros: zero latency cost on transitions; transactional consistency; retry = spec 29 aligned ("reintentar notificación"); mirrors proven expireStaleLazy pattern; failures never break order flow (sec 38.6).
   - Cons: notifications are delayed until some read happens (acceptable: ops panel is polled in normal operation). Effort: Medium.
3. **Hybrid (outbox + best-effort immediate send)** — fastest delivery, but adds latency risk back into transitions and doubles code paths. Effort: Medium-High.

## Recommendation
Option 2. Templates as code constants (Spanish, V1) rendered at dispatch time from context ids — PIN is recomputed via `Pin::code` at send, never stored plaintext in the outbox (sec 25/30). Transports: minimal `SmtpClient` (raw sockets: EHLO, STARTTLS optional, AUTH LOGIN, MAIL FROM/RCPT TO/DATA) + `WhatsAppClient` (HTTP POST to configurable bridge URL/token); both behind an injectable interface, failures become typed outbox outcomes, never exceptions to callers.

## Event catalog decision
order.created, order.accepted, order.rejected, order.in_progress, order.ready, order.cancelled, order.change_approved (from=change_proposed), payment.verified, payment.approved, payment.rejected, delivery.assigned, delivery.picked_up, delivery.delivered. Dedup: "entregado" via `delivery.delivered` for delivery orders, `order.completed` only for pickup orders. Not notified (not in spec list): order.expired, payment.cancelled, delivery.failed — public token page already shows those states.

## Risks
- Raw-socket SMTP edge cases (TLS, AUTH variants) on exotic shared hosts — mitigated: per-channel enable flags + typed failures visible in admin log.
- OpenWA bridge is external/underspecified — treated as opaque HTTP endpoint (URL+token), spec 18 tolerance honored.
- Sweep starvation if nobody opens panels — accepted V1 (cron HTTP sweep endpoint could be added later; out of scope now).

### Ready for Proposal
Yes.
