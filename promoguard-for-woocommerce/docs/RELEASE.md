# Release Process

PromoGuard release archives are generated artifacts. Build them from a clean
checkout instead of assembling plugin files manually.

## Build

Install the locked development dependencies, run every local quality gate, and
create the production archive:

```sh
composer install
npm ci
composer check
npm run package
```

`npm run package` rebuilds the administration assets, copies only distribution
files, installs the locked production Composer dependencies with an
authoritative autoloader, verifies the staging tree, and writes the ZIP to
`dist/`. The command prints the archive's SHA-256 checksum.

The archive intentionally includes `composer.json` and `composer.lock` for
dependency and source transparency. Development packages are not installed in
the bundled `vendor` directory.

## Artifact verification

Validate the archive itself rather than only the source checkout:

1. Extract it into a clean, disposable WordPress installation.
2. Activate WooCommerce and then the extracted PromoGuard build.
3. Confirm activation creates seven InnoDB tables and reports no fatal errors.
4. Run the WordPress Plugin Check plugin against the installed artifact.
5. Run the runtime and lifecycle smoke tests documented in
   [TESTING.md](TESTING.md).
6. Compare the shipped asset version with the version printed by
   `npm run build`.

Plugin Check may conservatively report direct-database warnings for PromoGuard
repositories. These calls operate on plugin-owned tables and are either
prepared, fixed transaction statements, or use validated plugin table names.
Each intentional call carries a focused PHPCS justification. Treat any new or
unannotated finding as a release blocker.

## Release checklist

- The working tree is clean and all intended changes are committed.
- `CHANGELOG.md`, `readme.txt`, and the plugin header use the same version.
- `composer check`, `npm run check`, and both isolated smoke suites pass.
- Plugin Check reports no errors against the packaged build.
- The generated ZIP contains no tests, development tools, caches, or
  `node_modules`.
- The final SHA-256 checksum is recorded with the release artifact.
- The remote compatibility workflow passes before the private beta is promoted.

Never run the acceptance smoke suites against an existing store database. They
create and mutate campaigns, coupons, orders, refunds, users, and plugin-owned
records.
