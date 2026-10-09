# Changelog

All notable changes to PromoGuard for WooCommerce are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project uses semantic versioning once a public release line begins.

## [0.1.0-dev] - 2026-07-25

### Added

- Campaign-level eligibility across assigned native WooCommerce coupons.
- User-first and hashed-email customer identity with safe guest merging.
- Atomic checkout reservations, order lifecycle transitions, expiration,
  refunds, and reconciliation.
- HPOS-compatible historical indexing with Action Scheduler and WP-CLI controls.
- Accessible campaign administration, operational reports, and currency-safe
  analytics.
- WordPress personal-data export and erasure with anonymous accounting
  retention.
- Daily bounded retention for eligibility decisions older than 365 days.
- Standalone, administrator-configurable signup bonuses backed by currency-scoped store-credit balances and an append-only ledger.
- Customer registration awards by default; Dokan vendor awards wait for vendor approval by default.

### Security

- Capability-separated REST settings, reports, campaign operations, and tools.
- Prepared and bounded persistence queries with fixed public error messages.
- Negative runtime coverage for authorization, markup sanitization, key/hash
  redaction, and injection-style filters.
