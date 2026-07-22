# Implementation Status

## Current phase

Phase 2: campaigns and native WooCommerce coupons.

The Phase 2 implementation and its isolated WordPress/WooCommerce runtime gate
are complete on the pinned default target.

## Completed

### Foundations

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

### Campaigns and native coupons

- [x] Immutable campaign aggregate and supported configuration policy
- [x] Schedule-derived campaign status and archived read-only behavior
- [x] Strict campaign input builder with partial-update merging
- [x] Bounded, indexed campaign persistence and safe Draft deletion
- [x] Native WC_Coupon adapter using WooCommerce public CRUD APIs
- [x] Bounded coupon search and basic native coupon creation
- [x] Immutable campaign-promotion assignment snapshots
- [x] Atomic attachment and explicit reassignment by source identity
- [x] Detachment that preserves coupons, usages, and decision snapshots
- [x] Transport-neutral campaign application service
- [x] Capability-protected promoguard/v1 REST endpoints
- [x] Closed request schemas for supported campaign configuration
- [x] Structured API resources and unsafe-deletion conflict responses

## Verification

- composer check: passed
  - PHP syntax: passed
  - WordPress Coding Standards: passed
  - PHPStan: passed
  - PHPUnit: 68 tests, 181 assertions
- npm run check: passed
  - JavaScript syntax: passed
  - Generated asset version: 1fa94770f27a
- Docker daemon: available, server 29.6.1
- Isolated WordPress/WooCommerce activation and REST smoke test: passed
  - WordPress 7.0.2, WooCommerce 10.9.4, and PHP 8.3
  - Seven PromoGuard tables present and using InnoDB
  - Versioned migration confirmed idempotent
  - Capability recovery, anonymous denial, campaign/coupon creation, atomic
    assignment, explicit reassignment, archive immutability, safe deletion, and
    native coupon preservation passed
  - The existing XAMPP WordPress database was not used

## Maintainability and performance review

- REST controllers contain transport concerns only. Campaign construction,
  persistence orchestration, response mapping, and route schemas are separate,
  focused dependencies.
- Every administration endpoint requires manage_promoguard. Mutation schemas
  accept only supported campaign configuration keys, sanitize administrator text,
  and cap list requests at 100 records.
- Campaign creation returns the inserted representation without a read-after-write.
  Updates load the campaign once and pass that snapshot to the optimistic update.
- Campaign and assignment reads select explicit columns and use primary, unique,
  or campaign indexes. Coupon search is capped and uses WooCommerce/WordPress
  public APIs.
- Assignment changes use a short InnoDB transaction with campaign/source locking.
  No remote work occurs inside the transaction.
- Permanent deletion is one conditional, primary-key-scoped query and is limited
  to unused Draft campaigns. Coupons, orders, usages, and decision snapshots are
  never deleted by campaign administration.
- Comments explain non-obvious locking, immutable archival behavior, and snapshot
  preservation. Constants centralize limits, statuses, formats, capabilities, and
  the REST namespace.
- Runtime capability synchronization checks in-memory role state and writes only
  missing grants, covering late WooCommerce role creation without steady-state
  option updates.
- No storefront or checkout queries are introduced in Phase 2.

## Compatibility baseline

| Component | Supported | CI/default target |
|---|---:|---:|
| WordPress | 6.9-7.0 | 7.0.2 |
| WooCommerce | 10.8-10.9 | 10.9.4 |
| PHP | 8.1-8.4 | 8.3 |

The pinned default target is runtime verified. The broader declared range remains
provisional until its full isolated compatibility matrix runs successfully.

## Not implemented

The minimal campaign administration UI is Phase 3. Identity, eligibility,
checkout enforcement, reservations, order lifecycle handling, refunds,
historical indexing, analytics, privacy tools, and release hardening belong to
later phases.
