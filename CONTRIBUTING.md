# Contributing to PromoGuard

Thank you for helping improve PromoGuard for WooCommerce.

## Before starting

1. Search existing issues and pull requests to avoid duplicate work.
2. Open an issue before a large behavioral or architectural change.
3. Keep each pull request focused on one problem.
4. Never use a production or existing store database for automated tests.

## Local setup

Development commands run from `promoguard-for-woocommerce/`:

```sh
cd promoguard-for-woocommerce
composer install
npm ci
```

The supported development ranges are documented in the root
[README.md](README.md). Use the locked dependency files rather than updating
dependencies as part of an unrelated change.

## Quality checks

Run the relevant checks before opening a pull request:

```sh
composer check
npm run check
```

For changes involving WordPress, WooCommerce, database migrations, checkout,
orders, refunds, or scheduled actions, also use the disposable integration
environment described in
[`docs/TESTING.md`](promoguard-for-woocommerce/docs/TESTING.md).

## Development standards

- Follow the WordPress Coding Standards and the existing project structure.
- Keep business rules out of REST controllers, views, and browser scripts.
- Use WooCommerce public CRUD APIs for orders and refunds.
- Prepare SQL and restrict direct queries to indexed PromoGuard-owned tables.
- Preserve authorization, nonce, validation, sanitization, and escaping checks.
- Keep checkout and customer-facing queries bounded; do not scan order history.
- Add or update tests for behavior changes.
- Explain non-obvious concurrency, lifecycle, or identity logic in comments.
- Update user-facing documentation and the changelog when behavior changes.

## Pull requests

A pull request should include:

- A concise problem and solution summary
- Testing performed and exact results
- Security, privacy, performance, and compatibility considerations
- Screenshots for visible administration or storefront changes
- Migration and rollback notes when storage changes

Do not include generated archives, dependency directories, local databases,
logs, credentials, or environment-specific configuration.

By contributing, you agree that your contribution is licensed under the
project's GPL-2.0-or-later license.
