<?php
/**
 * Customer identifier hashing tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Customer;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PromoGuard\Customer\CustomerIdentifier;
use PromoGuard\Customer\IdentifierHasher;

/** Covers deterministic, domain-separated identifier hashing. */
final class IdentifierHasherTest extends TestCase {
	private const KEY = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

	/** The same normalized identifier is stable while types remain separated. */
	public function test_hashes_are_deterministic_and_type_separated(): void {
		$hasher = new IdentifierHasher( self::KEY );

		$email_hash = $hasher->hash( CustomerIdentifier::TYPE_EMAIL, '7@example.com' );
		self::assertSame( $email_hash, $hasher->hash( CustomerIdentifier::TYPE_EMAIL, '7@example.com' ) );
		self::assertNotSame( $email_hash, $hasher->hash( CustomerIdentifier::TYPE_USER, '7@example.com' ) );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $email_hash );
	}

	/** Invalid keys and unsupported identifiers fail closed. */
	public function test_rejects_invalid_inputs(): void {
		$this->expectException( InvalidArgumentException::class );
		new IdentifierHasher( 'short' );
	}

	/** Empty normalized values are never hashed. */
	public function test_rejects_empty_value(): void {
		$this->expectException( InvalidArgumentException::class );
		( new IdentifierHasher( self::KEY ) )->hash( CustomerIdentifier::TYPE_EMAIL, '' );
	}
}
