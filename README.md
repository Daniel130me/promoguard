# PromoGuard for WooCommerce

PromoGuard organizes native WooCommerce coupons into campaigns and applies
shared customer eligibility rules across every coupon in a campaign. It also
provides campaign lifecycle tracking, reporting, and store-credit workflows.

The WordPress plugin is maintained in
[`promoguard-for-woocommerce/`](promoguard-for-woocommerce/).

## Highlights

- Shared customer limits across related WooCommerce coupons
- Classic Checkout and Checkout Block enforcement
- Reservation, consumption, cancellation, refund, and reconciliation handling
- Customer identity safeguards for registered users and guests
- Campaign reporting, denial auditing, and historical indexing
- Configurable signup bonuses and store-credit redemption
- HPOS and legacy order-storage support

## Requirements

- WordPress 6.9 or newer
- WooCommerce 10.8 or newer
- PHP 8.1 through 8.4
- Composer 2 for PHP development dependencies
- Node.js 20 through 24 and npm 10 or newer for asset builds
- Docker for the isolated `wp-env` integration environment

## Development setup

Run development commands from the plugin directory:

```sh
cd promoguard-for-woocommerce
composer install
npm ci
composer check
npm run check
```

Integration tests must run only against the disposable `wp-env` environment.
They create and mutate WordPress users, coupons, orders, refunds, and
PromoGuard-owned records.

```sh
npm run env:start
npx --yes @wordpress/env@10.30.0 run cli wp eval-file wp-content/plugins/promoguard-for-woocommerce/tests/Integration/runtime-smoke.php
npx --yes @wordpress/env@10.30.0 run cli wp eval-file wp-content/plugins/promoguard-for-woocommerce/tests/Integration/lifecycle-smoke.php
npm run env:stop
```

See the [testing guide](promoguard-for-woocommerce/docs/TESTING.md) for the full
test matrix and safety requirements.

## Documentation

- [Plugin development guide](promoguard-for-woocommerce/README.md)
- [Administration](promoguard-for-woocommerce/docs/ADMINISTRATION.md)
- [Architecture](promoguard-for-woocommerce/docs/ARCHITECTURE.md)
- [Analytics](promoguard-for-woocommerce/docs/ANALYTICS.md)
- [Privacy](promoguard-for-woocommerce/docs/PRIVACY.md)
- [Store credit](promoguard-for-woocommerce/docs/STORE-CREDIT.md)
- [Testing](promoguard-for-woocommerce/docs/TESTING.md)
- [Release process](promoguard-for-woocommerce/docs/RELEASE.md)
- [Changelog](promoguard-for-woocommerce/CHANGELOG.md)

## Contributing and support

Read [CONTRIBUTING.md](CONTRIBUTING.md) before submitting a change. Use GitHub
Issues for reproducible bugs and focused feature proposals. For security
vulnerabilities, follow [SECURITY.md](SECURITY.md) instead of opening a public
issue.

## License

PromoGuard for WooCommerce is licensed under the
[GNU General Public License v2.0 or later](LICENSE).
