# Delta for Schema Baseline

## MODIFIED Requirements

### Requirement: Baseline Tables
Versioned migrations MUST create `businesses`, `branches`, `business_settings`, `branch_settings`, `users`, `roles`, `permissions`, `role_permissions`, `user_roles`, `user_branches`, `audit_log`, auth runtime tables, catalog tables, pricing/promotions tables, cart tables, order/customer/stock/idempotency tables, `payments` plus `payment_events`, and — through migration `009` — delivery tables (`delivery_zones`, `delivery_persons`, `delivery_person_branches`, `deliveries`) plus new cart and order delivery columns, using InnoDB and utf8mb4.

#### Scenario: Fresh baseline migration
- GIVEN an empty database
- WHEN migrations run through version `009`
- THEN all baseline, auth, catalog, pricing, cart, order, payment, and delivery tables exist
- AND table definitions use InnoDB-compatible FKs and utf8mb4-compatible text columns.

### Requirement: Schema Scope Guard
Baseline schema MUST NOT create updater, backup, worker, module, or future integration tables beyond payments, payment event history, and the Phase 10 delivery tables created by migration `009`.

#### Scenario: Deferred tables absent
- GIVEN migrations completed through version `009`
- WHEN table names are listed
- THEN payment and delivery tables exist
- AND updater, backup, worker, and module tables remain absent.

## ADDED Requirements

### Requirement: Migration 009 delivery schema

Migration `009` MUST create `delivery_zones` (branch-scoped, match terms, independent customer/driver rates, active flag), `delivery_persons` (business-scoped, active flag), `delivery_person_branches` (unique person/branch pairs, cascade deletes), and `deliveries` (unique per order, nullable person FK `SET NULL`, `pending/assigned/picked_up/delivered/failed/cancelled` state enum, hashed PIN, attempt counter, transition timestamps, failed reason). It MUST add `carts.delivery_zone_id` (nullable FK `SET NULL`), `carts.delivery_fee_cents`, `carts.delivery_payout_cents` (default 0), and `orders.delivery_zone_name` (nullable), `orders.delivery_payout_cents` (default 0). It MUST seed the `deliveries.manage` permission idempotently (owner-linked) and a random `delivery.pin_key` business setting per existing business, both `WHERE NOT EXISTS`.

#### Scenario: Delivery tables and columns exist

- GIVEN migrations run through version `009`
- WHEN schema is inspected
- THEN the four delivery tables, cart columns, and order columns exist with the specified keys and defaults.

#### Scenario: Permission and PIN key seeds are idempotent

- GIVEN migration `009` runs twice
- WHEN seed statements execute
- THEN exactly one `deliveries.manage` permission (owner-linked) and one `delivery.pin_key` per business exist.

#### Scenario: State enum enforced

- GIVEN the `deliveries` table
- WHEN a row with an undefined state is inserted
- THEN the database rejects it.
