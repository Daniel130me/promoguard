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
and 182 unit tests with 500 assertions. `npm run check` passes JavaScript syntax
and the deterministic asset build.

Phase 8 unit and static coverage includes shared checkout/admin reservation,
request-cache race bypass, lifecycle status routing, bounded expiration,
snapshot-backed transitions, cumulative refund restoration, and bounded
reconciliation. It also covers indexing job persistence and lifecycle controls,
targeted and paginated order batches, safe identity outcomes, idempotent imports,
and superseded Action Scheduler callbacks.

The integration smoke test passes on WordPress 7.0.2, WooCommerce 10.9.4, and PHP
8.3. It verifies activation metadata, seven InnoDB tables, migration idempotency,
role capabilities, anonymous REST denial, campaign and coupon creation, atomic
assignment and explicit reassignment, legacy empty-settings compatibility,
assignment listing, archive immutability, safe Draft deletion, and preservation
of the native WooCommerce coupon. Phase 9 coverage also verifies anonymous
administration denial, Shop Manager report/tool access without settings access,
bounded history pagination, dashboard and storage-health responses, safe
settings persistence, and indexing input limits. Phase 10 coverage adds real
EUR/USD WooCommerce revenue and averages, distinct order/customer reconciliation,
paginated campaign breakdowns, shared cache invalidation, and deleted-order
tolerance. The existing XAMPP database is never used.

Phase 11 runtime coverage verifies WordPress personal-data export and atomic
identifier unlinking, anonymous accounting retention, indexed 365-day decision
cleanup, privacy-setting authorization, HMAC-key and error redaction, stored
markup sanitization, and exact prepared handling of injection-style filters.
The existing XAMPP database is never used.

The Phase 7 lifecycle smoke test also passes on the pinned environment. It
verifies partial refunds retain usage, a cumulative full refund restores it
exactly once, corrupted aggregate state is rebuilt from usage rows, and both
expiration and reconciliation jobs are registered with Action Scheduler.

The Phase 8 historical indexing lifecycle smoke test passes on the same isolated
environment. Targeted indexing through `wp promoguard index start` completed
idempotently, and a full-history run completed in three bounded batches with 25
orders processed, 3 imported, 22 skipped, and 0 failed. Pause, resume, retry,
restart, persisted status reporting, and stale scheduled callbacks are covered.

The campaign administration browser workflow is also verified at desktop and a
375 x 812 viewport. It covers lifecycle mutations, lazy assignment loading,
keyboard coupon search, attach/detach metadata, archived read-only controls, and
a clean console after reload.

Phase 9 browser verification passes at desktop and a 375 x 812 viewport. It
covers capability-aware Administrator and Shop Manager navigation, lazy
dashboard/history/settings/tool loading, responsive report cards without
horizontal overflow, filter and pagination states, uninstall-consent messaging,
indexing state actions, visible keyboard focus, locale-aware currency output,
and a clean console.
