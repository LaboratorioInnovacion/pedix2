# Archive Report — vo-operations (Phase 9)

**Archived:** 2026-08-28 · **Verdict:** verified PASS · **Suite at close:** 113 tests / 0 failures (full run 103/103 before external abort + exact 12-test tail rerun focused 12/12)

## Scope delivered

Order operations lifecycle (Phase 9 of Vender Online Core V1):

- **Unit A** — migration `008_operations` (stock_movements reason ENUM+CHECK extended with `consume_accepted`; `DROP CONSTRAINT` for MariaDB 10.4), `StockService::consume()` (guarded idempotent consume on accept), `OrderOperationsService` — the single audited transition gateway: accept/reject from pending+change_proposed, prepare/mark-ready/complete gated `orders.prepare`, cancel with REQUIRED reason (`release_cancelled` + pending payment cancelled + approved payment stays approved with `refund_required` audit), `acceptFromPayment` no-wrap path (PdoConnection forbids nesting — payment auto-accept routed through gateway so stock consumes exactly once), `expireStaleLazy()` with TTL setting `orders.order_expiry_hours` (default 24) releasing stock and cancelling pending payments, `PaymentService::cancelPaymentWithinTransaction` added for the same no-nesting reason.
- **Unit B** — `/admin/operacion` Spanish operations board (`OperationsAdminController` + template): sections per status, legal-buttons-only per state/permission, branch filter, `?auto=1` meta-refresh, CSRF/permission/reason validation on POST, all denials audited; `orders_detail` transition actions + cancel-with-reason (orders list stays button-free per existing test contract); "Operación" dashboard card; sweep triggers on admin list + public order page reads.

No permission migration needed: `InstallerSeeder` already seeds the granular `orders.*` keys.

## Verification summary

- 12/12 requirements (operations 7 NEW, inventory-stock R6, admin-shell board/actions/navigation/scope).
- OperationsServiceTest 13/13 (kitchen chain with per-step audits, illegal-transition typed rejection, permission denial audited, consume-once + double-accept no-op, movement reason constraints, cancel reason-required + release + payment interaction, approved-stays + refund_required, expiry sweep default and custom TTL, in-transaction acceptFromPayment, boardRows branch scoping).
- AdminOperationsHttpTest 4/4 over real HTTP (board rendering, guard/CSRF/permission 403/419, cancel stock+payment side effects, detail buttons + list stays clean + board-triggered expiry).
- 4 documented non-blocking deviations (MariaDB DDL syntax, reason name, cancelPaymentWithinTransaction, cosmetic button label).

## Execution notes

- NO TDD (maintainer preference); velocity mode (combined planning, focused tests per unit, one phase-end suite).
- Worker route worked for both apply units; verify done inline (bounded).
- Maintainer aborted the phase-end suite run at 103/103 PASS; the remaining 12 tests were rerun focused (12/12) — combined evidence covers the full suite.

## Specs synced

- NEW: `openspec/specs/operations/` (7 requirements).
- MERGED: `inventory-stock` + R6 (consume on acceptance); `admin-shell` + 3 requirements (board, transition actions, navigation) + scope guard rewritten (operations now spec-governed, "operation/delivery boundaries" removed from MUST-NOT).
- Main spec count: **19 capabilities**.

## Open follow-ups (non-blocking)

1. Sweep runs one transaction per order and aborts remaining on first failure (payments parity) — harden when Phase 12 reporting needs sweep metrics.
2. Board auto-refresh is meta-tag based — JS polling only if operators request it.
3. Carried: harness SKIP-when-DB-down; suite sharding (>30 min at 113 tests); MariaDB service install; per-business MP routing; IdempotencyConflict→409; prorated-lines legend.
4. Maintainer commit decision: working tree now carries Phases 2B-9 uncommitted (baseline `cb66ded`).

## Traceability

Engram: `sdd/vo-operations/{explore,proposal,spec,design,tasks,apply-progress,verify-report}` (obs 3868-3935). Focused test files: `tests/OperationsServiceTest.php`, `tests/AdminOperationsHttpTest.php`.
