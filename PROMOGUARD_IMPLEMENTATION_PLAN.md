# Master Implementation Plan for Codex

## Project: PromoGuard for WooCommerce

**Status:** Approved corrected specification  
**Development version:** `0.1.0-dev`  
**Distribution:** Free WordPress.org core; optional separate Pro add-on later

---

## 1. Product Definition

Build a production-quality WordPress plugin named **PromoGuard for WooCommerce**.

PromoGuard groups related WooCommerce coupons into campaigns and applies shared customer eligibility rules across every coupon in a campaign.

The first release must solve this completely:

> When a campaign permits one use per customer, a customer who successfully uses one coupon from that campaign cannot use another coupon from the same campaign later.

WooCommerce owns coupon storage, discount calculation, taxes, and order coupon items. PromoGuard owns campaign organisation, assignments, shared eligibility, reservations, usage history, explanations, and basic campaign analytics.

PromoGuard must not modify WordPress, WooCommerce, or another plugin's files.

### Product pillars

Every feature must organise promotions, control eligibility, integrate a promotion source, or analyse campaign performance. Do not add unrelated email, CRM, advertising, landing-page, loyalty, or affiliate-network features.

### Supported sources

The MVP supports native `WC_Coupon` objects, including third-party coupons stored and applied through the native coupon system.

It does not control custom pricing engines, store credit, gift cards, loyalty points, custom BOGO/free-gift engines, fee-based discounts, or vendor-specific engines. Create a small adapter interface for later integrations, but do not implement or advertise untested compatibility.

---

## 2. Release Milestones

### Private beta

- Campaign creation and editing
- Coupon attachment and basic native coupon creation
- Maximum campaign uses per customer
- Maximum one campaign coupon per order
- Authenticated-user and guest-email matching
- Login-required mode
- Classic and Block Checkout enforcement
- Reservations, consumption, failure, and cancellation handling
- Minimal usage history, denial logs, and administration
- HPOS-safe order access

### Public 1.0

- Historical indexing
- Refund restoration and reconciliation
- Complete administration interface
- Basic analytics
- Privacy exporter and eraser
- WP-CLI tools
- WordPress.org packaging and full compatibility matrix

Do not present the private beta as WordPress.org-ready.

---

## 3. Authoritative Business Rules

### Campaign redemption unit

One successful order using a campaign promotion equals **one campaign redemption**.

- The MVP permits at most one attached campaign coupon per order.
- A consumed order increments `consumed_count` once for its campaign.
- Coupon count is not campaign-redemption count.
- Repeated WooCommerce hooks cannot create another redemption.
- Future multiple-promotion support still counts one order/campaign redemption unless a separately named counting mode is introduced.

Eligibility is:

```text
consumed_count + active_reserved_count < maximum_uses
```

Once per lifetime means `maximum_uses = 1` and `period = lifetime`.

Cooldowns are not part of the MVP. Reject unsupported values rather than storing dormant feature settings.

### Campaign status

| Effective status | New use |
|---|---|
| Draft | Denied |
| Scheduled before start | Denied |
| Active within schedule | Evaluated normally |
| Paused | Denied |
| Completed | Denied |
| Archived | Denied; configuration read-only |

Warn that attaching a coupon to a non-active campaign makes it unavailable until activation. Store timestamps in GMT and display them in the site timezone.

### Customer identity precedence

1. Authenticated WordPress user ID is authoritative.
2. Guests may be matched through valid normalized billing email.
3. Attach an email to an authenticated customer only when unclaimed or already owned by that internal customer.
4. Never merge two authenticated users solely because they used the same billing email.
5. Keep conflicting authenticated identities separate and record a diagnostic.
6. Merge a guest into a newly authenticated customer only when no authenticated conflict exists.

Phone matching is a future feature and must not be partially implemented.

### Order lifecycle

Default counted statuses are Processing and Completed. Pending, Checkout Draft, Failed, and Cancelled are not counted. On Hold is configurable.

- Adding a coupon to a cart does not consume eligibility.
- Creating a payable order creates a temporary reservation.
- Entering a counted status consumes it.
- Failed and Cancelled orders release pending reservations.
- Full refunds follow campaign policy.
- Partial refunds keep usage consumed by default.
- Every transition is idempotent.

Guest decisions may be provisional before email entry. Final server-side validation and atomic reservation must happen before payment. Never reveal previous customer/order details to shoppers.

---

## 4. Technical Baseline

```text
Plugin name: PromoGuard for WooCommerce
Directory: promoguard-for-woocommerce
Main file: promoguard-for-woocommerce.php
Text domain: promoguard-for-woocommerce
Namespace: PromoGuard
REST namespace: promoguard/v1
Action Scheduler group: promoguard
Database suffix: promoguard_
Version: 0.1.0-dev
```

Phase 0 must pin exact minimum/latest WordPress and WooCommerce versions, HPOS and legacy storage where supported, Classic/Block Checkout, and compatible PHP versions. An allowed-failure beta job does not establish compatibility.

The plugin header must contain accurate WordPress, WooCommerce, and PHP requirements.

### Engineering requirements

- Follow WordPress Coding Standards.
- Use WooCommerce public APIs; never `Automattic\WooCommerce\Internal` classes.
- Use `WC_Order`, `wc_get_order()`, `wc_get_orders()`, and order CRUD metadata methods.
- Never query/mutate order storage through direct SQL or WordPress post-meta APIs.
- Use prepared SQL only for PromoGuard tables.
- Never scan historical orders during checkout.
- Keep checkout queries indexed and bounded.
- Make migrations and lifecycle operations idempotent.
- Preserve data on deactivation; delete only after uninstall opt-in.
- Keep business logic outside REST controllers and React components.
- Comment why non-obvious concurrency, lifecycle, and identity logic exists.
- Avoid magic values, hard-coded assumptions, and speculative abstraction.

Verify PHP, Composer, Node, npm, Git, Docker if using `wp-env`, WP-CLI if required, and an isolated test database. Never run automated order/refund/uninstall/concurrency tests against the existing site database.

---

## 5. Repository Structure

Create files incrementally; do not create empty placeholder classes.

```text
promoguard-for-woocommerce/
├── promoguard-for-woocommerce.php
├── uninstall.php
├── readme.txt
├── README.md
├── LICENSE
├── composer.json
├── package.json
├── phpcs.xml.dist
├── phpstan.neon.dist
├── phpunit.xml.dist
├── .editorconfig
├── .gitignore
├── .distignore
├── .wp-env.json
├── IMPLEMENTATION_STATUS.md
├── src/
│   ├── Plugin.php
│   ├── Activation/
│   ├── Admin/
│   ├── Api/
│   ├── Campaign/
│   ├── Promotion/
│   ├── Customer/
│   ├── Eligibility/
│   ├── Usage/
│   ├── Checkout/
│   ├── Orders/
│   ├── Background/
│   ├── Analytics/
│   ├── Logging/
│   ├── Privacy/
│   ├── Cli/
│   └── Support/
├── assets/src/
├── assets/build/
├── tests/Unit/
├── tests/Integration/
├── tests/E2E/
├── docs/
└── .github/workflows/
```

Use PSR-4 `PromoGuard\` to `src/`. Use WordPress-provided React packages and do not bundle React again.

---

## 6. Corrected Database Design

Use `$wpdb->prefix`, site charset/collation, `dbDelta()`, and versioned migrations. Do not add database foreign keys.

State and usage tables require InnoDB. Verify their engine after installation. If transactional guarantees are unavailable, display a critical administrator error and fail protected checkout safely.

### Campaigns

`promoguard_campaigns` stores ID, UUID, name, slug, description, goal, status, priority, GMT schedule, validated usage/conflict/settings JSON, creator, and timestamps.

Indexes:

```text
UNIQUE uuid
UNIQUE slug
INDEX status + starts_at_gmt + ends_at_gmt
INDEX priority
```

MVP JSON contains only maximum uses, lifetime period, login requirement, counted statuses, failure/cancellation release, refund behavior, and maximum campaign coupons per order.

### Campaign promotion assignments

`promoguard_campaign_promotions` stores ID, UUID, campaign, source, source type, external ID/code snapshot, channel, label, sort order, settings, and timestamps.

Indexes:

```text
UNIQUE uuid
UNIQUE source + source_type + external_id
INDEX campaign_id
INDEX external_code
```

For MVP, source is `woocommerce`, type is `coupon`, and external ID is coupon ID.

On detachment, delete only the assignment row. Preserve usage/decision snapshots, do not modify the coupon, and permit later reassignment.

### Customers and identifiers

`promoguard_customers` stores ID, nullable unique WordPress user ID, timestamps, and optional merge target.

`promoguard_customer_identifiers` stores customer ID, type, HMAC-SHA256 hash, primary state, and first/last seen times. Unique index is type plus hash; another index is customer plus type.

Initial types are `user` and `email`. Store no raw email. Store the plugin HMAC key in a non-autoloaded option; generate it once, never expose it, and never regenerate it during ordinary upgrades.

### Customer campaign state

`promoguard_customer_campaign_state` stores campaign/customer, consumed/reserved counts, total discount, first/last consumption, last order, lock version, and timestamps.

Indexes:

```text
UNIQUE campaign_id + customer_id
INDEX customer_id
INDEX campaign_id + last_consumed_at_gmt
```

This is the bounded checkout lookup. Never derive eligibility by scanning orders during checkout.

### Campaign usages

`promoguard_usages` contains **one mutable lifecycle row per order and campaign**. It is not an immutable event stream.

Required fields:

```text
id, uuid, campaign_id, promotion_id, customer_id, order_id
order_item_id, coupon_id, coupon_code
status, order_status, discount_amount, currency
reservation_key, reserved_until_gmt
reserved_at_gmt, consumed_at_gmt, released_at_gmt, restored_at_gmt
created_at_gmt, updated_at_gmt, metadata
```

Statuses: `pending`, `consumed`, `released`, `restored`, `voided`, `manual_review`.

Indexes:

```text
UNIQUE uuid
UNIQUE reservation_key
UNIQUE order_id + campaign_id
INDEX campaign_id + customer_id + status
INDEX customer_id + status
INDEX status + reserved_until_gmt
INDEX campaign_id + consumed_at_gmt
```

Campaign, promotion, coupon, currency, and discount values are snapshots. Reports must survive coupon/assignment deletion. Do not create a separate transition table in the MVP.

### Decision logs and options

Decision logs store request, campaign, promotion, customer, order, coupon, context, decision, reason, safe messages, metadata, and time. Index request, campaign/date, customer/date, decision/reason, and order.

Persist denials by default, not repeated allowed recalculations. Deduplicate within a request and clean retained logs in scheduled batches.

Options:

```text
promoguard_version
promoguard_db_version
promoguard_hash_key
promoguard_settings
promoguard_installation_id
promoguard_onboarding_complete
promoguard_delete_data_on_uninstall
```

---

## 7. Domain and Policy Design

Use one immutable decision with allowed/provisional state, reason code, safe customer message, administrator explanation, and metadata.

Reason codes:

```text
allowed
provisional_identity_required
campaign_draft
campaign_paused
campaign_archived
campaign_not_started
campaign_expired
login_required
customer_identity_missing
customer_limit_reached
campaign_coupon_already_applied
maximum_coupons_per_order_reached
invalid_configuration
identity_conflict
reservation_conflict
database_error
```

Evaluate campaign status, login, identity availability, customer usage, same-campaign coupon limit, registered extension policies, then allow/provisional allow. The first denial stops evaluation. Use the same engine everywhere.

Normalize emails by trim, lowercase, and WordPress validation; never provider-specific alias rules. Resolve authenticated users first, attach only unclaimed emails, keep authenticated conflicts separate, resolve guests by email hash, and merge guest-to-user only without conflict. Merges are transactional and idempotent.

---

## 8. Checkout, Reservation, and Lifecycle

### Validation

Resolve coupon and indexed assignment; leave unassigned coupons unchanged; build context; run policy; request-cache the result; persist a deduplicated denial; return allowed/invalid.

Expected public hooks include:

```text
woocommerce_coupon_is_valid
woocommerce_coupon_error
woocommerce_after_checkout_validation
woocommerce_checkout_order_processed
woocommerce_store_api_checkout_update_order_from_request
woocommerce_store_api_checkout_order_processed
woocommerce_order_status_changed
woocommerce_payment_complete
woocommerce_refund_created
```

Confirm signatures against supported versions. Classic hooks do not automatically guarantee Block support.

### Atomic reservation

1. Begin transaction.
2. Resolve authoritative customer.
3. Atomically insert state row if missing.
4. Select it `FOR UPDATE`.
5. Release expired reservations associated with the locked state.
6. Check consumed plus reserved count.
7. Check existing `order_id + campaign_id` usage.
8. Return equivalent pending/consumed usage successfully.
9. Roll back and deny if the limit is exceeded.
10. Insert one pending usage with unique reservation key.
11. Increment reserved count and lock version.
12. Commit.

On deadlock, roll back and retry a small bounded number of times. If exhausted, fail protected checkout safely and log the error. Never call remote services inside the transaction.

Default reservation duration is 30 minutes. Cleanup uses bounded batches and decrements reservations exactly once without negative counts.

### Consumption and refunds

On first counted status, lock state, return if already consumed, decrement reservation once, increment consumption once, add discount once, snapshot data, and commit.

Failed/Cancelled pending usages release once.

Do not treat every refund hook as full refund. Load the order through CRUD, calculate cumulative refunds, classify full/partial, and keep adjustments idempotent. Full-refund modes are restore, keep consumed, and manual review. Partial refunds keep usage by default.

Storefront checkout receives real-time validation. Admin/REST orders receive final validation before first counted status; invalid promotions do not consume usage and receive an order note/decision log. Do not silently remove financial data.

---

## 9. Historical Indexing and Reconciliation

Use Action Scheduler group `promoguard`. Query paginated IDs through `wc_get_orders()`, load with `wc_get_order()`, process 50 by default, apply safe identity resolution, upsert one order/campaign usage, save progress/errors, and support pause, resume, retry, restart, and targeted rebuild.

Reconciliation derives:

```text
consumed_count = consumed usage count
reserved_count = non-expired pending usage count
total_discount = consumed discount sum
```

Run outside checkout in bounded batches.

---

## 10. Administration and REST

Build minimal administration immediately after campaign-domain work: campaign details, schedule, coupon search/attachment/detachment, maximum uses, login requirement, statuses, lifecycle settings, messages, and plain-language summary.

The full React application later adds dashboard, complete editor, usage, decisions, settings, tools, accessibility, and responsive layouts.

Only unused Draft campaigns may be permanently deleted. Campaigns with assignments/usages/decisions must be archived. Never delete coupons or orders.

REST routes must be paginated, capability-protected, validated, sanitized, and structured. Unsafe campaign deletion returns a conflict explaining archival.

---

## 11. Security, Privacy, and Logging

Capabilities:

```text
manage_promoguard
view_promoguard_reports
manage_promoguard_settings
run_promoguard_tools
```

Administrators receive all. Shop Managers may manage campaigns, view reports, and run safe indexing, but cannot delete plugin data or change privacy/debug settings.

Use permissions, nonces, sanitization, escaping, prepared SQL, and JSON validation. Prevent stored XSS and CSV formula injection. Never expose SQL errors, traces, hashes, or keys. No telemetry without opt-in and no external customer-data transmission.

Implement privacy text, export, erasure/anonymization, identifier unlinking, and retention while preserving necessary accounting records and anonymous aggregates.

Use `wc_get_logger()` source `promoguard`; debug is off by default. Never log payment data, passwords, cookies, keys, or sensitive payloads.

---

## 12. Analytics and Query Performance

Metrics include redemptions, unique customers, campaign orders, revenue from orders using campaign, discounts, averages, denials, and refunds.

Do not imply causation. Group currencies separately; never convert without an integration.

- Campaign orders are unique by order plus campaign.
- Global summaries deduplicate orders across campaigns.
- Reports label per-campaign versus global counts.

Every query selects required columns, paginates lists, uses suitable indexes, avoids checkout wildcards and N+1 loading, request-caches repeated lookups, moves scans to background jobs, and invalidates report caches after lifecycle changes.

---

## 13. Extension Architecture

Define a minimal promotion-source interface for identity, availability, resolution, order extraction, and control/report support. Implement only `WooCommerceCouponSource` initially.

Compatibility levels: `full`, `partial`, `observe_only`, `unsupported`. Do not add unused interface methods or undocumented hooks.

---

## 14. Activation, Deactivation, and Uninstall

Activation checks requirements, creates/migrates tables, verifies InnoDB, saves versions, generates identifiers once, creates settings/capabilities, and schedules cleanup. Missing WooCommerce must not fatal.

Deactivation pauses imports, unschedules recurring cleanup, clears temporary caches, and preserves data.

Uninstall verifies `WP_UNINSTALL_PLUGIN` and preserves data unless deletion is explicitly enabled. When enabled, remove only PromoGuard data; never coupons, orders, or other plugin data. Do not claim multisite deletion until tested.

---

## 15. Testing Strategy

Unit tests cover status/policies, normalization/hashing, identity/conflicts, safe merges, eligibility, expiration, lifecycle, refunds, currencies, and reason codes.

Integration tests cover activation/migrations/InnoDB, missing WooCommerce, campaign CRUD, coupon reassignment, archival, identity, Classic/Block Checkout, reservations, lifecycle, refunds, admin/REST orders, indexing, reconciliation, HPOS, and legacy storage.

Concurrency tests cover simultaneous reservations, missing-state creation, deadlock retries, cleanup, repeated hooks, and non-negative counts.

E2E proves first use, second-code denial, other-customer allowance, failed-payment release, guest validation, refund restoration, and Classic/Block parity.

CI runs PHP syntax, PHPCS, PHPCompatibility, PHPStan, PHPUnit, TypeScript, JS lint/tests, build, Playwright, and ZIP verification.

---

## 16. WordPress.org Requirements

- GPL-compatible license and human-readable source
- No trial expiration, forced account, remote code, or non-consensual tracking
- Accurate compatibility and restrained notices
- Valid readme, privacy, and uninstall documentation
- Built assets included; development dependencies/caches excluded
- Readable source for minified assets included or linked
- Free core fully operational without a license key

---

## 17. Corrected Implementation Phases

### Phase 0: Environment, repository, and bootstrap

Audit, establish plugin boundary, pin compatibility, verify tools/isolation, configure Composer/npm/CI, and create bootstrap, dependency guard, status, and architecture documents.

**Gate:** activation, missing-WooCommerce behavior, autoloading, PHP lint, and JS build pass. No unrelated changes.

### Phase 1: Schema and lifecycle foundations

Create corrected tables, InnoDB verification, migrations, options, capabilities, and central helpers.

**Gate:** repeatable activation/migrations and safe uninstall pass.

### Phase 2: Campaigns and native coupons

Implement domain, coupon source, CRUD, search/creation, attachment/detachment/reassignment, archival, REST, and tests.

**Gate:** one active assignment, safe reassignment, preserved snapshots, and no coupon deletion.

### Phase 3: Minimal administration

Build beta campaign form and coupon selector.

**Gate:** administrators configure the acceptance campaign without database/manual REST work.

### Phase 4: Identity and eligibility

Implement identifiers, user-first resolution, guest email, safe merges/conflicts, state, policies, decisions, and denial logs.

**Gate:** no email-only authenticated merge; deterministic limits.

### Phase 5: Checkout enforcement

Implement validation, messages, Classic/Store API final validation, provisional guests, and request caching.

**Gate:** no bypass; unrelated coupons unaffected.

### Phase 6: Reservations and lifecycle

Implement atomic state creation, locks, deadlock retry, reservation, consumption, release, expiration, and admin/REST validation.

**Gate:** one simultaneous reservation succeeds; hooks are idempotent. Private beta complete.

### Phase 7: Refunds and reconciliation

Implement cumulative refunds, restoration, reconciliation, order notes, and rebuild tools.

**Gate:** partial-to-full sequences restore once; rebuilt state matches usages.

### Phase 8: Historical indexing

Implement batches, progress, pause/resume/retry, targeted indexing, errors, and WP-CLI.

**Gate:** resumable, idempotent imports.

### Phase 9: Full administration

Build React dashboard, editor, history, decisions, settings, tools, and accessibility.

**Gate:** permissions, keyboard, responsive, and browser checks pass.

### Phase 10: Analytics

Implement metrics, breakdowns, currencies, caching, invalidation, and filters.

**Gate:** fixture totals reconcile without duplicates.

### Phase 11: Privacy, security, and hardening

Implement export/erase, retention, audits, injection tests, redaction, and negative tests.

**Gate:** unauthorized access fails; sensitive data is not exposed.

### Phase 12: Release quality

Complete docs, readme, changelog, source disclosure, compatibility, Plugin Check, ZIP, migrations, and regressions.

**Gate:** clean install, no debug warnings/critical findings, and full acceptance pass.

---

## 18. XanderTrade Acceptance Scenario

```text
Campaign: Customer Acquisition Offers
Status: Active
Coupons: WELCOME10, FACEBOOK10, INSTAGRAM10, INFLUENCER-JANE
Maximum uses: 1 lifetime
Maximum campaign coupons per order: 1
Identity: Authoritative user ID with safe email support
Counted: Processing and Completed
Failed/Cancelled: Release
Full refund: Restore
```

1. Customer A uses `WELCOME10`; Processing consumes one redemption.
2. Customer A later receives `customer_limit_reached` for `FACEBOOK10`.
3. Customer B may use `FACEBOOK10`.
4. Failed payment releases Customer B's reservation.
5. A guest using Customer A's prior email is denied at final validation.
6. A different authenticated user with a conflicting billing email is not silently merged.
7. Full refund restores Customer A exactly once.
8. Customer A may then use another campaign coupon.
9. Critical flows pass in Classic and Block Checkout.

---

## 19. Coding and Maintainability Rules

- Write readable, focused, well-structured code.
- Comment why locks, idempotency, and identity logic exist.
- No empty placeholders, suppressed errors, or duplicated policies.
- Do not hardcode prefixes, paths, timezones, statuses, or messages.
- Do not scan historical orders during customer requests.
- Confirm indexes for checkout queries.
- Add tests with each feature; keep strings translatable.
- Do not add speculative abstraction.

After every phase, review and record readability, structure, comments, maintainability, extensibility, magic values, assumptions, query count/indexes, and unnecessary complexity.

---

## 20. Required Phase Report

Report phase, implementations, files, database changes, hooks, tests, exact results, manual testing, performance review, maintainability review, limitations, and next phase.

Do not continue when critical tests fail.

---

## 21. Initial Execution Instruction

Begin with **Phase 0 only**:

1. Audit the workspace.
2. Establish the PromoGuard plugin boundary.
3. Record platform/tool versions.
4. Define isolated testing.
5. Create files only as required.
6. Configure Composer, npm, linting, analysis, and CI.
7. Create bootstrap and WooCommerce dependency guard.
8. Create implementation-status and architecture documents.
9. Run Phase 0 checks.
10. Return the phase report.

Do not implement tables, campaigns, or checkout enforcement during Phase 0.

> WooCommerce calculates the discount; PromoGuard controls campaign membership, eligibility, usage lifecycle, explanations, and reporting.
