<?php
/**
 * Tests for site-specific PromoGuard table names.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use PromoGuard\Support\TableNames;

/** Covers site prefixing and transactional table selection. */
final class TableNamesTest extends TestCase {
	/** Table names include both the WordPress and plugin prefixes. */
	public function test_names_are_scoped_to_the_site_prefix(): void {
		$tables = new TableNames( 'tenant_7_' );

		self::assertSame( 'tenant_7_promoguard_campaigns', $tables->campaigns() );
		self::assertSame( 'tenant_7_promoguard_usages', $tables->usages() );
		self::assertSame( 'tenant_7_promoguard_credit_accounts', $tables->credit_accounts() );
		self::assertSame( 'tenant_7_promoguard_credit_transactions', $tables->credit_transactions() );
		self::assertCount( 9, array_unique( $tables->all() ) );
	}

	/** Credit accounts and their ledger join checkout state in transactional storage. */
	public function test_transactional_tables_are_bounded(): void {
		$tables = new TableNames( 'wp_' );

		self::assertSame(
			array(
				'wp_promoguard_customer_campaign_state',
				'wp_promoguard_usages',
				'wp_promoguard_credit_accounts',
				'wp_promoguard_credit_transactions',
			),
			$tables->transactional()
		);
	}
}
