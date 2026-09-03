# Delta for Schema Baseline

## MODIFIED Requirements

### Requirement: Baseline Tables

Versioned migrations MUST create `businesses`, `branches`, `business_settings`, `branch_settings`, `users`, `roles`, `permissions`, `role_permissions`, `user_roles`, `user_branches`, `audit_log`, auth runtime tables, catalog tables, pricing/promotions tables, cart tables (with delivery zone/fee/payout columns), order/customer/stock/idempotency tables (with delivery zone snapshot and payout columns), `payments` plus `payment_events`, `delivery_zones`, `delivery_persons`, `delivery_person_branches`, `deliveries`, and the `notification_events` outbox table using InnoDB and utf8mb4.
(Previously: the baseline ended at migration `009` with the delivery schema.)

#### Scenario: Fresh baseline migration

- GIVEN an empty database
- WHEN migrations run through version `010`
- THEN all baseline, auth, catalog, pricing, cart, order, payment, delivery, and notification outbox tables exist
- AND table definitions use InnoDB-compatible FKs and utf8mb4-compatible text columns.

## ADDED Requirements

### Requirement S1: Migration 010 notification outbox

Migration `010` MUST create `notification_events` with auto-increment id, FK-scoped `business_id`, `event` (VARCHAR 64), `channel` ENUM(`email`,`whatsapp`), `recipient` VARCHAR(190), nullable `subject` VARCHAR(190), nullable `context_json` (resolution ids only — no PIN, secrets, or customer data beyond the recipient), `state` ENUM(`pending`,`sent`,`failed`) defaulting to `pending`, `attempts` TINYINT defaulting to 0, nullable `last_error` VARCHAR(500), `created_at` defaulting to current timestamp, and nullable `sent_at`; with lookup indexes on (business_id, state), `event`, and `created_at`. The migration MUST seed no permissions and no settings rows; notification flags are opt-in through the settings UI.

#### Scenario: Outbox table and indexes available

- GIVEN migrations run on a fresh database through version `010`
- WHEN table, column, and index metadata is inspected
- THEN `notification_events` exists with the required columns, defaults, and the three lookup indexes.

#### Scenario: Invalid business rejected

- GIVEN a `notification_events` insert referencing a nonexistent business
- WHEN the insert executes
- THEN the foreign key rejects it.

#### Scenario: Rerun is stable and default state is pending

- GIVEN migration `010` was applied
- WHEN the migration runner executes again
- THEN version `010` is not applied twice
- AND a fresh insert without explicit state persists as `pending` with `attempts` 0.

## MODIFIED Requirements (scope guard)

### Requirement: Schema Scope Guard

Baseline schema MUST NOT create updater, backup, worker, module, or future integration tables beyond payments, payment event history, the delivery schema (zones, persons, person-branch pivot, deliveries), and the `notification_events` notification outbox.
(Previously: the permitted set ended at the delivery schema.)

#### Scenario: Deferred tables absent

- GIVEN migrations completed through version `010`
- WHEN table names are listed
- THEN payment, delivery, and notification outbox tables exist
- AND updater, backup, worker, and module tables remain absent.
