# Delta for Schema Baseline

## MODIFIED Requirements

### Requirement: Baseline Tables
Versioned migrations MUST create `businesses`, `branches`, `business_settings`, `branch_settings`, `users`, `roles`, `permissions`, `role_permissions`, `user_roles`, `user_branches`, `audit_log`, auth runtime tables, catalog tables, pricing/promotions tables, cart tables, order/customer/stock/idempotency tables, and Phase 8 `payments` plus `payment_events` using InnoDB and utf8mb4.
(Previously: this requirement named only the initial baseline tables.)

#### Scenario: Fresh baseline migration
- GIVEN an empty database
- WHEN migrations run through version `007`
- THEN all baseline, auth, catalog, pricing, cart, order, and payment tables exist
- AND table definitions use InnoDB-compatible FKs and utf8mb4-compatible text columns.

### Requirement: Schema Scope Guard
Baseline schema MUST NOT create delivery, updater, backup, worker, module, or future integration tables beyond payments and payment event history.
(Previously: payments and customers were still listed as deferred tables.)

#### Scenario: Deferred tables absent
- GIVEN migrations completed through version `007`
- WHEN table names are listed
- THEN payment tables exist
- AND delivery, updater, backup, worker, and module tables remain absent.

## ADDED Requirements

### Requirement R1: Payment persistence tables
Migration `007` MUST create `payments` and `payment_events` with integer money snapshots, legal state constraints, provider identifiers, proof metadata, verifier metadata, timestamps, FK scope, and indexes for order, business, state, method, provider payment, and external reference lookups.

#### Scenario: Payment tables available
- GIVEN migrations run on a fresh database
- WHEN table and index metadata is inspected
- THEN `payments` and `payment_events` exist with required constraints and lookup indexes.

#### Scenario: External reference duplicate rejected
- GIVEN a payment has a non-null external reference
- WHEN another payment uses the same reference
- THEN the database rejects the duplicate.
