# Testing and Environment Isolation

## Safety rule

Never run automated order, refund, uninstall, migration, or concurrency tests
against an existing WordPress database. The repository's `.wp-env.json` creates
disposable Docker-backed development and test sites for integration work.

## Local audit (2026-07-23)

| Tool | Result |
|---|---|
| PHP | 8.2.12 |
| Composer | 2.10.1 |
| Node.js | 24.16.0 |
| npm | Installed; invoke `npm.cmd` because local PowerShell blocks `npm.ps1` |
| Git | 2.54.0.windows.1 |
| Docker | CLI/server 29.6.1; disposable wp-env runtime verified |
| WP-CLI | Not installed globally; use the isolated environment's CLI |

Start Docker before running `npm run env:start`. Confirm both development and
test containers before any
integration suite.

## Commands

```sh
composer check
npm run check
npm run env:start
npx --yes @wordpress/env@10.30.0 run cli wp eval-file wp-content/plugins/promoguard-for-woocommerce/tests/Integration/runtime-smoke.php
npx --yes @wordpress/env@10.30.0 run cli wp eval-file wp-content/plugins/promoguard-for-woocommerce/tests/Integration/lifecycle-smoke.php
npm run env:stop
```

On Windows PowerShell with script execution disabled, replace `npm` with
`npm.cmd`.

## Compatibility policy

The initial verified target is WordPress 7.0.2, WooCommerce 10.9.4, and PHP 8.3.
The declared range is WordPress 6.9-7.0, WooCommerce 10.8-10.9, and PHP 8.1-8.4.
The full HPOS/legacy and Classic/Block Checkout matrix is added alongside the
integration harness; no unsupported combination is inferred from a passing
lint-only job.

## Runtime verification

`composer check` passes syntax validation, WordPress coding standards, PHPStan,
and 134 unit tests with 358 assertions. `npm run check` passes JavaScript syntax
and the deterministic asset build.

Phase 6 unit and static coverage includes shared checkout/admin reservation,
request-cache race bypass, lifecycle status routing, bounded expiration, and
snapshot-backed transitions. The dedicated lifecycle smoke scenario is ready but
its 2026-07-23 wp-env run is pending because api.wordpress.org DNS resolution
failed before any disposable containers started. The XAMPP database was not used.

The integration smoke test passes on WordPress 7.0.2, WooCommerce 10.9.4, and PHP
8.3. It verifies activation metadata, seven InnoDB tables, migration idempotency,
role capabilities, anonymous REST denial, campaign and coupon creation, atomic
assignment and explicit reassignment, legacy empty-settings compatibility,
assignment listing, archive immutability, safe Draft deletion, and preservation
of the native WooCommerce coupon. The existing XAMPP database is never used.

The campaign administration browser workflow is also verified at desktop and a
375 x 812 viewport. It covers lifecycle mutations, lazy assignment loading,
keyboard coupon search, attach/detach metadata, archived read-only controls, and
a clean console after reload.
