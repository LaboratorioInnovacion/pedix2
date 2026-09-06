# Catalog Admin Specification

## Purpose

Defines authenticated Spanish admin behavior for catalog CRUD, branch catalog configuration, validation, and audit boundaries.

## Requirements

### Requirement: Catalog Admin Authorization

All catalog admin GET and POST actions MUST require authentication and `products.manage`. Branch configuration actions MUST additionally validate the requested branch through explicit `user_branches` scope. All POST actions MUST require CSRF.

#### Scenario: Permission and CSRF required
- GIVEN an authenticated user without `products.manage` or with an invalid CSRF token
- WHEN a catalog admin state change is submitted
- THEN the request is rejected and no catalog state changes.

#### Scenario: Branch scope required
- GIVEN a user has `products.manage` but no assignment to the target branch
- WHEN branch catalog settings are submitted
- THEN the request is denied with 403.

### Requirement: Catalog CRUD Without Hard Delete

Admins with permission MUST create, edit, and archive categories, items, variants, modifier groups, modifiers, and item/group links. Admin actions MUST archive rows instead of physically deleting them during normal operation.

#### Scenario: Archive item
- GIVEN an admin is authorized and an item exists
- WHEN the admin archives the item
- THEN `archived_at` is set
- AND the item is absent from default admin and public catalog lists.

### Requirement: Admin Validation and Publish Rules

Validation errors MUST be shown in Spanish. Item names, slugs, types, money cents, selection bounds, and service delivery rules MUST be validated server-side. An item with `requires_variant=1` MUST have at least one active, non-archived variant before it can be saved as active/published.

#### Scenario: Variant requirement blocks activation
- GIVEN an item has `requires_variant=1` and no active variant
- WHEN the admin attempts to save it as active
- THEN the save is rejected with a clear Spanish validation error.

#### Scenario: Service delivery is rejected
- GIVEN an admin edits a service item
- WHEN delivery is enabled for the service
- THEN the save is rejected with a Spanish validation error.

### Requirement: Branch Catalog Configuration

Authorized branch admins MUST configure item/variant availability, item price overrides, variant price overrides, and item `stock_mode` for assigned branches only. Price overrides MAY be null to fall back to stored master prices.

#### Scenario: Branch override saved
- GIVEN an authorized user is assigned to a branch
- WHEN item availability, stock mode, and price override are saved
- THEN branch override values are stored for that branch only.

### Requirement: Catalog Admin Audit

Price changes MUST write audit records containing old and new cents. Availability changes and archive actions MUST write audit records with safe metadata and request context.

#### Scenario: Price change is audited
- GIVEN an item or variant price is changed by an authorized admin
- WHEN the save succeeds
- THEN audit log metadata includes old cents, new cents, target type, and target id.

#### Scenario: Availability or archive is audited
- GIVEN availability or archive state changes
- WHEN the save succeeds
- THEN an audit log entry records the state transition without secrets.

### Requirement: Catalog Admin Scope Guard

Catalog admin MUST NOT implement cart, checkout, order creation, promotions, PricingService, stock movements, reservations, upload processing, customer accounts, payments, or delivery workflows.

#### Scenario: Forbidden behavior absent
- GIVEN the catalog admin is available
- WHEN an admin attempts deferred commerce operations
- THEN this capability provides no such behavior.
