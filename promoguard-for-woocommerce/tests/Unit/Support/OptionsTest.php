<?php
/**
 * Tests for PromoGuard option ownership.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use PromoGuard\Support\Options;

/** Covers the bounded option and health metadata contract. */
final class OptionsTest extends TestCase {
	/** All authoritative option names are unique and plugin-prefixed. */
	public function test_option_names_are_bounded_and_prefixed(): void {
		self::assertCount( 11, array_unique( Options::names() ) );

		foreach ( Options::names() as $option_name ) {
			self::assertStringStartsWith( 'promoguard_', $option_name );
		}
	}

	/** Storage health defaults to unknown rather than an unsafe optimistic value. */
	public function test_storage_health_defaults_to_unknown(): void {
		self::assertSame(
			array(
				'storage_engine_supported'      => null,
				'storage_engine_checked_at_gmt' => null,
			),
			Options::default_settings()
		);
	}
}
