# PromoGuard for WooCommerce

PromoGuard groups native WooCommerce coupons into campaigns and applies shared
customer eligibility rules across every coupon in a campaign.

The project is in active development. Version `0.1.0-dev` is a private-beta
codebase and is not ready for installation on production stores.

## Development requirements

- PHP 8.1-8.4 (PHP 8.3 recommended)
- Composer 2
- Node.js 20-24 and npm 10+
- Docker for the isolated `wp-env` environment

## Setup

```sh
composer install
npm install
npm run build
npm run env:start
```

Run PHP checks with `composer check` and JavaScript checks with `npm run check`.
See [docs/TESTING.md](docs/TESTING.md) before running integration tests.
See [docs/ADMINISTRATION.md](docs/ADMINISTRATION.md) for roles, reports,
settings, and historical-indexing operations.
See [docs/ANALYTICS.md](docs/ANALYTICS.md) for metric definitions, filters,
currency handling, caching, and campaign breakdowns.
