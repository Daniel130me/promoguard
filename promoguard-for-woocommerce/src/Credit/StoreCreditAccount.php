<?php
/**
 * Customer My Account store-credit integration.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Credit;

/** Registers and renders the customer-owned balance and ledger endpoint. */
final class StoreCreditAccount {
	// Typed arguments and focused summaries document this WooCommerce adapter.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag

	public const ENDPOINT = 'store-credit';

	/** Configure the account read model. */
	public function __construct( private readonly CreditAccountViewRepository $accounts ) {}

	/** Build the production WooCommerce adapter. */
	public static function from_wordpress(): self {
		return new self( CreditAccountViewRepository::from_wordpress() );
	}

	/** Register rewrite, navigation, and endpoint rendering hooks. */
	public function register(): void {
		add_action( 'init', array( self::class, 'add_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_items' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
	}

	/** Register the public My Account rewrite endpoint. */
	public static function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Insert Store credit before WooCommerce's logout action.
	 *
	 * @param array<string,string> $items Existing My Account menu items.
	 * @return array<string,string>
	 */
	public function menu_items( array $items ): array {
		$logout = $items['customer-logout'] ?? null;
		unset( $items['customer-logout'] );
		$items[ self::ENDPOINT ] = __( 'Store credit', 'promoguard-for-woocommerce' );
		if ( is_string( $logout ) ) {
			$items['customer-logout'] = $logout;
		}

		return $items;
	}

	/** Render balances and the current bounded ledger page for the signed-in user. */
	public function render(): void {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination changes no server state.
		$page   = isset( $_GET['credit-page'] ) ? absint( wp_unslash( $_GET['credit-page'] ) ) : 1;
		$page   = max( 1, $page );
		$ledger = $this->accounts->ledger( $user_id, $page );
		wc_get_template(
			'myaccount/store-credit.php',
			array(
				'balances'     => $this->accounts->balances( $user_id ),
				'entries'      => $ledger['entries'],
				'page'         => $ledger['page'],
				'has_previous' => $ledger['has_previous'],
				'has_next'     => $ledger['has_next'],
				'endpoint_url' => wc_get_account_endpoint_url( self::ENDPOINT ),
			),
			'',
			plugin_dir_path( PROMOGUARD_PLUGIN_FILE ) . 'templates/'
		);
	}
}
