# Archive Report — vo-delivery (Phase 10)

**Archived:** 2026-08-28 · **Verdict:** verified PASS · **Suite at close:** 133 tests / 0 failures (run as 3 serial chunks)

## Scope delivered

Delivery coverage, rates, and courier handover (Phase 10 of Vender Online Core V1):

- **Unit A** — migration `009_delivery` (delivery_zones with match terms + independent customer/driver rates, delivery_persons + branch pivot, deliveries with own state machine and hashed-PIN columns, cart/order delivery columns, `deliveries.manage` permission seed, per-business `delivery.pin_key` seed), `ZoneMatcher` (accent/case normalization, comma/slash terms, first active zone by id), cart checkout-data zone resolution (typed Spanish 422 `delivery_unavailable`), **fee integrity fix**: OrderService now forces the cart-stored fee (client-supplied delivery fee ignored; grand-total lies rejected as PRICE_CHANGED), zone-name + payout snapshots onto orders, `deliveries` row created pending.
- **Unit B** — `DeliveryStateMap` + `DeliveryService` (assign/reassign/pickup/deliver/fail/cancel with per-action permissions and user_branches scoping), deterministic HMAC 6-digit PIN (SHA-256 at rest, constant-time verify, 5 attempts → `failed/pin_exhausted`, recomputable for redisplay, never audited), `completeFromDelivery` no-nesting path driving order `ready→completed`, order-cancel cascade cancelling active deliveries in-transaction, board/detail Spanish actions (asignar/reasignar/retirar/entregar/fallar) with CSRF.
- **Unit C** — `/admin/delivery` zones+persons CRUD (`deliveries.manage`, branch-scoped, deactivate-if-referenced), dashboard card, public `/pedido/{token}` delivery section (state badge, courier, PIN gated by state — hidden for terminal states).

Order state machine untouched: the spec's 9 states hold; delivery lifecycle lives exclusively on the `deliveries` table.

## Verification summary

- 16/16 requirements (delivery 9, cart-api 2, orders 2, schema-baseline 3).
- Suite: run as 3 serial family chunks (Admin/Cart/Catalog 38/38 · Csrf→Mp 30/30 · Order/Payment/Delivery 65/65 after one fixture fix) = **133/133**. The single-window suite no longer fits in 60 min (structural, ~20-35s DDL per scratch DB) — chunked runs are the working evidence format.
- Fixture fix during verify: PermissionGuardTest explicit-ID clash with migration-seeded `deliveries.manage` (2nd occurrence of this class; fixture now clears all permissions first).
- Test-semantics fix during apply: PRICE_CHANGED scenario required a fresh cart (successful creation clears the current one).

## Execution notes

- NO TDD (maintainer preference); velocity mode. Unit A finished inline after a mid-run worker interruption (established recovery route); Units B/C ran as workers.
- Worker flag worth noting: `public_html/index.php` builds OrderOperationsService without the delivery cascade (safe today — public path never cancels orders).

## Specs synced

- NEW: `openspec/specs/delivery/` (9 requirements).
- MERGED: `cart-api` R5 (resolved fee in quotes) + R6 (coverage resolution at checkout-data, typed no-match); `orders` R1 (server-forced fee, zone/payout snapshot, pending delivery row) + new "state machine unchanged by delivery" requirement; `schema-baseline` Baseline Tables + Scope Guard through migration 009 + new Migration 009 requirement.
- Main spec count: **20 capabilities**.

## Open follow-ups (non-blocking)

1. Harness: shard by family permanently or add schema-template caching; keep SKIP-when-DB-down fix on the list.
2. Driver panel, auto-assignment, offers/timeouts/capacity, driver-side states — deferred future slice (documented in proposal).
3. Public page OrderOperationsService lacks delivery cascade (safe today; wire if public paths ever mutate orders).
4. Manual board "Completar" on a ready delivery order leaves delivery picked_up — business alignment decision.
5. Carried: MariaDB service install; per-business MP routing; IdempotencyConflict→409; prorated-lines legend.
6. Maintainer commit decision: working tree carries Phases 2B-10 uncommitted on `cb66ded`.

## Traceability

Engram: `sdd/vo-delivery/{proposal(3938),spec(3942),design(3943),tasks(3944),apply-progress(3969),verify-report}`. Focused test files: `tests/DeliveryZoneTest.php`, `tests/DeliveryServiceTest.php`, `tests/DeliveryAdminHttpTest.php`.
