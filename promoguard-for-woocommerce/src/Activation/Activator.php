<?php
/**
 * PromoGuard activation callback.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Activation;

use PromoGuard\Credit\StoreCreditAccount;


/** Installs plugin-owned foundations without WooCommerce internals. */
final class Activator {
	/** Run the idempotent installer during plugin activation. */
	public static function activate(): void {
		Installer::install();
		StoreCreditAccount::add_endpoint();
		flush_rewrite_rules( false );
	}
}
