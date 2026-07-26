# Analytics

PromoGuard analytics reports facts recorded by the plugin and current
WooCommerce order totals. The reports describe campaign activity; they do not
claim that a campaign caused an order or its revenue.

## Access and filters

Both endpoints require `view_promoguard_reports`, which is assigned to
Administrators and Shop Managers:

- `GET /wp-json/promoguard/v1/analytics/summary`
- `GET /wp-json/promoguard/v1/analytics/campaigns`

Supported filters are `starts_at_gmt`, `ends_at_gmt`, and an optional positive
`campaign_id`. Dates are ISO 8601 values, the default window is the latest 30
days, and a request may cover at most 366 days. Campaign pages also accept
`page` and `per_page`; the server caps `per_page` at 50.

## Metric semantics

- **Redemptions** count currently consumed usage rows.
- **Unique customers** deduplicate customers across currencies.
- **Campaign orders** deduplicate each order within each campaign.
- **Unique orders** deduplicate orders across all campaigns in a summary.
- **Restored usages** count usage rows restored after refunds.
- **Denied attempts** count persisted denial decisions.
- **Consumed and restored discount** sum immutable usage snapshots.
- **Order revenue** sums the current totals of distinct consumed WooCommerce
  orders. Deleted orders are skipped.
- **Average discount** is consumed discount divided by redemptions in the same
  currency.
- **Average order** is order revenue divided by distinct loaded orders in the
  same currency.

Every monetary row is grouped by currency. PromoGuard never converts or combines
currencies.

The campaign endpoint returns activity-ranked campaign facts and page-scoped
currency discount breakdowns. Revenue is reported in the summary because
loading it independently for every campaign would introduce an expensive
per-campaign order-query loop.

## Performance and consistency

Ledger aggregates use date-leading indexes and prepared queries. Revenue order
IDs are read in ascending, distinct pages of at most 100 and loaded through
WooCommerce's public batch order factory, preserving HPOS compatibility.
Campaign pages use aggregate queries plus one currency query for only the
visible campaign IDs; they do not issue one query per campaign.

Summary and campaign results are cached for five minutes in a shared,
versioned namespace. Usage lifecycle changes, refund restorations, and
historical imports advance that namespace through
`promoguard_analytics_changed`, so stale keys expire naturally without an
unbounded cache-key registry.

## Administration

The Analytics administration view loads only when opened. It provides UTC date
and campaign filters, summary fact cards, currency-separated money tables,
denial reasons, and a paginated campaign-performance table. Tables collapse to
labeled rows on narrow screens and expose loading and result changes through
live regions.
