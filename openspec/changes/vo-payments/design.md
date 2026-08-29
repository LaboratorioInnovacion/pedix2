# Design: Payments

## Technical Approach
Add `api/app/Payments` and wire it into existing Route → Controller → Service → Repository → MySQL flows. Payments are independent from orders, but `PaymentService` owns the only integration point that calls `OrderRepository::markStatus($orderId, 'accepted')` when a payment reaches `verified` or `approved` and the order is still `pending`.

## Architecture Decisions
| Topic | Decision | Rationale |
|---|---|---|
| Mercado Pago | REST via curl in `MpClient`, no SDK | Shared hosting has no Composer/runtime SDK guarantee; injectable base URL/token keeps tests deterministic. |
| Webhook trust | HMAC `x-signature` + provider GET confirmation | Webhook body is transport metadata, not source of truth. |
| Proof files | `api/storage/proofs/{business_id}/{order_number}/random64.ext` | Keeps customer uploads outside `public_html`; only admin streaming route exposes them. |
| Cash | No payment row | Cash is operational order policy, not online payment tracking. |
| Expiry | Lazy read sweep | No cron/worker dependency in Core V1. |

## Migration 007
Create `api/database/migrations/007_create_payments.sql.php`:
- `payments`: `id`, `business_id`, `order_id`, `method ENUM('transfer','mercadopago','other')`, `state ENUM('pending','approved','rejected','cancelled','pending_verification','verified','refund_pending','refund_completed')`, `amount_cents INT NOT NULL`, `currency CHAR(3) DEFAULT 'ARS'`, `external_reference VARCHAR(190) NULL`, `provider_payment_id VARCHAR(190) NULL`, `provider_preference_id VARCHAR(190) NULL`, `provider_status VARCHAR(64) NULL`, `provider_status_detail VARCHAR(191) NULL`, `preference_init_point TEXT NULL`, `proof_path VARCHAR(500) NULL`, `proof_mime VARCHAR(100) NULL`, `proof_original_name VARCHAR(191) NULL`, `proof_size_bytes INT UNSIGNED NULL`, `verified_by_user_id BIGINT UNSIGNED NULL`, `verified_at/rejected_at/cancelled_at/expires_at DATETIME NULL`, timestamps. Constraints: `UNIQUE order_id`, `UNIQUE external_reference` (nullable-safe in MySQL), indexes on business/state/method/provider IDs. FKs: business/order `RESTRICT` for audit-preserving deletes; verifier `SET NULL`.
- `payment_events`: `id`, `payment_id NULL`, `business_id`, `provider VARCHAR(32)`, `event_type VARCHAR(100)`, `provider_event_id VARCHAR(190) NULL`, `external_reference VARCHAR(190) NULL`, `payload_json JSON NULL`, `signature_valid TINYINT(1)`, `result_state VARCHAR(32) NULL`, `received_at`, `processed_at NULL`; unique `(provider,provider_event_id)`, indexes on payment/business/external_reference; payment `SET NULL`, business `CASCADE`.

## State Map and Service Contracts
`PaymentStateMap`: transfer `pending_verification→verified|rejected|cancelled`; MP `pending→approved|rejected|cancelled`; expiry `pending|pending_verification→cancelled`; refund `refund_pending→refund_completed` present but unreachable by V1 UI/API.

`PaymentService` methods:
- `initiateForOrder(int $orderId, string $method): array` creates non-cash payment; MP also creates preference when `mp_enabled` and token exist.
- `verifyTransfer(int $paymentId, int $actorId)` / `rejectPayment(...)` enforce legal state and write audit via controller-supplied actor.
- `handleMpWebhook(string $rawBody, ?string $signatureHeader): array` validates signature, stores event, GET-confirms payment, transitions idempotently, and returns 200 for valid duplicates.
- `expireStaleLazy(int $businessId): void` cancels stale pending records on payment reads.

## Routing and UI
Public: `POST /api/webhooks/mercadopago` has no session/CSRF; signature is auth, bad signature returns 401. `POST /pedido/{token}/comprobante` validates jpg/png/pdf ≤5MB with `finfo`. `/pedido/{token}` shows state badge, MP button, transfer instructions/upload.

Admin: route `/admin/pagos`, `/admin/pagos/{id}`, proof download, verify/reject POSTs; add `/admin/configuracion`. Use Spanish templates, existing CSRF/session patterns, `payments.verify_transfer` for transfer actions, `settings.manage` for settings. Business settings remain key/value rows in `business_settings`; add a small `SettingsRepository` with request-scope caching and secret masking.

## File Changes
Create `Payments/*`, migration 007, tests `PaymentServiceTest`, `MpWebhookHttpTest`, `PaymentsAdminHttpTest`. Modify order service/repository/page/templates, admin router/controllers/templates, public front controller, schema tests.

## Testing Strategy
Focused commands: `php tests/run.php --filter PaymentServiceTest`, `--filter MpWebhookHttpTest`, `--filter PaymentsAdminHttpTest`, plus `--filter BaselineSchemaTest` and `--filter CatalogSchemaTest` after migration fixture updates.

## Threat Matrix
Applicable: public webhook routing, admin/public upload routing, file streaming, curl provider integration. Safe behavior: bad signatures 401/no transition; invalid uploads rejected/no public URL; provider failures keep pending; duplicate events 200/no duplicate transition.

## Open Questions
None.
