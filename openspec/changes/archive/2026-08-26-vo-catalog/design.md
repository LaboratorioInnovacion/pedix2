# Design: VO Catalog

## Technical Approach

Implement Phase 4 as three reviewable units following the existing shared-hosting PHP path: route boundary -> controller -> `CatalogService` -> `CatalogRepository` -> PDO/MySQL. `public_html/admin/index.php` remains the admin front controller, but catalog routes are dispatched from `AdminController` into a new `CatalogAdminController` to avoid growing the current login/dashboard controller into a god file. `public_html/index.php` becomes an HTML-capable public boundary while preserving `/health` JSON through the existing bootstrap app.

## Architecture Decisions

| Topic | Choice | Alternatives considered | Rationale |
|---|---|---|---|
| Work split | A schema/core, B admin, C public | Two larger units | Proposal risk is high; each unit stays near the 400-line review budget. |
| Admin shape | `AdminController` route table delegates catalog paths to `CatalogAdminController` | Put all methods in `AdminController`; one controller per entity | Delegation matches current front-controller simplicity without creating a god file or over-fragmenting. |
| Public pricing | Pure display resolver, no `PricingService` | Implement pricing engine now | Spec requires stored display order only; Phase 5 owns real pricing. |
| Search | Prepared `LIKE` with escaped `%`/`_` | FULLTEXT | V1 catalog scale is small and LIKE is portable in MariaDB/MySQL without indexing surprises. |
| Deletes | Archive via `archived_at` | Hard delete | Preserves future order/snapshot references and matches catalog specs. |

## Data Flow

Admin POST -> CSRF/session -> `PermissionGuard(products.manage)` -> optional `BranchScope` -> `CatalogAdminController` -> `CatalogService` transaction -> `CatalogRepository` prepared SQL -> audit rows.

Public GET -> `public_html/index.php` route match -> `PublicCatalogController` -> read-only repository query -> Spanish template -> escaped HTML.

## File Changes and Unit Split

| Unit | File | Action | Purpose | LOC |
|---|---|---|---|---:|
| A | `api/database/migrations/003_create_catalog.sql.php` | Create | 9 catalog tables, indexes, FKs, `products.manage` seed | 110 |
| A | `api/app/Catalog/CatalogRepository.php` | Create | Prepared CRUD/read queries; admin and public list/detail methods | 150 |
| A | `api/app/Catalog/CatalogService.php` | Create | Validation, transactions, audit decisions | 100 |
| A | `tests/CatalogSchemaTest.php`, `tests/CatalogServiceTest.php` | Create | scratch DB `vo_cat_test_<rand>` migration/service assertions | 70 |
| B | `api/app/Admin/AdminController.php` | Modify | Small route table/delegation for `/admin/catalogo*`, `/admin/categorias*`, `/admin/productos*`, `/admin/sucursales/{id}/catalogo` | 35 |
| B | `api/app/Admin/CatalogAdminController.php` | Create | Guarded Spanish admin GET/POST handlers | 170 |
| B | `api/app/Admin/templates/catalog_*.php` | Create | Compact Spanish list/form/branch templates using `Template::e` | 95 |
| B | `tests/AdminCatalogHttpTest.php` | Create | permission deny, CSRF reject, CRUD happy path, audit rows | 90 |
| C | `public_html/index.php` | Modify | Route `/`, `/categoria/{slug}`, `/producto/{slug}`, `/buscar?q=`, keep `/health` JSON | 55 |
| C | `api/app/Catalog/PublicCatalogController.php` | Create | Public read-only handlers and display resolver | 120 |
| C | `api/app/Catalog/templates/*.php` | Create | Spanish storefront, category, detail, search views | 90 |
| C | `public_html/assets/catalog.css` | Create | Minimal public catalog styling | 35 |
| C | `tests/PublicCatalogHttpTest.php` | Create | visibility, detail, search, category filter tests | 85 |

## Migration 003 DDL Sketch

```sql
CREATE TABLE categories (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,parent_id BIGINT UNSIGNED NULL,name VARCHAR(191) NOT NULL,slug VARCHAR(191) NOT NULL UNIQUE,description TEXT NULL,sort_order INT NOT NULL DEFAULT 0,is_active TINYINT(1) NOT NULL DEFAULT 1,archived_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_categories_parent_active (parent_id,is_active,archived_at,sort_order),CONSTRAINT fk_categories_parent FOREIGN KEY(parent_id) REFERENCES categories(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE catalog_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,category_id BIGINT UNSIGNED NULL,type ENUM('product','service') NOT NULL,name VARCHAR(191) NOT NULL,slug VARCHAR(191) NOT NULL UNIQUE,description TEXT NULL,base_price_cents BIGINT NULL,requires_variant TINYINT(1) NOT NULL DEFAULT 0,allows_pickup TINYINT(1) NOT NULL DEFAULT 1,allows_delivery TINYINT(1) NOT NULL DEFAULT 0,is_active TINYINT(1) NOT NULL DEFAULT 1,archived_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_items_category_active (category_id,is_active,archived_at),KEY idx_items_type_active (type,is_active,archived_at),CONSTRAINT fk_items_category FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE item_variants (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_id BIGINT UNSIGNED NOT NULL,name VARCHAR(191) NOT NULL,sku VARCHAR(191) NULL,price_cents BIGINT NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,sort_order INT NOT NULL DEFAULT 0,archived_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_variants_item_active (item_id,is_active,archived_at,sort_order),CONSTRAINT fk_variants_item FOREIGN KEY(item_id) REFERENCES catalog_items(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE modifier_groups (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(191) NOT NULL,is_required TINYINT(1) NOT NULL DEFAULT 0,selection ENUM('single','multi') NOT NULL DEFAULT 'single',min_select INT NOT NULL DEFAULT 0,max_select INT NULL,sort_order INT NOT NULL DEFAULT 0,is_active TINYINT(1) NOT NULL DEFAULT 1,archived_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_groups_active (is_active,archived_at,sort_order)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE modifiers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,group_id BIGINT UNSIGNED NOT NULL,name VARCHAR(191) NOT NULL,price_delta_cents BIGINT NOT NULL DEFAULT 0,is_active TINYINT(1) NOT NULL DEFAULT 1,sort_order INT NOT NULL DEFAULT 0,archived_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_modifiers_group_active (group_id,is_active,archived_at,sort_order),CONSTRAINT fk_modifiers_group FOREIGN KEY(group_id) REFERENCES modifier_groups(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE item_modifier_group (item_id BIGINT UNSIGNED NOT NULL,group_id BIGINT UNSIGNED NOT NULL,sort_order INT NOT NULL DEFAULT 0,PRIMARY KEY(item_id,group_id),KEY idx_img_group (group_id),CONSTRAINT fk_img_item FOREIGN KEY(item_id) REFERENCES catalog_items(id) ON DELETE RESTRICT,CONSTRAINT fk_img_group FOREIGN KEY(group_id) REFERENCES modifier_groups(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE branch_items (branch_id BIGINT UNSIGNED NOT NULL,item_id BIGINT UNSIGNED NOT NULL,is_available TINYINT(1) NOT NULL DEFAULT 1,price_override_cents BIGINT NULL,stock_mode ENUM('none','simple','unlimited') NOT NULL DEFAULT 'none',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(branch_id,item_id),KEY idx_branch_items_item_available (item_id,is_available),CONSTRAINT fk_branch_items_branch FOREIGN KEY(branch_id) REFERENCES branches(id) ON DELETE CASCADE,CONSTRAINT fk_branch_items_item FOREIGN KEY(item_id) REFERENCES catalog_items(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE branch_variants (branch_id BIGINT UNSIGNED NOT NULL,variant_id BIGINT UNSIGNED NOT NULL,is_available TINYINT(1) NOT NULL DEFAULT 1,price_override_cents BIGINT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(branch_id,variant_id),KEY idx_branch_variants_variant_available (variant_id,is_available),CONSTRAINT fk_branch_variants_branch FOREIGN KEY(branch_id) REFERENCES branches(id) ON DELETE CASCADE,CONSTRAINT fk_branch_variants_variant FOREIGN KEY(variant_id) REFERENCES item_variants(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE item_images (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_id BIGINT UNSIGNED NOT NULL,filename VARCHAR(255) NOT NULL,alt_text VARCHAR(255) NULL,sort_order INT NOT NULL DEFAULT 0,is_active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_images_item_active (item_id,is_active,sort_order),CONSTRAINT fk_images_item FOREIGN KEY(item_id) REFERENCES catalog_items(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO permissions(permission_key,label) SELECT 'products.manage','products.manage' WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key='products.manage');
INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON p.permission_key='products.manage' WHERE r.name='owner' AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id=r.id AND rp.permission_id=p.id);
```

## Interfaces / Contracts

Repository methods use prepared statements only: `listAdminCategories($includeArchived)`, `listAdminItems($includeArchived)`, `findItemForEdit($id)`, `findPublicItems(?$categorySlug, ?$q)`, `findPublicItemBySlug($slug)`, `activeVariants($itemId)`, `modifierGroupsForItem($itemId)`, `branchOverrides($branchId)`, `slugExists($table,$slug,$exceptId)`, `archive($table,$id)`, and save/upsert methods for each row. Public list queries join `catalog_items` to `branch_items` and `branches` with `EXISTS` requiring `branches.is_active=1` and `branch_items.is_available=1`; variant availability checks use `branch_variants` when variants exist.

`CatalogService` owns `create/update/archiveCategory`, `create/update/archiveItem`, `saveVariants`, `saveModifierGroup`, `linkModifierGroup`, `saveBranchCatalog`. Transactions wrap every multi-row save/archive and audit append. Validation: required name/slug/type; integer cents >= 0; unique slug; `requires_variant=1` active item requires at least one active non-archived variant; `min_select <= max_select` when max is present; `selection='single'` implies `max_select=1`; services force `allows_delivery=0` and branch `stock_mode='none'`.

Audit action strings: `catalog.created`, `catalog.updated`, `catalog.price_changed`, `catalog.availability_changed`, `catalog.archived`, `catalog.branch_override_updated`. Price metadata includes `old_cents`, `new_cents`, `target_type`, `target_id`; availability metadata includes `old_available`, `new_available`, branch and target IDs.

Admin URL scheme: `GET /admin/catalogo`, `GET|POST /admin/categorias`, `GET|POST /admin/productos`, `GET|POST /admin/producto/{id}`, `POST /admin/producto/{id}/archivar`, `GET|POST /admin/sucursales/{id}/catalogo`. Form shapes are flat `application/x-www-form-urlencoded` arrays: `name`, `slug`, `type`, `base_price_cents`, `requires_variant`, `variant_name[]`, `modifier_group_id[]`, `branch_available[item_id]`, `branch_price[item_id]`. Spanish errors include `El nombre es obligatorio.`, `El slug ya existe.`, `El producto requiere al menos una variante activa.`, `Un servicio no puede habilitar envío.`, `La selección mínima no puede superar la máxima.`

Public display resolver is pure and display-only: branch variant override -> variant price -> branch item override -> item base price; returns nullable cents for templates, never totals/discounts/delivery. Search escapes LIKE wildcards with `ESCAPE '\\'` and binds `%term%`.

## Testing Strategy

| Layer | What to Test | Approach |
|---|---|---|
| Integration | Migration 001+002+003, tables/FKs/indexes/permission seed, forbidden tables absent | scratch DB `vo_cat_test_<rand>`, `MigrationRunner`, SQL assertions |
| Unit/service | validation, transactions, archive filters, price/availability audit metadata | direct `CatalogService` with fixtures |
| Admin HTTP | deny without `products.manage`, CSRF 419 no state change, CRUD, branch scope 403, audit rows | built-in server harness like `AdminHttpTest` |
| Public HTTP | archived/unavailable hidden, category/search filters, detail variants/modifiers escaped | unauthenticated built-in server tests |

## Threat Matrix

| Boundary | Applicability | Design response | Planned RED tests |
|---|---|---|---|
| Documentation-like paths | N/A: no executable-file classification | None | None |
| Git repository selection | N/A: no VCS automation | None | None |
| Commit state | N/A: no commit automation | None | None |
| Push state | N/A: no push automation | None | None |
| PR commands | N/A: no PR automation | None | None |
| HTTP routing | Applicable: new public/admin routes | Exact method/path allowlist; POSTs require CSRF/auth/permission; `/health` remains JSON | GET/POST wrong method 404/405 expectations, CSRF denial, public read-only no state |

## Migration / Rollout

Apply migration 003 after 001/002. Rollback is manual/guarded: disable routes, archive or verify empty Phase 4 tables, then drop catalog tables in FK order and remove `products.manage` owner links only if no longer needed.

## Explicit Non-Design / Deferred

Phase 5+: `PricingService`, promotions, cart, checkout, order snapshots, inventory quantities, reservations, stock movements, uploads and MIME validation, customer accounts, payments, delivery workflows, service scheduling, frontend JS price authority.

## Open Questions

None blocking; `products.manage`, public root catalog, and `none|simple|unlimited` stock mode are accepted from proposal/spec.
