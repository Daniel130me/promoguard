<?php
/**
 * Tests for PromoGuard role capabilities.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use PromoGuard\Support\Capabilities;

/** Covers least-privilege capability assignments. */
final class CapabilitiesTest extends TestCase {
	/** Administrators receive the complete unique capability set. */
	public function test_administrator_capabilities_are_complete(): void {
		self::assertCount( 4, array_unique( Capabilities::administrator() ) );
		self::assertContains( Capabilities::MANAGE_SETTINGS, Capabilities::administrator() );
	}

	/** Shop Managers cannot change settings or uninstall data. */
	public function test_shop_manager_capabilities_exclude_settings(): void {
		self::assertNotContains( Capabilities::MANAGE_SETTINGS, Capabilities::shop_manager() );
		self::assertContains( Capabilities::MANAGE_CAMPAIGNS, Capabilities::shop_manager() );
		self::assertContains( Capabilities::VIEW_REPORTS, Capabilities::shop_manager() );
		self::assertContains( Capabilities::RUN_TOOLS, Capabilities::shop_manager() );
	}
}
