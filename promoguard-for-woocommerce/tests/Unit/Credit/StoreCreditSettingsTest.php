<?php
/**
 * Store-credit settings tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Credit;

use PHPUnit\Framework\TestCase;
use PromoGuard\Credit\StoreCreditSettings;

/** Verifies safe defaults and closed boolean parsing. */
final class StoreCreditSettingsTest extends TestCase {
	/** Redemption remains enabled for existing installations without an option. */
	public function test_defaults_enable_redemption(): void {
		self::assertTrue( StoreCreditSettings::defaults()->redemption_enabled );
	}

	/** Only a literal boolean true enables redemption. */
	public function test_from_array_does_not_coerce_truthy_values(): void {
		self::assertFalse( StoreCreditSettings::from_array( array( 'redemption_enabled' => 'true' ) )->redemption_enabled );
	}

	/** The transport representation contains only redemption policy. */
	public function test_to_array_is_closed(): void {
		self::assertSame(
			array( 'redemption_enabled' => false ),
			( new StoreCreditSettings( false ) )->to_array()
		);
	}
}
