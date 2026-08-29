# Schema Baseline Specification

## Purpose

Defines the first versioned database baseline for installer provisioning, tenant/branch scope, RBAC seed data, settings, and audit records.

## Requirements

### Requirement: Baseline Tables

Versioned migrations MUST create `businesses`, `branches`, `business_settings`, `branch_settings`, `users`, `roles`, `permissions`, `role_permissions`, `user_roles`, `user_branches`, and `audit_log` using InnoDB and utf8mb4.

#### Scenario: Fresh baseline migration
- GIVEN an empty database
- WHEN migrations run
- THEN all baseline tables exist
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

Baseline schema MUST NOT create catalog, orders, payments, delivery, customers, updater, backup, worker, module, or authentication-session tables.

#### Scenario: Deferred tables absent
- GIVEN baseline migrations completed
- WHEN table names are listed
- THEN only installer baseline and migration infrastructure tables exist.
