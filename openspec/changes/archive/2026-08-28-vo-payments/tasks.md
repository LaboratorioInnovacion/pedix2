# Tasks: Payments

## Review Workload Forecast
| Field | Value |
|---|---|
| Estimated changed lines | 900-1300 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | Unit A → Unit B → Unit C |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units
| Unit | Goal | Focused test command | Runtime harness | Rollback boundary |
|---|---|---|---|---|
| A | Migration 007, state map, PaymentService core, order auto-accept | `php tests/run.php --filter PaymentServiceTest` + schema filters | Service-level scratch DB `vo_pay8_test_*` | migration + `Payments` core + order integration |
| B | MP client, webhook signature, provider confirmation, idempotency | `php tests/run.php --filter MpWebhookHttpTest` | POST `/api/webhooks/mercadopago` with fake MP server/base URL | webhook route + `MpClient`/validator |
| C | Proof upload, admin UI, public payment section, settings | `php tests/run.php --filter PaymentsAdminHttpTest` + `OrderConfirmationHttpTest` | Browser-style PHP test server for `/pedido` and `/admin` | upload helper + admin/public templates/settings |

## Unit A: Migration, core service, order auto-accept
- [x] A.1 Add `tests/PaymentServiceTest.php` covering payments R1-R2/R5/R7, orders R5, schema-baseline R1.
- [x] A.2 Create `api/database/migrations/007_create_payments.sql.php` with `payments`/`payment_events` columns, FKs, unique external reference, and indexes.
- [x] A.3 Update `tests/BaselineSchemaTest.php` migration list/table expectations to include `007 create_payments`, `payments`, `payment_events`, and remove `payments` from deferred assertions.
- [x] A.4 Update `tests/CatalogSchemaTest.php` migration list and deferred list so `payments` is no longer deferred; assert payment tables exist where appropriate.
- [x] A.5 Create `api/app/Payments/PaymentStateMap.php`, `PaymentRepository.php`, and `PaymentService.php` for initiate, legal transitions, lazy expiry, and idempotent auto-accept.
- [x] A.6 Add `OrderRepository::markAcceptedIfPending()` or equivalent guarded path; rejected/cancelled payment leaves order `pending`.
- [x] A.7 VERIFY Unit A with `PaymentServiceTest`, `BaselineSchemaTest`, and `CatalogSchemaTest` focused filters.

## Unit B: Mercado Pago webhook
- [x] B.1 Add `tests/MpWebhookHttpTest.php` for payments R4, webhook threat cases, duplicate events, provider failure, and orders R5 auto-accept.
- [x] B.2 Create `api/app/Payments/MpClient.php` curl wrapper with injectable base URL/token: `createPreference()` and `getPayment()`.
- [x] B.3 Implement MP signature validator for `x-signature` using timestamp + v1 HMAC manifest and `mp_webhook_secret` from business settings.
- [x] B.4 Add public `POST /api/webhooks/mercadopago` route in `api/bootstrap/app.php`/`public_html/index.php`, no session or CSRF.
- [x] B.5 Persist `payment_events`, confirm provider status by GET, map MP states to payment states, and return 200 for valid duplicates.
- [x] B.6 VERIFY Unit B with `MpWebhookHttpTest` and `PaymentServiceTest` focused filters.

## Unit C: Proofs, admin, settings, public payment UX
- [x] C.1 Add `tests/PaymentsAdminHttpTest.php` covering payments R3/R6, admin-shell R1-R3, and protected proof download.
- [x] C.2 Implement proof upload endpoint by public order token/admin with `finfo`, jpg/png/pdf allowlist, 5MB max, generated filename under `api/storage/proofs`.
- [x] C.3 Update `OrderPageController` and `order_confirmation.php` to show payment badge, MP button, transfer instructions, and upload form.
- [x] C.4 Add `PaymentsAdminController` plus Spanish templates for `/admin/pagos`, detail, verify/reject, filters, and proof streaming.
- [x] C.5 Add `SettingsRepository`, `/admin/configuracion`, and Spanish settings template for MP token/secret/enabled, transfer instructions, and expiry hours under `settings.manage`.
- [x] C.6 Wire admin routing and order detail actions; audit transfer verification/rejection and settings changes.
- [x] C.7 VERIFY Unit C with `PaymentsAdminHttpTest`, `OrderConfirmationHttpTest`, and targeted admin smoke filters.

## Traceability
- Payments R1-R7: A.1-A.7, B.1-B.6, C.1-C.7.
- Orders R5/R10: A.6, B.5, C.3.
- Admin-shell R1-R3/scope guard: C.1, C.4-C.6.
- Schema-baseline R1/payment tables: A.2-A.4.
