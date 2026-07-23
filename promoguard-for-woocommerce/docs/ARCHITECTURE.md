# Architecture

## Product boundary

WooCommerce owns coupons, discount calculation, taxes, orders, and order coupon
items. PromoGuard owns campaign organization, shared eligibility, reservations,
usage history, explanations, and campaign analytics.

The MVP integrates only with native `WC_Coupon` objects through a promotion
source contract. Campaign assignment records preserve source identity and
display snapshots without taking ownership of the native coupon.

## Bootstrap flow

1. The main plugin file defines stable path/version constants and loads the
   optimized Composer autoloader.
2. `PromoGuard\Plugin` registers compatibility and startup hooks.
3. `PromoGuard\Support\Requirements` checks the narrow, tested runtime range.
4. Unsupported environments receive one safe administrator notice; storefront
   requests and sites without WooCommerce do not fatal.
5. A supported environment fires `promoguard_loaded`. Application services
   construct site-scoped repositories behind this boundary.

## Dependency direction

UI and transport layers (Admin, REST, CLI, checkout hooks) depend on
application/domain services. Domain services may depend on small repository and
promotion-source contracts. Business rules must not live in controllers, React
components, or hook callbacks.

Order access uses WooCommerce CRUD APIs exclusively so HPOS and legacy order
storage can share the same implementation. SQL is reserved for indexed
PromoGuard-owned tables and must always be prepared.

## Schema and lifecycle foundations

Phase 1 introduces seven PromoGuard-owned tables for campaigns, promotion
assignments, customer identities, campaign state, usage, and decision records.
Table naming is centralized, the schema is applied through `dbDelta`, and the
stored schema version makes future migrations incremental and administrator-only.

Activation creates non-autoloaded options, stable installation identifiers, and
role capabilities. Storage verification checks all expected tables in one bounded
metadata query and fails closed unless the lock-sensitive state and usage tables
use InnoDB. Uninstall preserves data by default and removes only centralized
PromoGuard tables, options, and capabilities after explicit opt-in.

## Campaigns, identity, and eligibility

Phase 2 adds validated campaign aggregates, source-backed WooCommerce coupon
assignments, REST application services, and the minimal campaign administration
screen. Campaign operations never delete native coupons or historical snapshots.

Phase 4 resolves authenticated WordPress users before considering email. Emails
are conservatively normalized, HMAC-SHA256 hashed with the persistent
non-autoloaded plugin key, and never stored raw. An unclaimed email may attach to
an authenticated customer; an email-owned guest may merge transactionally; two
authenticated customers never merge from email alone.

Guest merges lock customer rows in primary-key order and atomically reconcile
campaign counters, identifiers, usage rows, and decision rows before preserving
the guest as a merge tombstone.

The eligibility engine returns one immutable outcome with a stable reason code,
safe customer message, and administrator explanation. Policy order is campaign
status, login, identity, customer usage, then same-campaign coupon conflicts.
Early contexts may explicitly return provisional identity approval; final
contexts fail closed when identity is absent or conflicting.

Phase 5 resolves native coupon IDs to assignments and campaigns through indexed,
request-cached lookups. Unassigned coupons return immediately to WooCommerce.
Early coupon validation may defer missing guest identity; Classic and Store API
checkout repeat the shared policy with final billing identity and block any
denial. Equivalent decisions and negative assignment lookups are cached only for
the current request. Denied outcomes are written once per request, context,
campaign, promotion, order, and reason to the plugin-owned decision table.
Allowed and provisional outcomes do not create diagnostic writes, and raw
customer identity is never included in denial metadata.

Phase 6 carries the resolved internal customer ID into final checkout without a
second identity query. Reservation persistence creates the campaign/customer
state row idempotently, locks it, releases expired pending usages, checks the
authoritative counters and existing order/campaign usage, then inserts or
reactivates one pending usage and increments the reserved counter in the same
transaction. Only deadlocks and lock-wait timeouts receive three bounded retry
attempts. Classic and Store API processed-order hooks share this reservation
path immediately before payment; normal requests reuse the final cached
evaluation, while a missing or provisional result is evaluated finally.
Order-status changes then route pending usages through a separate lifecycle
policy. Counted statuses consume the reservation, while configured failure and
cancellation statuses release it. Both paths lock the authoritative
campaign/customer state before the unique order/campaign usage row and update
the usage snapshot and counters atomically. Repeated callbacks are idempotent
because only pending rows may transition.

Admin/REST-created orders use the same final validator and reservation coordinator
before their first counted status. Denials create a decision record and one
idempotent order note/marker without removing coupon or financial data. Pending
transitions are loaded from indexed PromoGuard usage snapshots rather than live
coupon assignments, so later detachment or coupon deletion cannot strand usage.

A five-minute Action Scheduler task releases expired reservations in batches of
50 candidate rows. It selects through the status/expiry index, deduplicates
campaign/customer states, and processes each state in its own short transaction.

Phase 7 snapshots the refund policy when pending usage becomes consumed. Refund
hooks calculate the cumulative refunded amount through WooCommerce CRUD APIs;
only a fully refunded order can restore an eligible consumed usage. Restoration
locks campaign/customer state before the usage row, decrements consumed totals,
and records a deduplicated per-campaign outcome on the order. Partial and repeated
refund callbacks therefore leave aggregate state unchanged.

Reconciliation treats usage rows as the authoritative ledger. A scheduled,
bounded batch selects customer/campaign state after a numeric cursor, obtains
aggregate consumed/reserved counts and discount totals in one grouped query, and
repairs only divergent rows using the same state-first locking order as live
transitions. The next Action Scheduler action is enqueued only when another batch
is required, preventing long-running or unbounded requests.

Phase 8 adds a resumable historical indexing application service. One
non-autoloaded WordPress option stores the current job identifier, lifecycle
status, cursor, counters, bounded target-order list, and bounded errors. WP-CLI
provides start, status, pause, resume, retry, and restart controls. Action
Scheduler runs the same service under the `promoguard` group; each callback
claims its expected job identifier, so an action left behind by a restart exits
without mutating the replacement job.

Full-history jobs request ascending WooCommerce order IDs in bounded pages.
Targeted jobs slice a validated, deduplicated order-ID list. Orders are then
loaded through `wc_get_order()` and public CRUD methods, preserving HPOS and
legacy compatibility. Each page bulk-loads all relevant PromoGuard assignments
before resolving identities or opening transactions. Unassigned coupons and
orders outside configured counted statuses stop before write work.

Historical usage imports use the same customer identity rules and state-first
locking order as live reservation transitions. The unique order/campaign usage
key makes replay safe, and aggregate state changes only when a consumed usage is
newly inserted. A historically fully refunded order whose immutable policy
allows restoration is inserted directly as restored, without incrementing
consumed totals.

## Performance baseline

Installation uses one bounded metadata query for seven known tables. Identity
resolution uses unique user and type/hash indexes inside a short transaction.
Eligibility short-circuits status, login, and identity failures before making at
most one lookup through the unique campaign/customer state index. It never scans
orders during checkout; historical reconstruction belongs only in bounded
background jobs. Denial logging performs one prepared insert only when a unique
denial is encountered during the request. Reservation queries use the unique
campaign/customer and order/campaign indexes; expiration cleanup is restricted
to the locked campaign/customer state. Lifecycle callbacks perform one
order/campaign-indexed snapshot lookup and use the same state-first lock order as
reservation persistence to avoid cross-path
deadlocks. Non-checkout validation adds one active-usage lookup and reuses
request-cached target resolution. Refund callbacks use indexed order/campaign
usage lookups and do not scan order history. Reconciliation performs one bounded
state query and one grouped aggregate query per batch, then writes only rows whose
stored totals differ.

Historical indexing adds one bounded WooCommerce order-ID request and one bulk,
indexed campaign-assignment query per page. Order hydration is limited to that
page, target lists and errors have fixed caps, and every imported
customer/campaign pair uses a short state-first transaction. Continuations are
scheduled only while another page remains, and replay does not inflate counters.
