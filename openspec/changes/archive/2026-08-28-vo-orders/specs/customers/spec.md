# Customers Specification

## Purpose

Defines guest checkout customer capture and optional account linking for orders.

## Requirements

### Requirement R1: Guest checkout contact
The system MUST allow checkout without login and MUST snapshot customer name, email, phone, and note on the order.

#### Scenario: Guest contact snapshot
- GIVEN checkout contains customer contact fields
- WHEN the order is confirmed
- THEN the order stores those contact values even if no customer account exists.

### Requirement R2: Unique customer email per business
The system MUST enforce customer email uniqueness inside a business while allowing the same email in different businesses.

#### Scenario: Duplicate email in same business
- GIVEN a customer email already exists for a business
- WHEN another checkout links or creates that email in the same business
- THEN the existing customer is reused or duplicate creation is rejected.

### Requirement R3: Optional account creation/link
The system MAY create or link a customer account during checkout when valid account input is supplied; orders MUST still preserve snapshots independent of live customer changes.

#### Scenario: Linked customer later changes profile
- GIVEN an order links to a customer account
- WHEN the customer profile is edited later
- THEN the order contact snapshot remains unchanged.
