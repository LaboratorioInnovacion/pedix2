# Delta for Catalog Public

## MODIFIED Requirements

### Requirement R8: Public Catalog Routes

The public storefront MUST expose `GET /`, `GET /categoria/{slug}`, `GET /producto/{slug}`, `GET /buscar?q=`, `GET /carrito`, and `GET /checkout`. These routes MUST render Spanish UI and MUST escape all dynamic output according to context. Cart and checkout pages MAY load live JSON data from the cart API, but frontend JavaScript MUST NOT compute final prices.
(Previously: public routes were read-only catalog, category, product, and search pages only.)

#### Scenario: Public routes render safely
- GIVEN catalog data exists
- WHEN a visitor opens a catalog, category, product, search, cart, or checkout page
- THEN a Spanish HTML page renders escaped dynamic content.

#### Scenario: Cart and checkout pages use backend preview
- GIVEN a visitor opens `/carrito` or `/checkout`
- WHEN the page needs cart totals
- THEN it retrieves backend cart/preview JSON
- AND it does not calculate final totals in JavaScript.

### Requirement R9: Public Catalog Scope Guard

Public catalog pages MUST NOT create orders, promotions, pricing-engine side effects, stock movements, reservations, uploads, customer accounts, payments, or delivery workflows. Phase 6 pages MAY create and mutate guest cart state only through the cart API.
(Previously: public catalog created no commerce state at all.)

#### Scenario: Storefront cart state only
- GIVEN a visitor browses public storefront pages
- WHEN they use Phase 6 cart or checkout interactions
- THEN only guest cart rows may be created or mutated
- AND no order/payment/stock/delivery state is created.
