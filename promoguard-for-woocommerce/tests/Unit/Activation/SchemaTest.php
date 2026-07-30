<?php
/**
 * Tests for the PromoGuard schema contract.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Activation;

use PHPUnit\Framework\TestCase;
use PromoGuard\Activation\Schema;
use PromoGuard\Support\TableNames;

/** Covers the authoritative database schema contract. */
final class SchemaTest extends TestCase {
	/**
	 * Combined SQL used by focused schema assertions.
	 *
	 * @var string
	 */
	private string $schema_sql;

	/** Build a fresh schema contract for every test. */
	protected function setUp(): void {
		$statements       = ( new Schema() )->statements( new TableNames( 'wp_' ), 'DEFAULT CHARACTER SET utf8mb4' );
		$this->schema_sql = implode( "\n", $statements );

		self::assertCount( 10, $statements );
	}

	/** Every table is explicitly created with the required transactional engine. */
	public function test_tables_are_created_with_innodb(): void {
		self::assertSame( 10, substr_count( $this->schema_sql, 'ENGINE=InnoDB' ) );
		self::assertStringNotContainsString( 'FOREIGN KEY', $this->schema_sql );
	}

	/** Checkout state and usage lookups have their authoritative bounded indexes. */
	public function test_checkout_indexes_are_present(): void {
		self::assertStringContainsString( 'UNIQUE KEY campaign_customer (campaign_id, customer_id)', $this->schema_sql );
		self::assertStringContainsString( 'UNIQUE KEY order_campaign (order_id, campaign_id)', $this->schema_sql );
		self::assertStringContainsString( 'KEY campaign_customer_status (campaign_id, customer_id, status)', $this->schema_sql );
		self::assertStringContainsString( 'KEY status_expiry (status, reserved_until_gmt)', $this->schema_sql );
	}

	/** Global analytics periods use date-leading indexes instead of table scans. */
	public function test_analytics_date_indexes_are_present(): void {
		self::assertStringContainsString( 'KEY consumed_at_gmt (consumed_at_gmt)', $this->schema_sql );
		self::assertStringContainsString( 'KEY created_at_gmt (created_at_gmt)', $this->schema_sql );
	}

	/** Customer identifiers remain hashed and unique without storing raw email. */
	public function test_customer_identifier_privacy_contract(): void {
		self::assertStringContainsString( 'identifier_hash char(64) NOT NULL', $this->schema_sql );
		self::assertStringContainsString( 'UNIQUE KEY type_hash (identifier_type, identifier_hash)', $this->schema_sql );
		self::assertStringNotContainsString( 'email_address', $this->schema_sql );
	}

	/** Usage rows preserve the order/campaign lifecycle snapshot. */
	public function test_usage_lifecycle_columns_are_present(): void {
		foreach ( array( 'reserved_at_gmt', 'consumed_at_gmt', 'released_at_gmt', 'restored_at_gmt' ) as $column ) {
			self::assertStringContainsString( $column . ' datetime NULL', $this->schema_sql );
		}
	}

	/** Store-credit balance updates and idempotent grants have bounded indexes. */
	public function test_store_credit_indexes_are_present(): void {
		self::assertStringContainsString( 'UNIQUE KEY user_currency (wp_user_id, currency)', $this->schema_sql );
		self::assertStringContainsString( 'UNIQUE KEY source_reference (source, reference_key)', $this->schema_sql );
		self::assertStringContainsString( 'KEY user_created (wp_user_id, created_at_gmt)', $this->schema_sql );
		self::assertStringContainsString( 'UNIQUE KEY order_id (order_id)', $this->schema_sql );
		self::assertStringContainsString( 'KEY account_status (account_id, status)', $this->schema_sql );
		self::assertStringContainsString( 'consumed_amount decimal(26,8) NOT NULL DEFAULT 0', $this->schema_sql );
		self::assertStringContainsString( 'restored_amount decimal(26,8) NOT NULL DEFAULT 0', $this->schema_sql );
	}
}
