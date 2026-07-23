<?php
/**
 * Internal customer tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Customer;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PromoGuard\Customer\Customer;

/** Covers customer identity invariants. */
final class CustomerTest extends TestCase {
	/** Guest, authenticated, and merged identities remain explicit. */
	public function test_identity_states_are_explicit(): void {
		self::assertTrue( self::customer()->is_guest() );
		self::assertFalse( self::customer( array( 'wp_user_id' => 7 ) )->is_guest() );
		self::assertTrue( self::customer( array( 'merged_into_customer_id' => 9 ) )->is_merged() );
	}

	/**
	 * Invalid customer snapshots fail before persistence.
	 *
	 * @param array<string,mixed> $overrides Invalid property overrides.
	 */
	#[DataProvider( 'invalid_customers' )]
	public function test_rejects_invalid_customer( array $overrides ): void {
		$this->expectException( InvalidArgumentException::class );
		self::customer( $overrides );
	}

	/**
	 * Provide invalid customer snapshots.
	 *
	 * @return array<string,array{array<string,mixed>}>
	 */
	public static function invalid_customers(): array {
		return array(
			'non-positive user'          => array( array( 'wp_user_id' => 0 ) ),
			'self merge'                 => array(
				array(
					'id'                      => 9,
					'merged_into_customer_id' => 9,
				),
			),
			'authenticated tombstone'    => array(
				array(
					'wp_user_id'              => 7,
					'merged_into_customer_id' => 9,
				),
			),
			'non-GMT timestamp'          => array( array( 'updated_at_gmt' => new DateTimeImmutable( '2026-07-23 12:00:00', new DateTimeZone( 'Africa/Lagos' ) ) ) ),
			'backward update chronology' => array( array( 'updated_at_gmt' => self::gmt( '2026-07-22 00:00:00' ) ) ),
		);
	}

	/**
	 * Build a representative customer.
	 *
	 * @param array<string,mixed> $overrides Focused property overrides.
	 */
	private static function customer( array $overrides = array() ): Customer {
		return new Customer(
			...array_replace(
				array(
					'id'                      => 4,
					'wp_user_id'              => null,
					'merged_into_customer_id' => null,
					'created_at_gmt'          => self::gmt( '2026-07-23 00:00:00' ),
					'updated_at_gmt'          => self::gmt( '2026-07-23 00:00:00' ),
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
