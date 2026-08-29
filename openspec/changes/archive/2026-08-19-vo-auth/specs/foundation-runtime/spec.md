# Delta for Foundation Runtime

## ADDED Requirements

### Requirement: Method-Aware Routing

Runtime routing MUST support method-aware routes for at least GET and POST, MUST dispatch by both path and method, and MUST return 405 when a known path is requested with an unsupported method.

#### Scenario: POST route dispatch
- GIVEN a POST route is registered
- WHEN a matching POST request arrives
- THEN the POST handler is executed.

#### Scenario: Wrong method rejected
- GIVEN a path exists for GET only
- WHEN the same path is requested with POST
- THEN the response status is 405.

### Requirement: Form POST Body Parsing

The HTTP request boundary MUST provide a helper for `application/x-www-form-urlencoded` POST body values while preserving existing JSON response behavior.

#### Scenario: Form body available
- GIVEN a form-encoded POST request
- WHEN the request is inspected by a controller
- THEN submitted fields are available through the request helper.

#### Scenario: JSON boundary unchanged
- GIVEN an existing JSON runtime route
- WHEN the route returns a JSON response
- THEN existing JSON response behavior and headers remain unchanged.
