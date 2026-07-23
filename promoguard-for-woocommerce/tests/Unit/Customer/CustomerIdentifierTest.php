<?php
/**
 * Hashed customer identifier tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Customer;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PromoGuard\Customer\CustomerIdentifier;

/** Covers non-reversible identifier invariants. */
final class CustomerIdentifierTest extends TestCase {
	/** Supported MVP identifier types are explicit and bounded. */
	public function test_supports_only_user_and_email(): void {
		self::assertTrue( CustomerIdentifier::supports( CustomerIdentifier::TYPE_USER ) );
		self::assertTrue( CustomerIdentifier::supports( CustomerIdentifier::TYPE_EMAIL ) );
		self::assertFalse( CustomerIdentifier::supports( 'phone' ) );
	}

	/**
	 * Invalid identifier snapshots fail before persistence.
	 *
	 * @param array<string,mixed> $overrides Invalid property overrides.
	 */
	#[DataProvider( 'invalid_identifiers' )]
	public function test_rejects_invalid_identifier( array $overrides ): void {
		$this->expectException( InvalidArgumentException::class );
		self::identifier( $overrides );
	}

	/**
	 * Provide invalid identifier snapshots.
	 *
	 * @return array<string,array{array<string,mixed>}>
	 */
	public static function invalid_identifiers(): array {
		return array(
			'invalid customer'  => array( array( 'customer_id' => 0 ) ),
			'future type'       => array( array( 'identifier_type' => 'phone' ) ),
			'uppercase hash'    => array( array( 'identifier_hash' => str_repeat( 'A', 64 ) ) ),
			'invalid hash'      => array( array( 'identifier_hash' => 'abc' ) ),
			'last before first' => array( array( 'last_seen_at_gmt' => self::gmt( '2026-07-22 00:00:00' ) ) ),
		);
	}

	/**
	 * Build a representative identifier.
	 *
	 * @param array<string,mixed> $overrides Focused property overrides.
	 */
	private static function identifier( array $overrides = array() ): CustomerIdentifier {
		return new CustomerIdentifier(
			...array_replace(
				array(
					'id'                => 8,
					'customer_id'       => 4,
					'identifier_type'   => CustomerIdentifier::TYPE_EMAIL,
					'identifier_hash'   => str_repeat( 'a', 64 ),
					'is_primary'        => true,
					'first_seen_at_gmt' => self::gmt( '2026-07-23 00:00:00' ),
					'last_seen_at_gmt'  => self::gmt( '2026-07-23 00:00:00' ),
				),
				$overrides
			)
		);
	}

	/**
	 * Build one GMT timestamp.
	 *
	 * @param string $value Timestamp value.
	 */
	private static function gmt( string $value ): DateTimeImmutable {
		return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
	}
}
