# Domain Primitives

## Requirements

### Requirement: Money integer cents
Money MUST use integer cents, reject floats, and support add/subtract/percentage/display.
#### Scenario: Integer arithmetic
- GIVEN same-currency values
- WHEN add/subtract runs
- THEN result is integer cents

### Requirement: Banker-safe rounding
Percentages MUST use deterministic half-even integer rounding and MUST NOT use floats.
#### Scenario: Half-even tie
- GIVEN halfway-cent result
- WHEN percentage applies
- THEN the nearest even cent is selected

### Requirement: State transitions
State helper MUST allow declared transitions, reject invalid ones, and SHOULD expose history hook.
#### Scenario: Invalid transition
- GIVEN undeclared transition
- WHEN transition is attempted
- THEN exception is raised

### Requirement: Idempotency keys
Idempotency helper MUST enforce unique keys and return first outcome marker on repeats.
#### Scenario: Duplicate attempt
- GIVEN key has outcome marker
- WHEN the key is attempted again
- THEN original marker is returned

### Requirement: Primitive scope guard
Primitives MUST NOT encode auth, catalog, pricing, order, payment, inventory, or delivery rules.
#### Scenario: Workflow decision
- GIVEN workflow eligibility request
- WHEN a primitive is used
- THEN decision is out of scope
