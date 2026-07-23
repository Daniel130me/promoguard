# Implementation Status

## Current phase

Phase 6: reservations and lifecycle — implementation complete; isolated runtime gate pending.

Identity, deterministic eligibility, Classic/Store API checkout enforcement,
deduplicated denial logging, and the atomic reservation persistence foundation
are implemented. WooCommerce order-status integration consumes or releases
pending usages atomically, Action Scheduler releases expired reservations in
bounded batches, and admin/REST-created orders receive final validation before
first consumption. Phase 7 starts after the isolated Phase 6 runtime gate runs.

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

### Identity, checkout, and reservation foundations

- [x] User-first customer identity with privacy-safe hashed guest email
- [x] Deterministic campaign eligibility and request-local decision caching
- [x] Classic and Store API final validation for assigned native coupons
- [x] Request-deduplicated, customer-attributed denial persistence
- [x] Authoritative customer handoff without a repeated identity lookup
- [x] Validated reservation request/result contracts and bounded contention retry
- [x] Atomic state creation, row locking, expiry release, limit check, and pending usage persistence
- [x] Classic and Store API processed-order reservation hooks before payment
- [x] Idempotent order-status consumption and configured failure/cancellation release
- [x] Action Scheduler-backed bounded expiration cleanup
- [x] Shared checkout and admin/REST final order validation and reservation
- [x] Snapshot-backed lifecycle transitions after coupon detachment or deletion

### Campaign administration

- [x] Capability-protected PromoGuard administration menu and page
- [x] Page-scoped, versioned JavaScript and CSS asset loading
- [x] Bounded campaign list with status filtering and pagination
- [x] Accessible loading, error, empty, success, and form-validation states
- [x] Draft campaign creation with safe slug generation
- [x] Campaign editing, scheduling, activation, pausing, archival, and safe Draft deletion
- [x] Lazy, bounded assigned-coupon loading with archived read-only presentation
- [x] Keyboard-accessible native coupon search, attach, explicit reassignment, and detach
- [x] Responsive table-to-card layout for narrow WordPress admin viewports
- [x] Pretty and plain-permalink REST URL compatibility

## Verification

- composer check: passed
  - PHP syntax: passed
  - WordPress Coding Standards: passed
  - PHPStan: passed
  - PHPUnit: 134 tests, 358 assertions
- npm run check: passed
  - JavaScript syntax: passed
  - Generated asset version: da23eb881114
- Docker daemon: available, server 29.6.1
- Isolated WordPress/WooCommerce activation and REST smoke test: passed
  - WordPress 7.0.2, WooCommerce 10.9.4, and PHP 8.3
  - Seven PromoGuard tables present and using InnoDB
  - Versioned migration confirmed idempotent
  - Capability recovery, anonymous denial, campaign/coupon creation, atomic
    assignment, legacy empty-settings reads, explicit reassignment, archive
    immutability, safe deletion, and native coupon preservation passed
  - The existing XAMPP WordPress database was not used
- Isolated Phase 6 lifecycle smoke scenario: locally validated, runtime pending
  - Covers admin/REST consumption and denial, idempotent reservations/status
    callbacks, failure release, expiry cleanup, scheduler registration, and
    detached-assignment snapshot consumption
  - 2026-07-23 wp-env start was blocked by DNS resolution for api.wordpress.org
  - No XAMPP database was used as a fallback
- Isolated browser workflow: passed
  - Campaign create/edit/schedule/pause/archive/delete flows verified through the
    live WordPress administration page
  - Lazy assignment listing, Enter-key coupon search, attach metadata, detach,
    coupon preservation, and archived read-only controls verified
  - Plain-permalink REST requests passed and a fresh browser console had 0 errors
  - Responsive campaign and assignment layouts verified at 375 x 812

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
- Checkout resolves identity once per decision and carries only the internal customer ID into denial and reservation boundaries.
- Reservation persistence locks the unique campaign/customer state before reconciling expiry, checking limits, and changing counters; it performs no remote work.
- Reservation retries are capped at three and apply only to deadlocks and lock-wait timeouts; other failures stop immediately.
- Order lifecycle policy, WooCommerce hook adaptation, and transactional
  persistence are separate dependencies. Transitions use indexed
  order/campaign lookups, preserve state-first locking, and update counters only
  when a pending usage changes.
- Checkout and non-checkout orders share one reservation coordinator. One
  order/campaign-indexed lookup skips active usages, while a fresh policy read is
  reserved for atomic limit races so stale request cache entries cannot approve.
- Expiration selects at most 50 rows through the status/expiry index and handles
  each distinct campaign/customer state in its own short state-first transaction.
- Pending lifecycle contexts come from PromoGuard usage snapshots joined by indexed
  campaign ID, so coupon deletion or assignment detachment cannot strand usage.
- The administration page enqueues assets only on its exact hook suffix. List
  requests are capped at 20 records per page, rows are built in one document
  fragment, and API content is inserted with textContent.
- Opening a campaign reuses the current list snapshot and lazily issues one
  indexed assignment query capped at 100. Coupon search runs only on explicit
  user action, returns at most 20 results, and disables found unavailable items.
- Assignment requests ignore late responses after an editor switch or close.
  Historical empty settings encoded as `[]` remain readable, while malformed
  non-empty arrays are still rejected.
- Native WordPress controls, visible labels, keyboard focus management, live
  regions, text status labels, and reduced-motion handling keep the UI aligned
  with WordPress and accessible without adding a frontend framework.

## Compatibility baseline

| Component | Supported | CI/default target |
|---|---:|---:|
| WordPress | 6.9-7.0 | 7.0.2 |
| WooCommerce | 10.8-10.9 | 10.9.4 |
| PHP | 8.1-8.4 | 8.3 |

The pinned default target is runtime verified. The broader declared range remains
provisional until its full isolated compatibility matrix runs successfully.

## Not implemented

Refunds, reconciliation, historical indexing, full administration, analytics,
privacy tools, and release hardening belong to the remaining phases.
