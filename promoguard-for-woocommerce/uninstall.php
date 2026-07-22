<?php
/**
 * PromoGuard uninstall entry point.
 *
 * Data removal is intentionally deferred until the opt-in uninstall policy is
 * implemented and tested. Deactivation and uninstall therefore preserve data.
 *
 * @package PromoGuard
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$promoguard_autoloader = __DIR__ . '/vendor/autoload.php';

// An incomplete package must preserve data rather than risk a partial cleanup.
if ( ! is_readable( $promoguard_autoloader ) ) {
	return;
}

require_once $promoguard_autoloader;

PromoGuard\Activation\Uninstaller::uninstall();
