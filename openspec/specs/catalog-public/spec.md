# Catalog Public Specification

## Purpose

Defines Spanish storefront browsing for Phase 4 catalog data, plus the Phase 6 guest cart and checkout preview pages. Cart and checkout pages retrieve live JSON from the cart API; frontend JavaScript MUST NOT compute final prices.

## Requirements

### Requirement: Public Catalog Routes

The public storefront MUST expose `GET /`, `GET /categoria/{slug}`, `GET /producto/{slug}`, `GET /buscar?q=`, `GET /carrito`, and `GET /checkout`. These routes MUST render Spanish UI and MUST escape all dynamic output according to context. Cart and checkout pages MAY load live JSON data from the cart API, but frontend JavaScript MUST NOT compute final prices.

#### Scenario: Public routes render safely
- GIVEN catalog data exists
- WHEN a visitor opens a catalog, category, product, search, cart, or checkout page
- THEN a Spanish HTML page renders escaped dynamic content.

#### Scenario: Cart and checkout pages use backend preview
- GIVEN a visitor opens `/carrito` or `/checkout`
- WHEN the page needs cart totals
- THEN it retrieves backend cart/preview JSON
- AND it does not calculate final totals in JavaScript.

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

Public catalog pages MUST NOT create orders, promotions, pricing-engine side effects, stock movements, reservations, uploads, customer accounts, payments, or delivery workflows. Phase 6 pages MAY create and mutate guest cart state only through the cart API.

#### Scenario: Storefront cart state only
- GIVEN a visitor browses public storefront pages
- WHEN they use Phase 6 cart or checkout interactions
- THEN only guest cart rows may be created or mutated
- AND no order/payment/stock/delivery state is created.
