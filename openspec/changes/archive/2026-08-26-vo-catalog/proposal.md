# Proposal: VO Catalog

## Intent
Implement Phase 4 catalog foundations: merchant-managed products/services, variants, modifiers, branch availability, and a minimal read-only storefront without cart, checkout, pricing resolution, or inventory accounting.

## Scope
### In Scope
- Migration `003`: category tree, catalog items, variants, modifiers, branch overrides, image metadata, and `products.manage` owner seed.
- `CatalogRepository`/`CatalogService`: validation, archive-not-delete, and `AuditService` events for price, availability, archive changes.
- Spanish `/admin/` CRUD and branch configuration, guarded by `products.manage`, CSRF, auth, and branch scope where applicable.
- Public routes `/`, `/categoria/{slug}`, `/producto/{slug}`, `/buscar?q=` for browsing, search, stored prices, variants/modifiers, and any-active-branch availability.

### Out of Scope
- Cart, checkout, `PricingService`, promotions, inventory accounting, order snapshots, uploads, customer accounts, delivery.

## Assumptions
- `products.manage` is the broad CRUD key, matching `users.manage`/`settings.manage`; reversible by data migration.
- Public catalog lives at `/`; branch choice waits for checkout, and items show when any active branch offers them.
- `stock_mode` is `none|simple|unlimited`; reversible before inventory accounting.
- `item_images` stores `filename`, sort, and alt only; secure processing is deferred.
- Services support variants/modifiers because the spec only excludes delivery, stock, and agenda.

## Capabilities
### New Capabilities
- `catalog-schema`: Migration 003 tables, constraints, archive columns, branch overrides, image metadata, permission seed.
- `catalog-admin`: Admin CRUD and branch catalog configuration with Spanish UI, authorization, CSRF, audits.
- `catalog-public`: Read-only storefront catalog routes/displays.

### Modified Capabilities
- None. Admin shell remains shell-only; catalog screens are covered by `catalog-admin`.

## Approach
Follow `Route -> Middleware -> Controller -> Service -> Repository -> MySQL`: integer cents, backend price/state authority, PDO prepared statements, transactions, archive filters, no forbidden dependencies.

## Affected Areas
| Area | Impact | Description |
|---|---|---|
| `api/migrations/003_*` | New | Catalog schema and seed data |
| `api/app/Catalog/` | New | Repository/service logic |
| `public_html/admin/` | New | Catalog admin routes/screens |
| `public_html/index.php` | Modified | Public catalog routes |

## Risks
| Risk | Likelihood | Mitigation |
|---|---|---|
| Exceeds 400-line review budget | High | Split apply into schema/service, admin, public units |
| Public display becomes hidden PricingService | Med | Display stored prices only |
| Migration mistakes affect tenant data | Med | Idempotent migration tests and rollback SQL |

## Rollback Plan
Disable routes/screens, revert code, and run guarded down SQL for empty Phase 4 tables plus `products.manage` seed rows.

## Dependencies
- Migrations 001/002, RBAC, CSRF/session runtime, branch scope, `AuditService`, 47-test green baseline.

## Success Criteria
- [ ] Migration 003 creates expected tables/constraints and seeds owner `products.manage`.
- [ ] Admin catalog actions are permission/CSRF guarded and audited.
- [ ] Public catalog lists only active, non-archived, available items and supports categories, detail, and search.
