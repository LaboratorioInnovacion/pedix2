# Schema Baseline Specification

## Purpose

Defines the first versioned database baseline for installer provisioning, tenant/branch scope, RBAC seed data, settings, and audit records.

## Requirements

### Requirement: Baseline Tables
Versioned migrations MUST create `businesses`, `branches`, `business_settings`, `branch_settings`, `users`, `roles`, `permissions`, `role_permissions`, `user_roles`, `user_branches`, `audit_log`, auth runtime tables, catalog tables, pricing/promotions tables, cart tables (with delivery zone/fee/payout columns), order/customer/stock/idempotency tables (with delivery zone snapshot and payout columns), `payments` plus `payment_events`, `delivery_zones`, `delivery_persons`, `delivery_person_branches`, `deliveries`, and the `notification_events` outbox table using InnoDB and utf8mb4.

#### Scenario: Fresh baseline migration
- GIVEN an empty database
- WHEN migrations run through version `010`
- THEN all baseline, auth, catalog, pricing, cart, order, payment, delivery, and notification outbox tables exist
- AND table definitions use InnoDB-compatible FKs and utf8mb4-compatible text columns.

### Requirement: Required Constraints

Baseline tables MUST define primary keys, required foreign keys, unique user emails, created/updated timestamps where mutable, and uniqueness constraints for RBAC/settings mappings.

#### Scenario: Constraint enforcement
- GIVEN baseline tables exist
- WHEN duplicate user email or duplicate mapping data is inserted
- THEN the database rejects the duplicate
- AND invalid child rows are rejected by FKs.

### Requirement: Migration Idempotency

Baseline migrations MUST be versioned and idempotent through existing `MigrationRunner` semantics, including `schema_migrations` registration and duplicate-version refusal.

#### Scenario: Migration rerun
- GIVEN baseline migrations were applied
- WHEN the migration runner executes again
- THEN baseline migrations are not applied a second time
- AND recorded versions remain stable.

### Requirement: Phase 2 Seed Data

Installer seed data MUST create an owner role and store the permission keys `orders.view`, `orders.accept`, `orders.reject`, `orders.modify`, `orders.prepare`, `orders.mark_ready`, `orders.cancel`, `products.edit_price`, `products.change_availability`, `products.manage_stock`, `deliveries.assign`, `deliveries.reassign`, `payments.verify_transfer`, `settings.manage`, `users.manage`, and `reports.view` as data, not schema.

#### Scenario: Owner permissions seeded
- GIVEN a successful install
- WHEN RBAC data is inspected
- THEN the owner role has the Phase 2 permission rows
- AND permission keys are stored as rows, not hard-coded columns or tables.

### Requirement: Schema Scope Guard
Baseline schema MUST NOT create updater, backup, worker, module, or future integration tables beyond payments, payment event history, the delivery schema (zones, persons, person-branch pivot, deliveries), and the `notification_events` notification outbox.

#### Scenario: Deferred tables absent
- GIVEN migrations completed through version `010`
- WHEN table names are listed
- THEN payment, delivery, and notification outbox tables exist
- AND updater, backup, worker, and module tables remain absent.

### Requirement: Migration 010 notification outbox
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

### Requirement: Migration 009 delivery schema
Migration `009` MUST create `delivery_zones`, `delivery_persons`, `delivery_person_branches`, and `deliveries` with branch scoping, match terms, independent customer/driver rates, a guarded deliveries state machine (hash-based PIN, attempt counter, failure reason), extend carts and orders with delivery zone/fee/payout columns, seed the `deliveries.manage` permission, and provision per-business `delivery.pin_key` settings for businesses existing at migration time (later businesses are provisioned lazily).

#### Scenario: Delivery tables and columns available
- GIVEN migrations run through version `009`
- WHEN table and column metadata is inspected
- THEN the delivery tables exist with state/audit columns, and carts/orders expose the delivery zone, fee, and payout columns.

#### Scenario: Permission and pin key seeds
- GIVEN migration `009` has run
- WHEN permissions and business settings are inspected
- THEN `deliveries.manage` exists bound to the owner role when that role pre-exists, and businesses present at migration time have a non-empty `delivery.pin_key`.

### Requirement: Payment persistence tables
Migration `007` MUST create `payments` and `payment_events` with integer money snapshots, legal state constraints, provider identifiers, proof metadata, verifier metadata, timestamps, FK scope, and indexes for order, business, state, method, provider payment, and external reference lookups.

#### Scenario: Payment tables available
- GIVEN migrations run on a fresh database
- WHEN table and index metadata is inspected
- THEN `payments` and `payment_events` exist with required constraints and lookup indexes.

#### Scenario: External reference duplicate rejected
- GIVEN a payment has a non-null external reference
- WHEN another payment uses the same reference
- THEN the database rejects the duplicate.
