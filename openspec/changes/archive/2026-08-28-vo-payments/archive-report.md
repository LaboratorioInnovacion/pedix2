# Archive Report — vo-payments (Phase 8)

**Archived:** 2026-08-28 · **Verdict:** verified PASS · **Suite at close:** 96 tests / 0 failures

## Scope delivered

Payment records, transfer verification, Mercado Pago integration, and payment UX (Phase 8 of Vender Online Core V1):

- **Unit A** — migration `007_create_payments` (payments + payment_events; integer money snapshots, 8-state legal constraints, provider identifiers, proof/verifier metadata, unique nullable external_reference, lookup indexes), `PaymentStateMap` (pending, approved, rejected, cancelled, pending_verification, verified, refund_pending, refund_completed — refund states defined but operationally unreachable in V1), `PaymentRepository`, `PaymentService` core (initiate per method — cash orders carry no payment row; transfer verification/rejection with audit; lazy expiry sweep; order auto-accept only from `pending`).
- **Unit B** — `MpClient` (injectable base URL/token, curl Checkout Pro preference + payment GET, typed credential-safe failures), webhook `POST /api/webhooks/mercadopago` (no session/CSRF — x-signature IS the auth; exact HMAC manifest + `hash_equals`; provider GET authoritative; sanitized allowlisted events committed atomically with transitions; event-id idempotency — MP `data.id` is the payment, webhook `id`/`x-request-id` is the dedupe key; approved-only auto-accept; duplicates 200; invalid signature 401).
- **Unit C** — `ProofStorage` (jpg/png/pdf, finfo MIME + extension, ≤5MB, random name, private `api/storage/proofs` outside public_html, replacement blocked after terminal state), public order page payment section (state badge, MP pay button, transfer instructions + upload form), `PaymentsAdminController` (`/admin/pagos` list/filter/detail, verify/reject under `payments.verify_transfer` + CSRF + audit request_id, protected proof streaming), `SettingsRepository` + `/admin/configuracion` under `settings.manage` (mp_enabled/mp_access_token/mp_webhook_secret/transfer_instructions; secrets never rendered or audited; empty secret POST preserves stored value).

## Verification summary

- 16/16 requirements across 4 capability deltas (payments 7, orders 2, admin-shell 4, schema-baseline 3).
- Phase-end suite: 96 tests / 0 failures (first 92 in the full run; harness 30-min timeout cut the last 4, which passed 4/4 focused; final focused rerun of all three payment test files 17/17).
- Independent inline probe: **20/20 checks** — exact amount snapshot vs grand total, verified→auto-accept with audit/event rows, double-verify typed rejection, reject keeps order pending, cash rejection with no payment row, lazy expiry cancelling only active-state payments (terminal states immune).
- Two probe expectations corrected during verification after confirming design semantics (sweep ignores terminal payments); both consistent with `PaymentServiceTest`.

## Execution notes (maintainer decisions this phase)

- Worker launches were repeatedly cancelled by model usage limits on pinned `openai/gpt-5.5`; Units B/C executed via `opencode run --agent build --model <override>` terminal route; afterwards ALL agent model pins were removed from `opencode.json` so agents inherit the session model (maintainer choice).
- Unit B ledger exceeded 400 lines (680, inherited partial work + extensive HTTP tests): maintainer chose **split verification** — B1 (MpClient/signature) and B2 (webhook/idempotency) verified separately over the preserved pushed commit `cb66ded`; the ledger was reset with explicit maintainer reason. No source rewrites.
- NO TDD/RED-first per maintainer preference (recorded Phase 7): code and tests authored together, tests as verification evidence.

## Specs synced

- NEW: `openspec/specs/payments/` (7 requirements).
- MERGED: `orders` R5 rewritten (payment-driven auto-accept; rejected/cancelled payments keep order pending) + R10 added (public payment visibility); `admin-shell` scope guard rewritten (payments/settings pages now permitted) + payments section, transfer actions, and settings page requirements added; `schema-baseline` Baseline Tables + Scope Guard rewritten through migration 007 + payment persistence tables requirement added.
- Main spec count: **18 capabilities**.

## Open follow-ups (non-blocking)

1. Harness softening: Unit C added a SKIP-when-DB-down path — restore fail-fast or surface a skipped-count summary (loud-DB rule regression risk).
2. Full suite >30 min at 96 tests — shard or per-class timing.
3. MariaDB as Windows service (pending maintainer admin step); interim: detached `mysqld.exe --defaults-file=...` survives shell cleanup (verified).
4. MP credentials via `firstBusinessId()` — single-business boundary; per-business account routing when multi-business lands.
5. `IdempotencyConflictException` → 409 mapping (carried from Phase 7); prorated line-total legend (carried).
6. Payment expiry hours fixed by design defaults — expose in settings when Phase 9 operations lands.
7. Maintainer commit decision: working tree carries Phases 2B-8 uncommitted on top of `cb66ded`.

## Traceability

Engram topic keys: `sdd/vo-payments/proposal` (3724), `spec`/`design`/`tasks` (3725-3727), `apply-progress` (3728), `verify-report` (saved), this archive report. Probe: `%TEMP%\opencode\vo8_verify_probe.php`.
