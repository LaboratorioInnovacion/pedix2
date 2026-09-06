# Catalog Schema Specification

## Purpose

Defines Phase 4 catalog persistence for single-install business catalog data, branch overrides, image metadata, and permission seed data.

## Requirements

### Requirement: Migration 003 Catalog Tables

Migration `003` MUST create `categories`, `catalog_items`, and `item_variants` as InnoDB/utf8mb4 tables without `business_id`, because each installation is single-tenant. Categories MUST support `parent_id` adjacency, unique `slug`, `name`, active state, nullable `archived_at`, and timestamps. Catalog items MUST support `type ENUM('product','service')`, unique `slug`, `name`, `description`, nullable `base_price_cents BIGINT`, `requires_variant`, pickup/delivery flags, nullable `archived_at`, and timestamps. Variants MUST reference items and store `name`, nullable `sku`, `price_cents BIGINT`, availability, sort order, and nullable `archived_at`.

#### Scenario: Catalog base schema exists
- GIVEN migrations run on an installed database
- WHEN migration `003` completes
- THEN category, item, and variant tables exist with FKs, unique slugs, indexes, timestamps, and archive columns.

### Requirement: Modifier Persistence

Migration `003` MUST create modifier groups, modifiers, and item/group links. Groups MUST store `name`, `is_required`, `selection ENUM('single','multi')`, `min_select`, `max_select`, and nullable `archived_at`; modifiers MUST store `price_delta_cents BIGINT DEFAULT 0`, availability, sort order, and nullable `archived_at`; links MUST store item, group, and sort order.

#### Scenario: Modifier schema supports selection rules
- GIVEN catalog tables exist
- WHEN a required multi-select group with modifiers is stored
- THEN selection bounds, paid/free deltas, item links, and archived states are represented without duplicate links.

### Requirement: Branch Overrides

Migration `003` MUST create `branch_items` and `branch_variants` with FKs to existing branches and catalog rows. Branch items MUST store availability, nullable price override cents, and `stock_mode ENUM('none','simple','unlimited') DEFAULT 'none'`; branch variants MUST store availability and nullable price override cents.

#### Scenario: Branch-specific availability and prices
- GIVEN a branch and catalog item exist
- WHEN branch override rows are saved
- THEN branch availability, optional price overrides, and stock mode are stored independently from master catalog rows.

### Requirement: Item Image Metadata

Migration `003` MUST create `item_images` with item FK, `filename`, sort order, and alt text only. It MUST NOT implement upload processing, file validation, or stored-file ownership behavior.

#### Scenario: Image metadata only
- GIVEN an item exists
- WHEN image metadata is stored
- THEN filename, sort, and alt are persisted
- AND no upload endpoint or binary storage behavior is required.

### Requirement: Permission Seed and Archive Semantics

Migration `003` MUST seed `products.manage` and grant it to the owner role idempotently. Catalog records MUST use archive-not-delete semantics: normal delete behavior SHALL set `archived_at`, and default catalog queries MUST exclude archived rows.

#### Scenario: Owner can receive catalog permission
- GIVEN migration `003` is applied more than once
- WHEN permissions and owner links are inspected
- THEN exactly one `products.manage` permission and owner grant exist.

#### Scenario: Archived rows are hidden by default
- GIVEN a category, item, variant, group, or modifier has `archived_at` set
- WHEN default catalog reads execute
- THEN the archived row is excluded.

### Requirement: Catalog Schema Scope Guard

Migration `003` MUST NOT create cart, checkout, order, promotion, pricing engine, stock movement, reservation, upload-processing, customer, payment, or delivery tables.

#### Scenario: Deferred tables remain absent
- GIVEN migration `003` completes
- WHEN database tables are listed
- THEN only catalog schema and permission seed changes from this capability are present.
