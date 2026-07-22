# Architecture

## Product boundary

WooCommerce owns coupons, discount calculation, taxes, orders, and order coupon
items. PromoGuard owns campaign organization, shared eligibility, reservations,
usage history, explanations, and campaign analytics.

The MVP integrates only with native `WC_Coupon` objects. Future promotion
sources must enter through a small source adapter introduced when the first
source domain is implemented; Phase 0 intentionally does not add an empty
interface.

## Bootstrap flow

1. The main plugin file defines stable path/version constants and loads the
   optimized Composer autoloader.
2. `PromoGuard\Plugin` registers compatibility and startup hooks.
3. `PromoGuard\Support\Requirements` checks the narrow, tested runtime range.
4. Unsupported environments receive one safe administrator notice; storefront
   requests and sites without WooCommerce do not fatal.
5. A supported environment fires `promoguard_loaded`. Domain services will be
   wired behind this boundary in later phases.

## Dependency direction

Future UI and transport layers (Admin, REST, CLI, checkout hooks) depend on
application/domain services. Domain services may depend on small repository and
promotion-source contracts. Business rules must not live in controllers, React
components, or hook callbacks.

Order access will use WooCommerce CRUD APIs exclusively so HPOS and legacy order
storage can share the same implementation. SQL is reserved for indexed
PromoGuard-owned tables and must always be prepared.

## Performance baseline

Phase 0 performs constant-time version checks and no database queries. Later
checkout work must resolve assignments and customer campaign state through
bounded indexed lookups; historical order scans belong only in background jobs.
