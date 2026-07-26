# Privacy and Security

PromoGuard stores only the customer and promotion facts required to enforce
campaign-wide limits, preserve WooCommerce accounting history, and explain
eligibility outcomes. It does not send customer data to an external service and
does not collect telemetry.

This document describes the plugin behavior. Store operators remain responsible
for reviewing their own legal and regulatory obligations.

## Stored data

PromoGuard can store:

- an internal customer ID;
- a WordPress user ID for an authenticated customer;
- keyed HMAC-SHA256 hashes of normalized user IDs and billing email addresses;
- campaign, coupon, WooCommerce order, discount, currency, and lifecycle facts;
- bounded eligibility decision explanations and event times.

Raw email addresses are not stored in PromoGuard tables. The site-specific HMAC
key is stored as a non-autoloaded WordPress option and is never returned by the
REST API, personal-data exporter, or administration UI. PromoGuard does not
store payment details, passwords, cookies, or full request payloads.

## WordPress privacy tools

PromoGuard registers with the standard WordPress personal-data exporter and
eraser.

The exporter:

- resolves the request by the authoritative WordPress user ID first, then by a
  keyed email hash;
- returns campaign state, promotion usage, and eligibility decisions;
- reads at most 20 rows from each category per callback page;
- never exports identifier hashes, the HMAC key, reservation keys, raw metadata,
  or internal exception details.

The eraser atomically deletes every PromoGuard identifier for the matched
customer and clears the WordPress user link. It intentionally retains the
now-unlinked customer row, campaign counters, usages, and decisions needed for
promotion accounting, abuse prevention, and lifetime-rule enforcement. The
WordPress erasure result reports that anonymous records were retained.

PromoGuard also contributes suggested text to the WordPress Privacy Policy
Guide on `admin_init`.

## Retention

Eligibility decisions are diagnostic records rather than the authoritative
promotion ledger. PromoGuard retains them for 365 days.

A daily Action Scheduler worker:

- selects expired decisions through the `created_at_gmt` index;
- processes at most 250 decision IDs per run;
- deletes only the selected primary keys;
- leaves campaign state, usage accounting, orders, and coupons unchanged.

If more than 250 decisions are expired, later daily runs continue the cleanup.
Usage and aggregate retention is not time-limited because those records enforce
lifetime campaign rules and preserve promotion accounting.

## Authorization and output safety

- Administrators receive all PromoGuard capabilities.
- Shop Managers can operate campaigns, view reports, and run safe indexing, but
  cannot read or change privacy and uninstall settings.
- REST endpoints use explicit capability callbacks and WordPress REST nonces.
- List inputs are schema-validated, sanitized, paginated, and capped.
- Database inputs use prepared statements; bounded dynamic ID lists contain
  only integers selected from PromoGuard tables.
- Administration rows are rendered with `textContent`, and PHP views escape
  output for their target context.
- Unexpected REST and WP-CLI failures use fixed public messages. SQL errors,
  stack traces, keys, hashes, and raw exception messages are not exposed.
- Per-order indexing errors are fixed, bounded summaries and never contain raw
  customer or database payloads.

PromoGuard has no CSV export. Consequently, CSV formula execution is not an
active output path. If a CSV feature is added later, values beginning with
formula control characters must be neutralized before export.

## Verification

The isolated runtime suite verifies:

- anonymous REST denial;
- Shop Manager access to reports and safe tools without settings access;
- rejection of Shop Manager settings reads and writes;
- generic REST output for a synthetic SQL-style failure;
- absence of the HMAC key and identifier hashes from public responses;
- removal of executable markup during campaign mutation;
- exact prepared filtering for an SQL-injection-style decision reason;
- bounded decision retention without deleting current diagnostics or usages;
- privacy export followed by atomic identifier unlinking while accounting rows
  remain present.

Run the complete checks described in [TESTING.md](TESTING.md) before release.
