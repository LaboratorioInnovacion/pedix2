# Tasks: vo-reports — Reports & Dashboard V1 (Phase 12)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~780 total (≈370 Unit A / ≈410 Unit B incl. tests) |
| 400-line budget risk | Medium |
| Chained PRs recommended | Yes |
| Suggested split | Unit A (repository+service+repo test) → Unit B (controller+template+routing+http test) |
| Delivery strategy | ask-on-risk |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: Medium

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| A | `ReportsRepository` + `ReportsService` (validation, presets, canonical cents) + `ReportsRepositoryTest` | PR 1 | `D:\xampp\php\php.exe tools\run-tests.php --filter ReportsRepositoryTest` | Scratch DB `vo_rep12_test_<rand>` (real MariaDB, MigrationRunner + InstallerSeeder), serial | Delete `api/app/Reports/*` + test; nothing else touched |
| B | `ReportsAdminController` (page + CSV), `reportes.php`, dashboard metrics + nav card, routing, `ReportsAdminHttpTest` | PR 2 | `D:\xampp\php\php.exe tools\run-tests.php --filter ReportsAdminHttpTest` | `InstallerTestServer` HTTP harness (AdminOrdersHttpTest pattern), serial | Revert routing + dashboard template lines; delete Unit B files; Unit A untouched |

## Phase 1: Unit A — Repository & Service (refs R2–R5, R7, R8 queries)

- [x] 1.1 Create `api/app/Reports/ReportsRepository.php`: `summary(array $filters, int $userId)` — single scoped query `JOIN user_branches ub ON ub.branch_id=o.branch_id AND ub.user_id=?`, date bounds `created_at >= ? AND < ? + INTERVAL 1 DAY`, default status set per design pin A1, `EXISTS` subqueries for producto/categoría, allowlist params for sucursal/medio/modalidad/estado, `GROUP BY DATE(o.created_at)` returning per-day + aggregate metric expressions in integer cents (`COALESCE(SUM(...),0)`).
- [x] 1.2 Add `dashboard(int $userId)` to the repository: ventas/pedidos de hoy (`DATE(created_at)=CURDATE()` + A1 set + scope), status counts (`pending`,`in_progress`,`ready`,`cancelled`,`rejected`), delivery buckets (`deliveries.state IN ('pending','assigned')` / `='picked_up'`, `uq_deliveries_order` no fan-out), stock bajo (`branch_items` `stock_mode='simple'` AND `stock_quantity<=0` scoped).
- [x] 1.3 Create `api/app/Reports/ReportsService.php`: `parseFilters(array $get, int $userId)` — presets hoy/7d/30d/custom, default 30d, Y-m-d validation, from<=to, span ≤ 366 days, allowlists (`pickup|delivery`, 9 statuses, branch/product/category positive ints, payment method string ≤64), invalid values ignored; `neta()` = bruta − descuentos + delivery cobrado − remuneración (int cents); `totalsFor(rows)` rollup; `branchesFor(userId)` scoped select.
- [x] 1.4 Create `tests/ReportsRepositoryTest.php` (scratch `vo_rep12_test_<rand>`, serial): seed 2 branches × multiple days × statuses with discounts + delivery fees + payouts (incl. one order with 2 same-product lines); assert exact cents per metric (R4 identity), daily rows (R5), default-set exclusion incl. `change_proposed` in (R4), estado override (R3), product/category EXISTS no-double-count (R3), every filter effect (R2/R3), scope isolation + out-of-scope branch zero rows (R7), empty range. Verify Unit A focused command: 8 tests, 0 failures (GREEN).

## Phase 2: Unit B — Controller, Template, Dashboard, Routing (refs R1, R2–R8, admin-shell delta)

- [x] 2.1 Create `api/app/Admin/ReportsAdminController.php` (`handle($method,$path)`): auth redirect, `PermissionGuard('reports.view')` else 403 + `authz.denied` audit (R1), GET `/admin/reportes` renders via service+repository, GET `/admin/reportes/csv` streams CSV with same filters (R6).
- [x] 2.2 CSV response details (R6): `Content-Type: text/csv; charset=utf-8`, BOM `\xEF\xBB\xBF`, semicolon separator, Spanish header row `Fecha;Pedidos;Venta bruta;Descuentos;Delivery cobrado;Remuneración delivery;Venta neta operativa`, daily rows + `TOTAL` row, `Content-Disposition: attachment; filename="ventas_YYYYMMDD-YYYYMMDD.csv"`.
- [x] 2.3 Create `api/app/Admin/templates/reportes.php` (Spanish, `Template::e`, layout): filter form (preset select, from/to dates, scoped branch select, producto/categoría, medio de pago, modalidad, estado — R2/R3/R7), 6 metric cards, daily table, CSV link carrying current query string, empty-state message (R5).
- [x] 2.4 Modify `api/app/Admin/templates/dashboard.php`: 11-metric block per design pins (R8) + "Reportes" card `href=/admin/reportes` replacing "Módulo pendiente" (admin-shell ADDED requirement).
- [x] 2.5 Modify `api/app/Admin/AdminController.php`: `isReportsPath()` + dispatch to `ReportsAdminController` (admin-shell scope-guard scenario).
- [x] 2.6 Create `tests/ReportsAdminHttpTest.php` (serial harness): 200 report with metrics/table for authorized owner; 403 + `authz.denied` for page AND csv after revoking `reports.view` (R1/R6); login redirect; filters reflected via query string (R2/R3); CSV body = BOM + headers + rows + TOTAL + correct filename (R6); dashboard shows the 11 seeded metric values incl. delivery buckets and stock bajo (R8); "Reportes" card links `/admin/reportes`; scoped user sees only own-branch numbers (R7). Verify Unit B focused command.

## Phase 3: Verification & Cleanup

- [ ] 3.1 Run both focused test files plus neighboring suites (`AdminOrdersHttpTest`, `NotificationsAdminHttpTest`) serially; sweep stray `vo_*_test_*` scratch DBs (keep `vo_test`).
- [ ] 3.2 Confirm no migration file added; `git status` shows only the 8 design-listed files (NO-COMMIT mode — no git operations).
