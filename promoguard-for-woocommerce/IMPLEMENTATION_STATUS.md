# Implementation Status

## Current phase

Phase 1: schema, installation, and lifecycle foundations.

Implementation is complete. The isolated activation smoke test remains pending
because the local Docker daemon is unavailable.

## Completed

- [x] Plugin directory boundary and metadata
- [x] Composer PSR-4 autoloading and PHP quality tooling
- [x] npm build and lint tooling
- [x] Safe bootstrap when WooCommerce is unavailable or unsupported
- [x] HPOS and Cart/Checkout Blocks compatibility declarations
- [x] Isolated WordPress/WooCommerce environment configuration
- [x] Initial CI workflow and unit-test foundation
- [x] Seven plugin-owned database tables and indexed schema definitions
- [x] Versioned activation and administrator-only migration runner
- [x] InnoDB storage verification and fail-closed health metadata
- [x] Stable installation UUID, HMAC key, and non-autoloaded plugin options
- [x] Administrator and Shop Manager capability assignments
- [x] Explicit opt-in uninstall cleanup scoped to PromoGuard-owned data

## Verification

- `composer check`: passed (syntax, PHPCS, PHPStan, 20 tests/79 assertions)
- `npm ci --dry-run`: passed against the dependency-free lockfile
- `npm run check`: passed; generated asset version `1fa94770f27a`
- Isolated WordPress activation: pending; Docker CLI cannot reach a running daemon

The existing XAMPP WordPress database was not used for automated testing.

## Maintainability and performance review

- Bootstrap responsibilities are split between coordination and a unit-testable
  requirement policy; no business logic is placed in the plugin entry file.
- PSR-4 naming, centralized compatibility constants, and focused methods avoid
  magic values and make later services straightforward to add.
- Comments explain the non-obvious compatibility boundary and deterministic
  asset cache version; no empty domain interfaces or placeholder classes exist.
- Phase 1 adds no storefront queries. Installation performs one bounded metadata
  query for seven known tables after `dbDelta`; runtime upgrade checks are
  administrator-only.
- Tables use targeted compound indexes and omit database foreign keys so WordPress
  migrations remain portable. No historical order scans or unbounded work were
  introduced.
- Frontend dependencies are deferred until an administration UI exists. The
  current dependency-free asset build keeps installs and CI fast.
- [x] Architecture and testing documentation

## Compatibility baseline

| Component | Supported | CI/default target |
|---|---:|---:|
| WordPress | 6.9-7.0 | 7.0.2 |
| WooCommerce | 10.8-10.9 | 10.9.4 |
| PHP | 8.1-8.4 | 8.3 |

The compatibility range is intentionally narrow until the integration matrix
has run successfully. A version in this table is not considered supported only
because an allowed-failure CI job can start with it.

## Not implemented

Campaigns, coupon assignments, identity, checkout enforcement, reservations,
analytics, and administration belong to later phases.
