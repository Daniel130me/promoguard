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

		self::assertCount( 7, $statements );
	}

	/** Every table is explicitly created with the required transactional engine. */
	public function test_tables_are_created_with_innodb(): void {
		self::assertSame( 7, substr_count( $this->schema_sql, 'ENGINE=InnoDB' ) );
		self::assertStringNotContainsString( 'FOREIGN KEY', $this->schema_sql );
	}

	/** Checkout state and usage lookups have their authoritative bounded indexes. */
	public function test_checkout_indexes_are_present(): void {
		self::assertStringContainsString( 'UNIQUE KEY campaign_customer (campaign_id, customer_id)', $this->schema_sql );
		self::assertStringContainsString( 'UNIQUE KEY order_campaign (order_id, campaign_id)', $this->schema_sql );
		self::assertStringContainsString( 'KEY campaign_customer_status (campaign_id, customer_id, status)', $this->schema_sql );
		self::assertStringContainsString( 'KEY status_expiry (status, reserved_until_gmt)', $this->schema_sql );
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
}
