<?php
/**
 * Unit-test bootstrap.
 *
 * @package PromoGuard
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! function_exists( '__' ) ) {
	/**
	 * Minimal translation fallback for isolated unit tests.
	 *
	 * @param string $text Source text.
	 */
	function __( string $text ): string {
		return $text;
	}
}
