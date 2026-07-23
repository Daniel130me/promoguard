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
request-cached target resolution.
