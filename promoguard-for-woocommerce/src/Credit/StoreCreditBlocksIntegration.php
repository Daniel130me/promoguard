<?php
/**
 * Cart and Checkout Blocks storefront asset integration.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Credit;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

/** Registers the store-credit opt-in SlotFill for Cart and Checkout Blocks. */
final class StoreCreditBlocksIntegration implements IntegrationInterface {
	private const SCRIPT_HANDLE = 'promoguard-store-credit-blocks';

	/** Return the integration namespace. */
	public function get_name(): string {
		return StoreCreditCheckout::BLOCK_NAMESPACE;
	}

	/** Register the generated storefront script and style. */
	public function initialize(): void {
		$directory    = plugin_dir_path( PROMOGUARD_PLUGIN_FILE ) . 'assets/build/';
		$url          = plugin_dir_url( PROMOGUARD_PLUGIN_FILE ) . 'assets/build/';
		$metadata     = is_readable( $directory . 'store-credit.asset.php' )
			? require $directory . 'store-credit.asset.php'
			: array(
				'dependencies' => array( 'wp-element', 'wp-i18n', 'wp-plugins', 'wc-blocks-checkout' ),
				'version'      => PROMOGUARD_VERSION,
			);
		$dependencies = is_array( $metadata ) && isset( $metadata['dependencies'] ) && is_array( $metadata['dependencies'] )
			? array_values( array_filter( $metadata['dependencies'], 'is_string' ) )
			: array();
		$version      = is_array( $metadata ) && isset( $metadata['version'] ) && is_string( $metadata['version'] )
			? $metadata['version']
			: PROMOGUARD_VERSION;

		wp_register_script(
			self::SCRIPT_HANDLE,
			$url . 'store-credit.js',
			$dependencies,
			$version,
			true
		);
		wp_set_script_translations( self::SCRIPT_HANDLE, 'promoguard-for-woocommerce' );
		wp_enqueue_style(
			self::SCRIPT_HANDLE,
			$url . 'store-credit.css',
			array(),
			$version
		);
	}

	/** Return storefront script handles. */
	public function get_script_handles(): array {
		return array( self::SCRIPT_HANDLE );
	}

	/** The storefront control is not needed inside the block editor. */
	public function get_editor_script_handles(): array {
		return array();
	}

	/**
	 * Endpoint extension data is supplied by the Store API response.
	 *
	 * @return array<string,mixed>
	 */
	public function get_script_data(): array {
		return array();
	}
}
