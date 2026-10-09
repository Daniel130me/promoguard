# Implementation Status

## Current phase

Phase 12: release quality and final acceptance - implementation and local gates complete.

PromoGuard now includes the complete private-beta scope: campaign administration,
identity and eligibility enforcement, checkout reservations and lifecycle,
refund restoration and reconciliation, historical indexing, operational
administration, currency-safe analytics, WordPress privacy tooling, bounded
retention, adversarial security coverage, and reproducible release packaging.

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

### Refunds and reconciliation

- [x] Immutable refund-policy snapshots on consumed usages
- [x] Cumulative full-refund detection through WooCommerce CRUD APIs
- [x] Idempotent consumed-to-restored transitions and order outcome markers
- [x] Atomic restoration of consumed counters and discount totals
- [x] Bounded aggregate reconciliation from the authoritative usage ledger
- [x] Action Scheduler-backed continuation for reconciliation batches

### Historical indexing

- [x] Persisted indexing job lifecycle with progress, counters, and bounded errors
- [x] Full-history and targeted-order indexing with configurable bounded batches
- [x] Pause, resume, retry-failed, and restart controls
- [x] Action Scheduler orchestration with superseded-action protection
- [x] WP-CLI start, status, pause, resume, retry, and restart commands
- [x] WooCommerce CRUD order loading compatible with HPOS and legacy storage
- [x] Identity-safe, idempotent usage imports and full-refund restoration policy
- [x] Bulk assignment resolution and short state-first import transactions

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

### Full administration and analytics

- [x] Capability-protected operational dashboard, usage history, decisions, settings, and tools
- [x] Lazy, responsive, keyboard-accessible administration navigation and report views
- [x] Bounded UTC analytics filters with a 366-day maximum window
- [x] Redemptions, unique customers, campaign/global order counts, restorations, and denial reasons
- [x] Currency-separated discount, revenue, and average metrics without conversion
- [x] HPOS-compatible, 100-order public batch loading with deleted-order tolerance
- [x] Activity-ranked campaign breakdowns capped at 50 campaigns per page
- [x] Versioned five-minute caches shared across summaries and campaign pages
- [x] Mutation-driven invalidation for lifecycle, refunds, and historical imports
- [x] Desktop and mobile analytics tables with live loading and result states

### Privacy, security, and hardening

- [x] WordPress personal-data exporter with bounded campaign, usage, and decision pages
- [x] Atomic identifier erasure and WordPress-user unlinking
- [x] Anonymous usage and aggregate preservation for accounting and lifetime enforcement
- [x] Suggested WordPress Privacy Policy Guide content
- [x] Indexed 365-day eligibility-decision retention capped at 250 rows per daily run
- [x] Action Scheduler retention registration and idempotent scheduling safeguards
- [x] Fixed public REST and WP-CLI errors without raw exception disclosure
- [x] HMAC key and identifier-hash redaction from API and privacy output
- [x] Negative authorization, stored-markup, error-redaction, and injection-style runtime tests

### Release quality and acceptance

- [x] Reproducible production ZIP with an authoritative Composer autoloader
- [x] Required/forbidden artifact-tree verification and SHA-256 output
- [x] Composer dependency metadata and readable administration source disclosure
- [x] WordPress Plugin Check validation against the installed packaged artifact
- [x] Clean-database artifact activation, migration, runtime, and lifecycle acceptance
- [x] Release checklist, changelog, WordPress readme, and compatibility handoff

### Standalone signup bonus and store credit

- [x] Currency-scoped store-credit accounts and append-only transaction ledger
- [x] Atomic, idempotent grant persistence with indexed source references
- [x] Customer registration default and Dokan vendor approval default

- [x] Closed administrator configuration for audience timing and amounts
- [x] Separate signup campaign domain, option, API object, and settings fieldset
- [x] Coupon campaign services, assignments, and checkout eligibility remain unchanged
## Verification

- composer check: passed
  - PHP syntax: passed
  - WordPress Coding Standards: passed
  - PHPStan: passed
  - PHPUnit: 182 tests, 500 assertions
- npm run check: passed
  - JavaScript syntax: passed
  - Generated asset version: d8d957d228d2
- npm run package: passed
  - Production dependencies only; Composer classmap authoritative
  - Plugin Check: 0 errors; reviewed direct plugin-table query warnings
  - Packaged artifact activated on a clean disposable database
  - Runtime and lifecycle smoke suites passed against the artifact
  - Repository-root compatibility workflow covers PHP 8.1-8.4 and four
    HPOS/legacy runtime corners
  - Runtime jobs assert the active WordPress, WooCommerce, PHP, and storage targets
- Docker daemon: available, server 29.6.1
- Isolated WordPress/WooCommerce activation and REST smoke test: passed
  - WordPress 7.0.2, WooCommerce 10.9.4, and PHP 8.3
  - Seven PromoGuard tables present and using InnoDB
  - Versioned migration confirmed idempotent
  - Capability recovery, anonymous denial, campaign/coupon creation, atomic
    assignment, legacy empty-settings reads, explicit reassignment, archive
    immutability, safe deletion, and native coupon preservation passed
  - Real EUR/USD WooCommerce orders reconciled revenue and averages through the public batch factory
  - Summary and paginated campaign caches remained stable before invalidation and refreshed afterward
  - Deleted historical order IDs were skipped without failing the report
  - Personal-data export omitted hashes and keys; erasure unlinked identifiers while retaining usage accounting
  - The 365-day retention worker deleted only the expired diagnostic fixture and preserved current decisions
  - Settings read/write authorization, stored-markup sanitization, generic error redaction, and injection-style filters passed
  - The existing XAMPP WordPress database was not used
- Isolated Phase 7 lifecycle smoke scenario: passed
  - Covers admin/REST consumption and denial, idempotent reservations/status
    callbacks, failure release, expiry cleanup, and detached-assignment snapshot
    consumption
  - Covers partial-to-cumulative-full refund restoration exactly once, aggregate
    repair from usage rows, and expiration/reconciliation scheduler registration
  - No XAMPP database was used
- Isolated Phase 8 historical indexing smoke scenario: passed
  - Lifecycle start, pause, resume, retry, restart, and completed-job handling passed
  - Targeted public WP-CLI indexing completed idempotently
  - A full historical scan completed in three batches: 25 processed, 3 imported,
    22 skipped, 0 failed
  - Superseded Action Scheduler callbacks safely exited without changing the
    current job
  - No XAMPP database was used
- Isolated browser workflow: passed
  - Campaign create/edit/schedule/pause/archive/delete flows verified through the
    live WordPress administration page
  - Lazy assignment listing, Enter-key coupon search, attach metadata, detach,
    coupon preservation, and archived read-only controls verified
  - Plain-permalink REST requests passed and a fresh browser console had 0 errors
  - Responsive campaign and assignment layouts verified at 375 x 812
  - Analytics revenue, averages, denial reasons, and 10 campaign rows rendered with zero console errors
  - Expanded analytics tables used labeled mobile rows with no horizontal overflow at 375 x 812

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
- Refund handling reads WooCommerce order/refund state through CRUD APIs and
  restores only consumed rows whose immutable snapshot permits restoration.
- Reconciliation selects a bounded page of customer/campaign states and computes
  ledger totals with one grouped query for that page; each changed state is
  repaired with the same state-first locking discipline as live transitions.
- Historical indexing reads ascending WooCommerce order-ID pages in bounded
  batches and loads order details through CRUD APIs, preserving HPOS compatibility.
- Each import page resolves campaign assignments in one indexed bulk query before
  identity or transaction work. Unrelated and non-counted orders short-circuit.
- Usage upserts rely on the unique order/campaign key and lock customer/campaign
  state first. Only newly consumed usage changes aggregate counters, so retries,
  stale scheduled actions, and completed-job replays remain idempotent.
- Indexing progress is stored in one non-autoloaded option. Target IDs, batch size,
  and recorded errors are capped; continuations enqueue only while work remains.
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

- Analytics summary queries use date-leading plugin indexes, preserve distinct customer/order semantics, and bound denial groups.
- Revenue pages contain at most 100 distinct order IDs and use WooCommerce's public batch factory, preserving HPOS compatibility and avoiding one order query per ID.
- Campaign analytics caps pages at 50 and attaches all visible currency facts in one aggregate query instead of querying per campaign.
- Both report shapes are revalidated at the transient boundary and share mutation-version invalidation without tracking unbounded cache keys.
- The analytics UI loads only when opened, builds rows in document fragments, inserts API values with textContent, and retains visible labels and live regions on mobile.
- Privacy export resolves indexed user or hashed-email identities and issues three capped page queries rather than loading an unbounded customer history.
- Erasure uses one short transaction to lock the canonical customer, delete indexed identifiers, and clear the WordPress user link while preserving anonymous ledger facts.
- Retention selects at most 250 diagnostics through the created-time index and deletes only that primary-key set; it never scans or deletes usage accounting.
- Public REST and WP-CLI failures use fixed messages. Runtime negative tests cover capability separation, key/hash redaction, stored markup, and injection-style filters.
- The release builder uses a fixed project-scoped staging directory, normalized exclusion rules, locked production dependencies, explicit tree assertions, and deterministic asset generation; packaging adds no application query.

- Signup credit performs one option read per lifecycle event, then one short indexed account transaction only when a rule matches; retries stop at the unique source/reference key.
- Customer/vendor rule validation is closed, exact decimal handling avoids floats, and WordPress/Dokan hooks are thin adapters over testable services.

## Compatibility baseline

| Component | Supported | CI/default target |
|---|---:|---:|
| WordPress | 6.9-7.0 | 7.0.2 |
| WooCommerce | 10.8-10.9 | 10.9.4 |
| PHP | 8.1-8.4 | 8.3 |

The pinned default target is runtime verified. The remaining declared range stays
provisional until the repository-root remote matrix runs successfully.

## Completion

No implementation phases remain in the private-beta scope. The broader declared
compatibility range remains gated by the remote matrix, and promotion to production
remains a release decision after that workflow passes. This checkout has no Git
remote configured, so the workflow cannot be dispatched from here yet.
