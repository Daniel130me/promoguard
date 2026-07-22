<?php
/**
 * Tests for the uninstall deletion boundary.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Activation;

use PHPUnit\Framework\TestCase;
use PromoGuard\Support\Options;
use PromoGuard\Support\TableNames;

/** Covers the exact data ownership boundary used by uninstall. */
final class UninstallerTest extends TestCase {
	/** Every removable table excludes WordPress and WooCommerce storage. */
	public function test_table_deletion_boundary_is_plugin_scoped(): void {
		foreach ( ( new TableNames( 'wp_' ) )->all() as $table_name ) {
			self::assertStringStartsWith( 'wp_promoguard_', $table_name );
			self::assertStringNotContainsString( 'posts', $table_name );
			self::assertStringNotContainsString( 'orders', $table_name );
		}
	}

	/** Every removable option remains inside the PromoGuard namespace. */
	public function test_option_deletion_boundary_is_plugin_scoped(): void {
		foreach ( Options::names() as $option_name ) {
			self::assertStringStartsWith( 'promoguard_', $option_name );
		}
	}
}
