# Testing Bootstrap

## Requirements

### Requirement: Single command harness
Harness MUST run as `php tools/run-tests.php` or portable PHP, without Composer/PHPUnit.
#### Scenario: Composer-free run
- GIVEN PHP available
- WHEN test command runs
- THEN tests execute without vendors

### Requirement: Discovery and exits
Harness MUST discover `tests/`, return zero for green, non-zero for red.
#### Scenario: Red run
- GIVEN discovered failure
- WHEN the harness finishes
- THEN exit code is non-zero

#### Scenario: Green run
- GIVEN all tests pass
- WHEN the harness finishes
- THEN exit code is zero

### Requirement: Smoke coverage
Smoke tests MUST cover harness execution, Money, state transitions, and idempotency.
#### Scenario: Smoke tests found
- GIVEN foundation tests
- WHEN discovery runs
- THEN required smoke areas are included

### Requirement: Testing scope guard
This change MUST NOT test auth, catalog, pricing engine, orders, payments, or delivery logic.
#### Scenario: Deferred tests absent
- GIVEN foundation tests list
- WHEN scope is reviewed
- THEN only runtime, primitives, harness are verified
