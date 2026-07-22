# Testing and Environment Isolation

## Safety rule

Never run automated order, refund, uninstall, migration, or concurrency tests
against an existing WordPress database. The repository's `.wp-env.json` creates
disposable Docker-backed development and test sites for integration work.

## Local audit (2026-07-22)

| Tool | Result |
|---|---|
| PHP | 8.2.12 |
| Composer | 2.10.1 |
| Node.js | 24.16.0 |
| npm | Installed; invoke `npm.cmd` because local PowerShell blocks `npm.ps1` |
| Git | 2.54.0.windows.1 |
| Docker | CLI 29.6.1 installed; daemon unavailable during Phase 0 verification |
| WP-CLI | Not installed globally; use the isolated environment's CLI |

Start Docker before running `npm run env:start`. Confirm both development and
test containers before any
integration suite.

## Commands

```sh
composer check
npm run check
npm run env:start
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

## Phase 1 verification

`composer check` passes syntax validation, WordPress coding standards, PHPStan,
and 20 unit tests with 79 assertions. Unit coverage includes table naming, all
seven schema definitions and indexes, option initialization, role capability
policy, and uninstall scope safeguards.

Activation, migration idempotency, storage-engine inspection, and opt-in uninstall
must still be exercised against the disposable WordPress database. Those checks
remain pending because the Docker daemon is unavailable; the existing XAMPP
database has not been touched.
