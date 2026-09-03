# Proposal: Payments

## Intent

Implement Phase 8 payments so transfer and Mercado Pago orders can be paid, verified, audited, and advanced safely without SDKs, workers, public proof files, or floating-point money.

## Scope

### In Scope
- Migration `007`: `payments` plus `payment_events`/status history; snapshot amount/currency, method, provider/proof fields, verifier and timestamps.
- Payment state machine from the master spec: `pending`, `approved`, `rejected`, `cancelled`, `pending_verification`, `verified`, `refund_pending`, `refund_completed`.
- Transfer proof upload by public order token, private storage, and admin verification/rejection.
- Mercado Pago Checkout Pro preference creation, signed webhook receiver, provider confirmation, and idempotent state updates.
- Minimal admin payment/configuration pages for credentials and transfer instructions.
- Public order page payment section with transfer instructions, proof form, state badge, and MP redirect button.

### Out of Scope
- Automated refunds/chargebacks, partial payments, subscriptions, MP Connect/split payments, notifications, cron-only expiry, and acceptance-before-payment policy UI.

## Capabilities

### New Capabilities
- `payments`: payment persistence, state transitions, proof upload, MP preference/webhook handling, payment audit trail, and public/admin payment UX.

### Modified Capabilities
- `orders`: paid transfer/MP orders auto-transition from `pending` to `accepted`; public order detail includes payment state/actions.
- `admin-shell`: adds payment list/actions and settings page routing using existing permissions.
- `schema-baseline`: adds migration `007` tables and indexes.

## Approach

Add `api/app/Payments` with `PaymentService`, repository, state map, `MpClient` (curl REST, injectable base URL), webhook signature validator, and proof storage helper. Transfer payments start as `pending_verification`; MP starts as `pending`. Approved MP payments and verified transfers auto-accept `pending` orders. Store proofs under `api/storage/proofs/{business}/{order}/` with random names, MIME/extension/size validation. Use `payment_events` for provider/system history and `audit_log` for admin-sensitive actions.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `api/database/migrations/007_*` | New | Payment tables, constraints, indexes. |
| `api/app/Payments/*` | New | Core payment service/client/state/security logic. |
| `public_html/index.php`, `api/bootstrap/app.php` | Modified | Public proof, MP init/webhook routes. |
| `api/app/Admin/*`, templates | Modified | Payment list/actions and configuration page. |
| `api/app/Orders/*` | Modified | Payment rendering and auto-accept integration. |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Forged/duplicated webhooks | Med | HMAC `x-signature` validation, provider GET confirmation, idempotent event storage. |
| Unsafe uploads | Med | Private path, generated filename, MIME/extension/size checks, no execution. |
| MP/network outage | Med | Keep payment pending; retries remain idempotent. |
| Scope growth | High | Three work units under review budget. |

## Rollback Plan

Disable MP/transfer settings, remove new routes from front controllers, and leave `payments` rows intact for audit. Migration rollback may drop Phase 8 tables only before production data exists.

## Dependencies

- PHP 8.2 curl, MariaDB/InnoDB, existing auth/audit/settings/order infrastructure, Mercado Pago access token and webhook secret.

## Success Criteria

- [ ] Transfer and MP payments persist integer-cent snapshots and legal state transitions.
- [ ] Verified/approved payments auto-accept pending orders exactly once.
- [ ] Webhooks are signature-validated, provider-confirmed, and duplicate-safe.
- [ ] Proof uploads stay private and reject invalid files.
- [ ] Admin/public pages expose the minimal payment workflow safely.
