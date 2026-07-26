=== PromoGuard for WooCommerce ===
Contributors: promoguard
Tags: woocommerce, coupons, promotions, campaigns
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0-dev
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Campaign-level eligibility, lifecycle controls, and reporting for native WooCommerce coupons.

== Description ==

PromoGuard groups related native WooCommerce coupons into campaigns and applies
shared customer eligibility rules across them.

Features include:

* Campaign-wide lifetime customer limits across multiple coupons.
* Classic Checkout and Checkout Block validation.
* Atomic reservation, order-status, failure, cancellation, and refund handling.
* HPOS-compatible historical indexing, reconciliation, and analytics.
* Accessible campaign administration and operational reports.
* WordPress personal-data export, identifier erasure, and bounded retention.

PromoGuard keeps coupons and orders owned by WooCommerce. It does not transmit
customer data to an external service and does not collect telemetry.

This version remains a private beta and should be evaluated on a staging site
before production use.

== Installation ==

1. Upload the complete `promoguard-for-woocommerce` directory to
   `/wp-content/plugins/`.
2. Activate PromoGuard for WooCommerce after WooCommerce is active.
3. Open WooCommerce > PromoGuard and create a Draft campaign.
4. Assign existing native coupons, configure the campaign rules, and activate it.

PHP 8.1-8.4, WordPress 6.9-7.0, and WooCommerce 10.8-10.9 are supported by the
current compatibility policy. Back up the site before installation or upgrade.

== Frequently Asked Questions ==

= Does PromoGuard replace WooCommerce coupons? =

No. Coupons and orders remain native WooCommerce records. PromoGuard stores the
campaign assignments and the shared customer usage ledger.

= Does PromoGuard send customer data elsewhere? =

No. PromoGuard has no telemetry or external customer-data service.

= What happens when personal data is erased? =

PromoGuard removes hashed identifiers and the WordPress user link. Anonymous
usage and aggregate facts remain where needed for accounting and lifetime-rule
enforcement. Eligibility decisions are retained for 365 days.

== Privacy ==

PromoGuard registers with the WordPress personal-data exporter and eraser and
adds suggested Privacy Policy Guide text. It stores keyed hashes rather than raw
email addresses in its own tables.

== Source ==

Readable administration sources are included in `assets/src`. Generated assets
in `assets/build` can be reproduced with:

`npm ci`
`npm run build`

== Changelog ==

= 0.1.0-dev =
* Added campaign-wide eligibility across native coupons.
* Added checkout reservations, lifecycle, refunds, and reconciliation.
* Added historical indexing, administration reports, and analytics.
* Added WordPress privacy tools, bounded retention, and security hardening.