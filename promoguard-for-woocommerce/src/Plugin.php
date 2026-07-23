<?php
/**
 * Main plugin coordinator.
 *
 * @package PromoGuard
 */

namespace PromoGuard;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use PromoGuard\Activation\Installer;
use PromoGuard\Admin\CampaignPage;
use PromoGuard\Api\CampaignController;
use PromoGuard\Checkout\WooCommerceCheckout;
use PromoGuard\Reservation\WooCommerceOrderLifecycle;
use PromoGuard\Reservation\WooCommerceUsageExpiration;
use PromoGuard\Support\Capabilities;
use PromoGuard\Support\Options;
use PromoGuard\Support\Requirements;

/**
 * Registers the minimum hooks needed to start PromoGuard safely.
 */
final class Plugin {
	/**
	 * Register bootstrap hooks.
	 */
	public static function register(): void {
		add_action( 'before_woocommerce_init', array( self::class, 'declare_woocommerce_compatibility' ) );
		add_action( 'plugins_loaded', array( self::class, 'boot' ), 20 );
	}

	/**
	 * Start the plugin only when its runtime requirements are satisfied.
	 */
	public static function boot(): void {
		$requirements = Requirements::from_environment();

		if ( ! $requirements->is_satisfied() ) {
			add_action(
				'admin_notices',
				static function () use ( $requirements ): void {
					self::render_requirement_notice( $requirements );
				}
			);

			return;
		}

		// WooCommerce may create its Shop Manager role after PromoGuard activates.
		Capabilities::install();

		/**
		 * Fires after PromoGuard has passed dependency checks.
		 *
		 * @param string $version Active PromoGuard version.
		 */
		do_action( 'promoguard_loaded', PROMOGUARD_VERSION );
		add_action( 'rest_api_init', array( CampaignController::from_wordpress(), 'register_routes' ) );
		WooCommerceCheckout::from_wordpress()->register();
		WooCommerceOrderLifecycle::from_wordpress()->register();
		WooCommerceUsageExpiration::from_wordpress()->register();

		if ( is_admin() ) {
			CampaignPage::register();
			add_action( 'admin_init', array( Installer::class, 'maybe_upgrade' ), 5 );
			add_action( 'admin_notices', array( self::class, 'render_storage_notice' ) );
		}
	}

	/**
	 * Declare compatibility through WooCommerce's public feature API.
	 */
	public static function declare_woocommerce_compatibility(): void {
		if ( ! class_exists( FeaturesUtil::class ) ) {
			return;
		}

		FeaturesUtil::declare_compatibility( 'custom_order_tables', PROMOGUARD_PLUGIN_FILE, true );
		FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PROMOGUARD_PLUGIN_FILE, true );
	}

	/** Show a critical notice when lock-safe checkout storage is unavailable. */
	public static function render_storage_notice(): void {
		if ( Options::storage_is_supported() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'PromoGuard requires InnoDB storage for customer campaign state and usage records. Protected promotions remain unavailable until storage is repaired.', 'promoguard-for-woocommerce' )
		);
	}
	/**
	 * Show a concise dependency error to users who can manage plugins.
	 *
	 * @param Requirements $requirements Evaluated runtime requirements.
	 */
	private static function render_requirement_notice( Requirements $requirements ): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( $requirements->get_admin_message() )
		);
	}
}
