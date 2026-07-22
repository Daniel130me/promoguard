<?php
/**
 * Tests for runtime requirement evaluation.
 *
 * @package PromoGuard\Tests\Unit\Support
 */

namespace PromoGuard\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PromoGuard\Support\Requirements;

/**
 * Covers supported, missing, and out-of-range dependencies.
 */
final class RequirementsTest extends TestCase {
	/** A supported environment passes all checks. */
	public function test_supported_environment_is_accepted(): void {
		$requirements = Requirements::evaluate( '8.3.7', '7.0.2', '10.9.4' );

		self::assertTrue( $requirements->is_satisfied() );
	}

	/** Missing WooCommerce returns a safe inactive result. */
	public function test_missing_woocommerce_fails_safely(): void {
		$requirements = Requirements::evaluate( '8.3.7', '7.0.2', null );

		self::assertFalse( $requirements->is_satisfied() );
		self::assertStringContainsString( 'WooCommerce must be installed and active', $requirements->get_admin_message() );
	}

	/**
	 * Provide dependency versions outside the supported range.
	 *
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function unsupported_version_provider(): array {
		return array(
			'old PHP'            => array( '8.0.30', '7.0.2', '10.9.4', 'PHP 8.1 or newer' ),
			'future PHP'         => array( '8.5.0', '7.0.2', '10.9.4', 'PHP versions later than 8.4' ),
			'old WordPress'      => array( '8.3.7', '6.8.6', '10.9.4', 'WordPress 6.9 or newer' ),
			'future WordPress'   => array( '8.3.7', '8.0.0', '10.9.4', 'WordPress versions later than 7.0' ),
			'old WooCommerce'    => array( '8.3.7', '7.0.2', '10.7.0', 'WooCommerce 10.8 or newer' ),
			'future WooCommerce' => array( '8.3.7', '7.0.2', '11.0.0', 'WooCommerce versions later than 10.9' ),
		);
	}

	/**
	 * Versions outside the verified range are rejected.
	 *
	 * @param string $php_version         Active PHP version.
	 * @param string $wordpress_version   Active WordPress version.
	 * @param string $woocommerce_version Active WooCommerce version.
	 * @param string $expected_message    Expected administrator message fragment.
	 */
	#[DataProvider( 'unsupported_version_provider' )]
	public function test_unsupported_versions_are_rejected(
		string $php_version,
		string $wordpress_version,
		string $woocommerce_version,
		string $expected_message
	): void {
		$requirements = Requirements::evaluate( $php_version, $wordpress_version, $woocommerce_version );

		self::assertFalse( $requirements->is_satisfied() );
		self::assertStringContainsString( $expected_message, $requirements->get_admin_message() );
	}
}
