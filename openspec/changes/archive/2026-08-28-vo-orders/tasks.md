# Tasks: Orders, Customers, and Stock Reservation

## Review Workload Forecast

| Field | Value |
|---|---|
| Estimated changed lines | Unit A ~360; Unit B ~390; Unit C ~360; Unit D ~380 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | A schema+state map → B OrderService creation → C confirm+idempotency+public page → D stock/usage/admin read-only |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Focused test command | Runtime harness | Rollback boundary |
|---|---|---|---|---|
| A | Migration 006 + order state map | `D:\xampp\php\php.exe tools/run-tests.php --filter=OrdersSchemaTest` | Scratch DB `vo_orders7_test_*` migration run | Remove migration/state map/tests only |
| B | Transactional `OrderService` creation snapshots | `D:\xampp\php\php.exe tools/run-tests.php --filter=OrderServiceTest` | Service test with seeded cart/pricing | Remove `api/app/Orders` service/repo changes |
| C | Cart confirm API, DB idempotency, public page | `D:\xampp\php\php.exe tools/run-tests.php --filter=CartOrderConfirmTest` | Built-in PHP server, POST `/api/cart/confirm`, GET `/pedido/{token}` | Unwire confirm/public routes/controllers |
| D | Stock movements, promotion usage, admin read-only | `D:\xampp\php\php.exe tools/run-tests.php --filter=OrderAdminStockTest` | Scratch DB + admin HTTP session | Remove stock/admin usage wiring |

## Unit A: Schema and State Foundation

- [x] A.1 Create `tests/OrdersSchemaTest.php` for R orders R4-R5, inventory R1/R4, customers R2; assert migration list includes `006 create_orders`.
- [x] A.2 Add `api/database/migrations/006_create_orders.sql.php` with all order/customer/stock/idempotency tables and `branch_items` stock columns.
- [x] A.3 Create `api/app/Orders/OrderStateMap.php` using `VO\Domain\StateMachine` with all 9 states and legal transitions.
- [x] A.4 Update `tests/BaselineSchemaTest.php` and `tests/CatalogSchemaTest.php` table/deferred lists: remove `orders`, `customers`, `stock_movements`; confirm `carts` remains non-deferred.
- [x] A.5 Verify: run Unit A focused command, then record tasks verification placeholder.

## Unit B: OrderService Creation

- [x] B.1 Write `tests/OrderCreationTest.php` for orders R1-R4/R6/R9 and pricing-engine modified scenarios.
- [x] B.2 Create `api/app/Orders/OrderRepository.php` and `OrderService.php` with transaction-owned creation and snapshots.
- [x] B.3 Implement counter locking, price_changed rollback, cart row lock, cart item clearing, customer snapshot/link, and idempotent response write hook.
- [x] B.4 Verify: run Unit B focused command, then record tasks verification placeholder.

## Unit C: API Idempotency and Public Confirmation

- [x] C.1 Write `tests/CartOrderConfirmTest.php` for cart-api modified R7 and orders R2/R3/R7.
- [x] C.2 Create `api/app/Domain/DbIdempotencyStore.php` and wire request-hash replay semantics to `OrderService`.
- [x] C.3 Modify `api/app/Cart/CartApiController.php`, `CartService.php`, `api/bootstrap/app.php`, and `public_html/index.php` to add `POST /api/cart/confirm` while preserving preview.
- [x] C.4 Create public order controller/template for `GET /pedido/{public_token}` using snapshot data only.
- [x] C.5 Verify: run Unit C focused command, then record tasks verification placeholder.

## Unit D: Stock, Usage, and Admin Read-only

- [x] D.1 Write `tests/OrderAdminStockTest.php` for inventory R2-R5, pricing usage, and orders R8.
- [x] D.2 Create `api/app/Inventory/StockService.php` with locked check/reserve/release and movement rows.
- [x] D.3 Persist `order_discounts`, `promotion_usage`, promotion/coupon `times_used` increments once per confirmed order.
- [x] D.4 Create admin orders repository/controller/templates and delegate `/admin/pedidos` + `/admin/pedidos/{id}` from `AdminController` under `products.manage` and branch scope.
- [x] D.5 Verify: run Unit D focused command, then `D:\xampp\php\php.exe tools/run-tests.php`; record verification placeholder.
