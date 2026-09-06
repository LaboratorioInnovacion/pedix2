# Delta for Foundation Runtime

## ADDED Requirements

### Requirement: Generated Config Overlay

Runtime config loading MUST merge `api/config/installed.php` over static defaults when the private overlay exists, while preserving private path boundaries and non-secret failure messages.

#### Scenario: Overlay present
- GIVEN static defaults and a private installed overlay
- WHEN `Config` loads runtime configuration
- THEN overlay values take precedence
- AND the overlay path remains outside `public_html`.

#### Scenario: Overlay missing
- GIVEN no private installed overlay exists
- WHEN installer status is evaluated
- THEN the system treats installation as not installed
- AND normal private path secrecy remains unchanged.

### Requirement: Runtime Scope Guard

This change MUST NOT add runtime login/auth endpoints, admin panel behavior, catalog, orders, payments, delivery, customer accounts, updater, or backups to foundation runtime.

#### Scenario: Runtime remains minimal
- GIVEN the config overlay behavior exists
- WHEN runtime routes are reviewed
- THEN deferred product features are still absent.
