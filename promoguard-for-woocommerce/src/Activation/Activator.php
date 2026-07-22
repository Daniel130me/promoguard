<?php
/**
 * PromoGuard activation callback.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Activation;

/** Installs plugin-owned foundations without WooCommerce internals. */
final class Activator {
	/** Run the idempotent installer during plugin activation. */
	public static function activate(): void {
		Installer::install();
	}
}
