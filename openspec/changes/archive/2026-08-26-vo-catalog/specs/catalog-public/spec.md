# Catalog Public Specification

## Purpose

Defines read-only Spanish storefront browsing for Phase 4 catalog data.

## Requirements

### Requirement: Public Catalog Routes

The public storefront MUST expose read-only `GET /`, `GET /categoria/{slug}`, `GET /producto/{slug}`, and `GET /buscar?q=` routes. These routes MUST render Spanish UI and MUST escape all dynamic output according to context.

#### Scenario: Public routes render safely
- GIVEN catalog data exists
- WHEN a visitor opens a catalog, category, product, or search page
- THEN a Spanish HTML page renders escaped catalog content.

### Requirement: Public Visibility Rules

Public catalog reads MUST exclude archived categories, items, variants, modifier groups, and modifiers. An item MUST be shown when it is active, non-archived, and available in at least one active branch; branch selection is not required in Phase 4.

#### Scenario: Available in any active branch
- GIVEN an item is available in one active branch and unavailable elsewhere
- WHEN the public catalog is listed
- THEN the item is shown.

#### Scenario: Archived or inactive data hidden
- GIVEN catalog rows are archived or unavailable in all active branches
- WHEN public pages are rendered
- THEN those rows are not shown.

### Requirement: Display-Only Stored Price

Public pages MUST display stored prices only using this display order: branch variant override, variant price, branch item override, product base price. This is display-only until Phase 5 and MUST NOT calculate promotions, totals, discounts, delivery fees, or checkout prices.

#### Scenario: Stored price fallback
- GIVEN stored price values exist at several levels
- WHEN a product is displayed
- THEN the first available stored value in the documented order is shown
- AND no promotion or final total is calculated.

### Requirement: Search and Category Browsing

Search MUST use parameterized, collation-safe name prefix/contains matching. Category pages MUST support tree browsing from parent/child category relationships and show only visible catalog items.

#### Scenario: Safe search
- GIVEN public items named with mixed case or accents
- WHEN `/buscar?q=` is requested with user input
- THEN matching visible items are returned through parameterized search.

#### Scenario: Category tree browsing
- GIVEN parent and child categories exist
- WHEN a category page is opened
- THEN visible child categories and visible items in the category tree are shown.

### Requirement: Public Catalog Scope Guard

Public catalog MUST NOT create carts, checkout sessions, orders, promotions, pricing-engine results, stock movements, reservations, uploads, customer accounts, payments, or delivery workflows.

#### Scenario: Read-only storefront
- GIVEN a visitor browses the public catalog
- WHEN they interact with Phase 4 routes
- THEN only browsing/search/detail behavior is available and no commerce state is created.
