<?php
/**
 * Identity resolution result tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Customer;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PromoGuard\Customer\Customer;
use PromoGuard\Customer\IdentityResolution;

/** Covers safe resolution-result invariants. */
final class IdentityResolutionTest extends TestCase {
	/** Conflict is explicit while retaining the authoritative customer. */
	public function test_conflict_retains_authoritative_customer(): void {
		$resolution = new IdentityResolution( self::customer(), IdentityResolution::OUTCOME_CONFLICT );

		self::assertTrue( $resolution->has_conflict() );
		self::assertNotNull( $resolution->customer );
		self::assertSame( 4, $resolution->customer->id );
	}

	/** Missing identity never contains a customer. */
	public function test_missing_identity_has_no_customer(): void {
		$resolution = new IdentityResolution( null, IdentityResolution::OUTCOME_MISSING );

		self::assertNull( $resolution->customer );
		self::assertFalse( $resolution->has_conflict() );
	}

	/** Inconsistent outcomes fail before they reach eligibility logic. */
	public function test_rejects_missing_outcome_with_customer(): void {
		$this->expectException( InvalidArgumentException::class );
		new IdentityResolution( self::customer(), IdentityResolution::OUTCOME_MISSING );
	}

	/** Build a representative customer. */
	private static function customer(): Customer {
		$now = new DateTimeImmutable( '2026-07-23 00:00:00', new DateTimeZone( 'UTC' ) );

		return new Customer( 4, 7, null, $now, $now );
	}
}
