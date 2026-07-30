<?php
/**
 * WooCommerce cart and checkout store-credit integration.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Credit;

use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use Closure;
use RuntimeException;
use Throwable;
use WC_Cart;
use WC_Order_Item_Product;
use WC_Order;
use WC_Order_Item_Fee;

/** Provides explicit customer opt-in, safe fee calculation, and order reservation. */
final class StoreCreditCheckout {
	// Hook signatures use explicit scalar/object types; method summaries document
	// their WooCommerce context and transactional failure behavior.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionCommentThrowTag.Missing
	public const BLOCK_NAMESPACE  = 'promoguard-store-credit';
	private const SESSION_APPLIED = 'promoguard_store_credit_applied';
	private const SESSION_AMOUNT  = 'promoguard_store_credit_amount';
	private const ORDER_AMOUNT    = '_promoguard_credit_reserved_amount';
	private const ORDER_CURRENCY  = '_promoguard_credit_currency';

	/**
	 * Configure checkout policy and environment feature flag.
	 *
	 * @param CreditRedemptionStore    $credits    Atomic redemption persistence.
	 * @param CreditCheckoutCalculator $calculator Non-shipping amount policy.
	 * @param Closure                  $enabled    Administrator setting lookup.
	 */
	public function __construct(
		private readonly CreditRedemptionStore $credits,
		private readonly CreditCheckoutCalculator $calculator,
		private readonly Closure $enabled
	) {}

	/** Build the active WordPress/WooCommerce adapter. */
	public static function from_wordpress(): self {
		return new self(
			CreditRedemptionRepository::from_wordpress(),
			new CreditCheckoutCalculator(),
			static fn (): bool => true
		);
	}

	/** Register Classic Checkout, Store API, and Cart/Checkout Block hooks. */
	public function register(): void {
		add_action( 'wp_loaded', array( $this, 'handle_cart_toggle' ), 20 );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_fee' ), 20 );
		add_action( 'woocommerce_cart_totals_before_order_total', array( $this, 'render_cart_toggle' ) );
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'render_checkout_toggle' ), 5 );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'update_checkout_toggle' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'reserve_classic_order' ), 30, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'reserve_store_api_order' ), 30 );
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api' ) );
		add_action( 'woocommerce_blocks_cart_block_registration', array( $this, 'register_block_integration' ) );
		add_action( 'woocommerce_blocks_checkout_block_registration', array( $this, 'register_block_integration' ) );
	}

	/** Persist a nonce-protected customer choice from the Classic Cart. */
	public function handle_cart_toggle(): void {
		if ( ! isset( $_POST['promoguard_credit_toggle'] ) ) {
			return;
		}
		$nonce = isset( $_POST['promoguard_credit_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['promoguard_credit_nonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, 'promoguard_credit_toggle' ) || ! is_user_logged_in() ) {
			wc_add_notice( __( 'Store-credit preference could not be updated.', 'promoguard-for-woocommerce' ), 'error' );
			return;
		}

		$this->set_applied( isset( $_POST['promoguard_use_credit'] ) );
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	/** Add a non-taxable negative fee capped to merchandise and merchandise tax. */
	public function apply_fee( WC_Cart $cart ): void {
		if ( ! $this->customer_can_redeem() || ! $this->is_applied() ) {
			$this->set_session_amount( '0' );
			return;
		}

		$currency  = get_woocommerce_currency();
		$available = $this->credits->available_balance( get_current_user_id(), $currency );
		$amount    = $this->calculator->calculate(
			$available,
			wc_format_decimal( $cart->get_cart_contents_total(), 8 ),
			wc_format_decimal( $cart->get_cart_contents_tax(), 8 )
		);
		$this->set_session_amount( $amount );
		if ( CreditAmount::is_positive( $amount ) ) {
			$cart->add_fee( $this->fee_name(), -1 * (float) $amount, false );
		}
	}

	/** Render an explicit opt-in control in the Classic Cart totals. */
	public function render_cart_toggle(): void {
		if ( ! $this->customer_can_redeem() ) {
			return;
		}
		$currency  = get_woocommerce_currency();
		$available = $this->credits->available_balance( get_current_user_id(), $currency );
		if ( ! CreditAmount::is_positive( $available ) ) {
			return;
		}
		?>
		<tr class="promoguard-store-credit-toggle">
			<th><?php esc_html_e( 'Store credit', 'promoguard-for-woocommerce' ); ?></th>
			<td data-title="<?php esc_attr_e( 'Store credit', 'promoguard-for-woocommerce' ); ?>">
				<form method="post">
					<?php wp_nonce_field( 'promoguard_credit_toggle', 'promoguard_credit_nonce' ); ?>
					<input type="hidden" name="promoguard_credit_toggle" value="1">
					<label>
						<input type="checkbox" name="promoguard_use_credit" value="1" <?php checked( $this->is_applied() ); ?>>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: available store-credit balance. */
								__( 'Apply available balance (%s)', 'promoguard-for-woocommerce' ),
								wc_price( (float) $available, array( 'currency' => $currency ) )
							)
						);
						?>
					</label>
					<button class="button" type="submit"><?php esc_html_e( 'Update', 'promoguard-for-woocommerce' ); ?></button>
				</form>
			</td>
		</tr>
		<?php
	}

	/** Render an explicit opt-in checkbox in Classic Checkout. */
	public function render_checkout_toggle(): void {
		if ( ! $this->customer_can_redeem() ) {
			return;
		}
		$currency  = get_woocommerce_currency();
		$available = $this->credits->available_balance( get_current_user_id(), $currency );
		if ( ! CreditAmount::is_positive( $available ) ) {
			return;
		}
		?>
		<p class="form-row form-row-wide promoguard-store-credit-toggle">
			<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
				<input class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" type="checkbox" name="promoguard_use_credit" value="1" <?php checked( $this->is_applied() ); ?>>
				<span>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: available store-credit balance. */
							__( 'Apply my available store credit (%s)', 'promoguard-for-woocommerce' ),
							wc_price( (float) $available, array( 'currency' => $currency ) )
						)
					);
					?>
				</span>
			</label>
		</p>
		<?php
	}

	/** Update the session choice from serialized Classic Checkout fields. */
	public function update_checkout_toggle( string $posted_data ): void {
		parse_str( $posted_data, $data );
		$this->set_applied( is_user_logged_in() && isset( $data['promoguard_use_credit'] ) );
	}

	/**
	 * Reserve credit after Classic Checkout creates its payable order.
	 *
	 * @param int                 $order_id    WooCommerce order ID.
	 * @param array<string,mixed> $posted_data Sanitized checkout data.
	 * @param WC_Order            $order       Created order.
	 * @throws RuntimeException When order context or reservation fails.
	 */
	public function reserve_classic_order( int $order_id, array $posted_data, WC_Order $order ): void {
		unset( $posted_data );
		if ( $order_id !== $order->get_id() ) {
			throw new RuntimeException( 'Store-credit checkout order context is inconsistent.' );
		}
		$this->reserve_order( $order );
	}

	/** Reserve credit before Checkout Blocks begins payment processing. */
	public function reserve_store_api_order( WC_Order $order ): void {
		$this->reserve_order( $order );
	}

	/** Register customer-scoped Cart endpoint data and its update callback. */
	public function register_store_api(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CartSchema::IDENTIFIER,
				'namespace'       => self::BLOCK_NAMESPACE,
				'data_callback'   => array( $this, 'store_api_data' ),
				'schema_callback' => array( $this, 'store_api_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => self::BLOCK_NAMESPACE,
				'callback'  => array( $this, 'update_store_api_choice' ),
			)
		);
	}

	/**
	 * Return safe current-customer data for Cart and Checkout Blocks.
	 *
	 * @return array{authenticated:bool,enabled:bool,applied:bool,available:string,currency:string}
	 */
	public function store_api_data(): array {
		$authenticated = is_user_logged_in();
		$currency      = get_woocommerce_currency();
		$available     = $authenticated && ( $this->enabled )()
			? $this->credits->available_balance( get_current_user_id(), $currency )
			: '0';

		return array(
			'authenticated' => $authenticated,
			'enabled'       => ( $this->enabled )(),
			'applied'       => $authenticated && $this->is_applied(),
			'available'     => $available,
			'currency'      => $currency,
		);
	}

	/**
	 * Return the closed Store API extension schema.
	 *
	 * @return array<string,mixed>
	 */
	public function store_api_schema(): array {
		return array(
			'properties' => array(
				'authenticated' => array(
					'type'     => 'boolean',
					'readonly' => true,
				),
				'enabled'       => array(
					'type'     => 'boolean',
					'readonly' => true,
				),
				'applied'       => array(
					'type'     => 'boolean',
					'readonly' => true,
				),
				'available'     => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'currency'      => array(
					'type'     => 'string',
					'readonly' => true,
				),
			),
		);
	}

	/**
	 * Persist a customer choice sent through the Store API cart extension route.
	 *
	 * @param array<string,mixed> $data Store API extension request data.
	 */
	public function update_store_api_choice( array $data ): void {
		$apply = isset( $data['applied'] ) && true === filter_var( $data['applied'], FILTER_VALIDATE_BOOLEAN );
		$this->set_applied( is_user_logged_in() && $apply );
	}

	/** Register the storefront asset with a Cart or Checkout Block registry. */
	public function register_block_integration( object $registry ): void {
		if ( method_exists( $registry, 'register' ) ) {
			$registry->register( new StoreCreditBlocksIntegration() );
		}
	}

	/** Atomically reserve and reconcile the displayed order fee. */
	private function reserve_order( WC_Order $order ): void {
		$fee = $this->credit_fee( $order );
		if ( null === $fee ) {
			return;
		}
		$user_id = $order->get_customer_id();
		if ( $user_id < 1 || ! ( $this->enabled )() ) {
			$this->reconcile_order_fee( $order, $fee, '0' );
			return;
		}

		$displayed = $this->absolute_amount( (string) $fee->get_total() );
		$maximum   = CreditAmount::minimum( $displayed, $this->eligible_order_amount( $order ) );
		try {
			$reservation = $this->credits->reserve( $user_id, $order->get_id(), $maximum, $order->get_currency() );
			$amount      = null === $reservation ? '0' : $reservation->amount;
			$this->reconcile_order_fee( $order, $fee, $amount );
			if ( CreditAmount::is_positive( $amount ) ) {
				$order->update_meta_data( self::ORDER_AMOUNT, $amount );
				$order->update_meta_data( self::ORDER_CURRENCY, $reservation instanceof CreditReservation ? $reservation->currency : $order->get_currency() );
				$order->save_meta_data();
			}
			$this->set_applied( false );
		} catch ( Throwable $exception ) {
			$this->credits->release( $order->get_id() );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is diagnostic context only.
			throw new RuntimeException( 'Store credit could not be reserved. Please try again.', 0, $exception );
		}
	}

	/** Return the PromoGuard fee item from one order. */
	private function credit_fee( WC_Order $order ): ?WC_Order_Item_Fee {
		foreach ( $order->get_items( 'fee' ) as $item ) {
			if ( $item instanceof WC_Order_Item_Fee && $this->fee_name() === $item->get_name() ) {
				return $item;
			}
		}
		return null;
	}

	/** Sum discounted merchandise and its tax without shipping or fee items. */
	private function eligible_order_amount( WC_Order $order ): string {
		$total = '0';
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$total = CreditAmount::add( $total, wc_format_decimal( $item->get_total(), 8 ) );
				$total = CreditAmount::add( $total, wc_format_decimal( $item->get_total_tax(), 8 ) );
			}
		}
		return $total;
	}

	/** Reconcile the order fee to the amount won by the atomic reservation. */
	private function reconcile_order_fee( WC_Order $order, WC_Order_Item_Fee $fee, string $amount ): void {
		if ( CreditAmount::is_positive( $amount ) ) {
			$fee->set_total( '-' . $amount );
			$fee->save();
		} else {
			$order->remove_item( $fee->get_id() );
		}
		$order->calculate_totals( false );
		$order->save();
	}

	/** Normalize the magnitude of one signed WooCommerce amount. */
	private function absolute_amount( string $amount ): string {
		return CreditAmount::normalize( ltrim( wc_format_decimal( $amount, 8 ), '-' ) );
	}

	/** Whether the current request may display or calculate account credit. */
	private function customer_can_redeem(): bool {
		return ( $this->enabled )() && is_user_logged_in();
	}

	/** Return the translated order fee label. */
	private function fee_name(): string {
		return __( 'Store credit', 'promoguard-for-woocommerce' );
	}

	/** Return the customer session choice. */
	private function is_applied(): bool {
		return null !== WC()->session && true === WC()->session->get( self::SESSION_APPLIED, false );
	}

	/** Persist the customer session choice. */
	private function set_applied( bool $applied ): void {
		if ( null !== WC()->session ) {
			WC()->session->set( self::SESSION_APPLIED, $applied );
		}
	}

	/** Persist the calculated amount for diagnostics and recalculation. */
	private function set_session_amount( string $amount ): void {
		if ( null !== WC()->session ) {
			WC()->session->set( self::SESSION_AMOUNT, $amount );
		}
	}
}
