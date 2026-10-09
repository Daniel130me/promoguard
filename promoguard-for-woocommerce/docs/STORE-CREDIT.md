# Store Credit: Installation and Test Guide

This guide tests PromoGuard store credit independently from coupon campaigns.
Use a disposable site or a staging copy; refund and order-status tests create
real WooCommerce records.

## 1. Prerequisites

- WordPress 6.9 or newer
- WooCommerce 10.8 or newer
- PHP 8.1 through 8.4
- InnoDB database tables
- Node.js 20 through 24 and Composer when building from source
- Dokan only when testing vendor approval awards

For XAMPP, start Apache and MySQL, create a fresh WordPress site, install
WooCommerce, and finish the WooCommerce setup wizard before installing
PromoGuard.

## 2. Build the installable ZIP

From `promoguard-for-woocommerce`:

```powershell
composer install
npm.cmd install
npm.cmd run package
```

The package command runs the JavaScript checks and asset build, installs the
production Composer autoloader into the staging tree, and creates:

```text
dist/promoguard-for-woocommerce-0.1.0-dev.zip
```

On macOS or Linux, use `npm` instead of `npm.cmd`.

## 3. Install and activate

1. Open WordPress administration.
2. Go to **Plugins > Add New Plugin > Upload Plugin**.
3. Select the ZIP from `dist` and choose **Install Now**.
4. Activate **PromoGuard for WooCommerce**.
5. If this site previously ran a development build, deactivate and reactivate
   PromoGuard once so the My Account endpoint rewrite is refreshed.
6. Confirm no PromoGuard dependency or storage-engine error appears.

Activation creates currency-scoped account, transaction, and reservation
tables. PromoGuard declares compatibility with WooCommerce HPOS and Cart and
Checkout Blocks through WooCommerce's public feature API.

## 4. Configure awards and redemption

1. Open **WooCommerce > PromoGuard > Settings**.
2. Under **Store credit redemption**, enable **Allow signed-in customers to
   apply store credit**.
3. Under **Signup bonus**, keep customer timing at **On registration**, enter a
   test amount such as `50`, and save.
4. For Dokan, keep vendor timing at **After approval** and enter a test amount.
5. Create a new customer account. Do not reuse an account that registered
   before the rule was saved.
6. Open **My Account > Store credit** and confirm the balance uses the current
   WooCommerce currency.

The defaults are customer credit on registration and Dokan vendor credit after
approval. Signup rules, store-credit redemption, and coupon campaigns are
separate settings and data flows.

## 5. Test Classic Cart and Checkout

1. Configure a shipping method with a non-zero charge.
2. Add a taxable product to the cart while signed in as the credited customer.
3. In Cart totals, select **Apply available balance** and choose **Update**.
4. Confirm a negative **Store credit** line appears only after opt-in.
5. Confirm the applied amount is no greater than discounted merchandise plus
   merchandise tax.
6. Confirm shipping remains payable even when the balance exceeds the product
   and tax total.
7. Continue to Classic Checkout and confirm the checkbox remains explicit and
   the final payable total is never negative.
8. Clear the checkbox and confirm the credit line disappears.

## 6. Test Cart and Checkout Blocks

1. Set the Cart and Checkout pages to WooCommerce Cart and Checkout Blocks.
2. Add the same product and sign in as the credited customer.
3. Use the PromoGuard checkbox in the totals area.
4. Confirm totals refresh through the Store API without a page reload.
5. Complete an order and confirm the displayed credit equals the amount stored
   on the order.
6. Repeat with HPOS enabled under **WooCommerce > Settings > Advanced >
   Features**.

## 7. Test eligibility boundaries

Run each case separately:

- Sign out and confirm a guest sees no credit control and receives no credit
  fee.
- Use an account with a zero balance and confirm no control appears.
- Use credit held in one currency on an order in another currency and confirm
  no credit is applied.
- Disable redemption in PromoGuard settings and confirm both Classic and Block
  controls disappear without deleting the balance.
- Apply a coupon and store credit together; confirm the coupon campaign remains
  a native, separate discount and credit only covers the discounted merchandise
  and its tax.

## 8. Test reservation and payment lifecycle

1. Start checkout with credit applied but do not pay immediately.
2. In **My Account > Store credit**, confirm the amount appears as **Reserved**
   and is removed from **Available**.
3. Complete payment. Confirm the reservation disappears, the posted balance is
   debited once, and an order note records consumption.
4. Trigger the payment-complete action again or move between paid statuses.
   Confirm no second ledger debit is created.
5. Create another unpaid order with credit and cancel or fail it. Confirm the
   reservation is released and the posted balance is unchanged.

## 9. Test refunds

1. Refund only a shipping line. Confirm no store credit is restored.
2. Partially refund a product and its tax. Confirm only that eligible amount is
   restored, capped by the credit consumed on the order.
3. Repeat the same refund hook or reload the refund action. Confirm restoration
   is not duplicated.
4. Create additional partial refunds whose total exceeds the original credit.
   Confirm cumulative restoration stops at the originally consumed credit.
5. Confirm each created restoration appears once in **My Account > Store
   credit** and in the order notes.

Store credit cannot be paid out or exchanged for cash; refunds return only the
eligible credit-funded portion to the same currency account.

## 10. Test Dokan vendor defaults

1. Install and activate Dokan on the disposable site.
2. Register a new vendor and leave the vendor unapproved.
3. Confirm no vendor credit is posted before approval.
4. Approve the vendor in Dokan.
5. Confirm the configured credit is posted once.
6. Repeat the approval action and confirm the ledger has no duplicate grant.

## 11. Run automated checks

For static and unit checks:

```powershell
composer check
npm.cmd run check
npm.cmd run package
```

For disposable WordPress integration tests, start Docker Desktop, then run:

```powershell
npm.cmd run env:start
npx.cmd --yes @wordpress/env@10.30.0 run cli wp eval-file wp-content/plugins/promoguard-for-woocommerce/tests/Integration/runtime-smoke.php
npx.cmd --yes @wordpress/env@10.30.0 run cli wp eval-file wp-content/plugins/promoguard-for-woocommerce/tests/Integration/lifecycle-smoke.php
npx.cmd --yes @wordpress/env@10.30.0 run cli wp eval-file wp-content/plugins/promoguard-for-woocommerce/tests/Integration/credit-smoke.php
npm.cmd run env:stop
```

Never run the integration smoke tests against a production or existing XAMPP
database. They intentionally create customers, orders, refunds, campaigns, and
ledger records.

## 12. Expected automated credit-smoke coverage

The credit smoke test verifies:

- USD and EUR balances remain isolated;
- an unowned GBP currency cannot reserve credit;
- checkout reservation and retry are idempotent;
- consumption debits the ledger once;
- cancellation releases the reservation;
- duplicate refunds do not restore twice;
- cumulative restoration is capped at consumed credit; and
- My Account balance and ledger reads expose the resulting records.
