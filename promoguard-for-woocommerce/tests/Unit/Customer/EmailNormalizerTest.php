<?php
/**
 * Customer email normalization tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Customer;

use PHPUnit\Framework\TestCase;
use PromoGuard\Customer\EmailNormalizer;

/** Covers conservative email normalization. */
final class EmailNormalizerTest extends TestCase {
	/** Valid email is trimmed and lowercased without provider alias rewriting. */
	public function test_normalizes_valid_email_without_alias_rules(): void {
		$normalizer = new EmailNormalizer();

		self::assertSame( 'person+offer@example.com', $normalizer->normalize( ' Person+Offer@Example.COM ' ) );
	}

	/** Invalid or empty input cannot become an identifier. */
	public function test_rejects_invalid_email(): void {
		$normalizer = new EmailNormalizer();

		self::assertNull( $normalizer->normalize( '' ) );
		self::assertNull( $normalizer->normalize( 'not-an-email' ) );
	}
}
