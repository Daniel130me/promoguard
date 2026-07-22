<?php
/**
 * Plugin Name:       PromoGuard for WooCommerce
 * Description:       Groups WooCommerce coupons into campaigns with shared customer eligibility rules.
 * Version:           0.1.0-dev
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * WC requires at least: 10.8
 * WC tested up to:   10.9
 * Author:            PromoGuard Contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       promoguard-for-woocommerce
 *
 * @package PromoGuard
 */

defined( 'ABSPATH' ) || exit;

define( 'PROMOGUARD_VERSION', '0.1.0-dev' );
define( 'PROMOGUARD_PLUGIN_FILE', __FILE__ );
define( 'PROMOGUARD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

$promoguard_autoloader = PROMOGUARD_PLUGIN_DIR . 'vendor/autoload.php';

if ( ! is_readable( $promoguard_autoloader ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'PromoGuard cannot start because its Composer dependencies are missing. Install a complete plugin build or run Composer install.', 'promoguard-for-woocommerce' )
			);
		}
	);

	return;
}

require_once $promoguard_autoloader;

register_activation_hook( __FILE__, array( PromoGuard\Activation\Activator::class, 'activate' ) );

PromoGuard\Plugin::register();
