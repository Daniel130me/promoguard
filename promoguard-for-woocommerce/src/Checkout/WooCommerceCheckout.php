<?php
/**
 * WooCommerce checkout integration.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Checkout;

use DateTimeImmutable;
use DateTimeZone;
use PromoGuard\Application\EligibilityService;
use PromoGuard\Campaign\CampaignRepository;
use PromoGuard\Decision\DecisionRepository;
use PromoGuard\Decision\DenialLogger;
use PromoGuard\Promotion\CampaignPromotionRepository;
use PromoGuard\Reservation\ReservationRepository;
use PromoGuard\Reservation\ReservationRequestFactory;
use PromoGuard\Reservation\ReservationService;
use PromoGuard\Support\TableNames;
use RuntimeException;
use Throwable;
use WC_Coupon;
use WC_Order;
use WP_Error;
use WP_REST_Request;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Public WooCommerce hook signatures include required context arguments.
/** Adapts Classic and Store API checkout hooks to the shared validator. */
final class WooCommerceCheckout {
	/**
	 * Latest coupon decisions used to replace WooCommerce's generic filter error.
	 *
	 * @var array<int,CheckoutEvaluation>
	 */
	private array $latest = array();

	/**
	 * Configure checkout validation and denial diagnostics.
	 *
	 * @param CheckoutValidator         $validator    Request-cached validator.
	 * @param DenialLogger              $denials      Request-deduplicated denial logger.
	 * @param ReservationService        $reservations Atomic reservation service.
	 * @param ReservationRequestFactory $requests     Reservation request factory.
	 * @param string                    $request_id   Request correlation ID.
	 */
	public function __construct(
		private readonly CheckoutValidator $validator,
		private readonly DenialLogger $denials,
		private readonly ReservationService $reservations,
		private readonly ReservationRequestFactory $requests,
		private readonly string $request_id
	) {}

	/** Build the production checkout adapter for the active site. */
	public static function from_wordpress(): self {
		$tables = TableNames::from_wordpress();

		return new self(
			new CheckoutValidator(
				new CheckoutTargetResolver(
					new CampaignPromotionRepository( $tables ),
					new CampaignRepository( $tables )
				),
				EligibilityService::from_wordpress()
			),
			new DenialLogger( new DecisionRepository( $tables ) ),
			new ReservationService( new ReservationRepository( $tables ) ),
			new ReservationRequestFactory(),
			wp_generate_uuid4()
		);
	}

	/** Register validation and message hooks for Classic and Store API checkout. */
	public function register(): void {
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'filter_coupon_is_valid' ), 10, 3 );
		add_filter( 'woocommerce_coupon_error', array( $this, 'filter_coupon_error' ), 10, 3 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_classic_checkout' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'validate_store_api_checkout' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'reserve_classic_order' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'reserve_store_api_order' ), 20 );
	}

	/**
	 * Apply provisional policy during WooCommerce coupon validation.
	 *
	 * @param bool      $valid     WooCommerce's existing validation result.
	 * @param WC_Coupon $coupon    Coupon being validated.
	 * @param mixed     $discounts WooCommerce discounts context.
	 */
	public function filter_coupon_is_valid( bool $valid, WC_Coupon $coupon, mixed $discounts = null ): bool {
		if ( ! $valid || $coupon->get_id() < 1 ) {
			return $valid;
		}

		$now_gmt    = $this->now_gmt();
		$evaluation = $this->validator->evaluate(
			$coupon->get_id(),
			$this->cart_coupon_ids(),
			$this->current_user_id(),
			$this->current_billing_email(),
			$now_gmt,
			true
		);

		if ( null === $evaluation ) {
			return $valid;
		}

		$this->latest[ $coupon->get_id() ] = $evaluation;
		$this->denials->record(
			$evaluation,
			$this->request_id,
			DenialLogger::CONTEXT_COUPON_VALIDATION,
			$now_gmt,
			coupon_code: $coupon->get_code()
		);

		return $evaluation->decision->allowed;
	}

	/**
	 * Replace the generic filtered-coupon error with the policy message.
	 *
	 * @param string    $message    Existing WooCommerce message.
	 * @param int       $error_code WooCommerce coupon error code.
	 * @param WC_Coupon $coupon     Rejected coupon.
	 */
	public function filter_coupon_error( string $message, int $error_code, WC_Coupon $coupon ): string {
		$evaluation = $this->latest[ $coupon->get_id() ] ?? null;
		if (
			null === $evaluation
			|| $evaluation->decision->allowed
			|| '' === $evaluation->decision->customer_message
		) {
			return $message;
		}

		return $evaluation->decision->customer_message;
	}

	/**
	 * Perform final Classic Checkout validation after billing data is available.
	 *
	 * @param array<string,mixed> $data   Sanitized checkout data.
	 * @param WP_Error            $errors Checkout errors.
	 */
	public function validate_classic_checkout( array $data, WP_Error $errors ): void {
		$email = isset( $data['billing_email'] ) && is_string( $data['billing_email'] )
			? $data['billing_email']
			: null;

		$this->validate_final(
			$this->cart_coupon_ids(),
			$this->current_user_id(),
			$email,
			DenialLogger::CONTEXT_CLASSIC_CHECKOUT,
			null,
			static function ( CheckoutEvaluation $evaluation ) use ( $errors ): void {
				$errors->add(
					'promoguard_' . $evaluation->decision->reason,
					$evaluation->decision->customer_message
				);
			}
		);
	}

	/**
	 * Perform final Store API validation against the persisted checkout order.
	 *
	 * Throwing an exception is the documented Store API mechanism for blocking
	 * checkout from this order-update action.
	 *
	 * @param WC_Order        $order   Checkout order.
	 * @param WP_REST_Request $request Store API request.
	 * @throws RuntimeException When a protected coupon is denied.
	 */
	public function validate_store_api_checkout( WC_Order $order, WP_REST_Request $request ): void {
		$this->validate_final(
			$this->coupon_ids_from_codes( $order->get_coupon_codes() ),
			$order->get_customer_id() > 0 ? $order->get_customer_id() : null,
			$order->get_billing_email(),
			DenialLogger::CONTEXT_STORE_API,
			$order->get_id(),
			static function ( CheckoutEvaluation $evaluation ): void {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed customer-safe policy message is handled by the Store API exception boundary.
				throw new RuntimeException( $evaluation->decision->customer_message );
			}
		);
	}

	/**
	 * Evaluate every applied coupon with final identity requirements.
	 *
	 * @param int[]                             $coupon_ids Coupon IDs.
	 * @param int|null                          $user_id    WordPress user ID.
	 * @param string|null                       $email      Billing email.
	 * @param string                            $context    Validation context.
	 * @param int|null                          $order_id   Checkout order ID.
	 * @param callable(CheckoutEvaluation):void $on_denied Transport-specific denial handler.
	 */
	private function validate_final(
		array $coupon_ids,
		?int $user_id,
		?string $email,
		string $context,
		?int $order_id,
		callable $on_denied
	): void {
		$now_gmt = $this->now_gmt();

		foreach ( array_unique( $coupon_ids ) as $coupon_id ) {
			$evaluation = $this->validator->evaluate(
				$coupon_id,
				$coupon_ids,
				$user_id,
				$email,
				$now_gmt,
				false
			);

			if ( null === $evaluation ) {
				continue;
			}

			$this->latest[ $coupon_id ] = $evaluation;
			if ( ! $evaluation->decision->allowed ) {
				$this->denials->record( $evaluation, $this->request_id, $context, $now_gmt, $order_id );
				$on_denied( $evaluation );
			}
		}
	}

	/**
	 * Reserve protected coupons after Classic Checkout creates the payable order.
	 *
	 * @param int                 $order_id   WooCommerce order ID.
	 * @param array<string,mixed> $posted_data Sanitized checkout data.
	 * @param WC_Order            $order      Created order.
	 * @throws RuntimeException When a protected campaign place cannot be reserved.
	 */
	public function reserve_classic_order( int $order_id, array $posted_data, WC_Order $order ): void {
		unset( $posted_data );

		if ( $order_id !== $order->get_id() ) {
			throw new RuntimeException( 'Checkout order context is inconsistent.' );
		}

		$this->reserve_order( $order );
	}

	/**
	 * Reserve protected coupons before Store API payment processing.
	 *
	 * @param WC_Order $order Processed Store API order.
	 * @throws RuntimeException When a protected campaign place cannot be reserved.
	 */
	public function reserve_store_api_order( WC_Order $order ): void {
		$this->reserve_order( $order );
	}

	/**
	 * Reserve each unique protected campaign represented on the order.
	 *
	 * @param WC_Order $order Payable WooCommerce order.
	 * @throws RuntimeException When final validation or reservation fails.
	 */
	private function reserve_order( WC_Order $order ): void {
		$now_gmt    = $this->now_gmt();
		$coupon_ids = $this->coupon_ids_from_codes( $order->get_coupon_codes() );

		foreach ( array_unique( $coupon_ids ) as $coupon_id ) {
			$evaluation = $this->latest[ $coupon_id ] ?? null;
			if ( null === $evaluation || $evaluation->decision->provisional ) {
				$evaluation = $this->validator->evaluate(
					$coupon_id,
					$coupon_ids,
					$order->get_customer_id() > 0 ? $order->get_customer_id() : null,
					$order->get_billing_email(),
					$now_gmt,
					false
				);
			}

			if ( null === $evaluation ) {
				continue;
			}

			if ( ! $evaluation->decision->allowed ) {
				$this->denials->record(
					$evaluation,
					$this->request_id,
					DenialLogger::CONTEXT_RESERVATION,
					$now_gmt,
					$order->get_id()
				);
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Policy message is customer-safe and handled by WooCommerce's checkout boundary.
				throw new RuntimeException( $evaluation->decision->customer_message );
			}

			try {
				$request = $this->requests->create(
					$evaluation,
					$order->get_id(),
					$order->get_currency(),
					wp_generate_uuid4(),
					$now_gmt
				);
				$this->reservations->reserve( $request );
			} catch ( Throwable $exception ) {
				$message = esc_html__( 'This promotion could not be reserved. Please try again.', 'promoguard-for-woocommerce' );
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is escaped; previous exception is diagnostic context only.
				throw new RuntimeException( $message, 0, $exception );
			}
		}
	}

	/**
	 * Resolve the active cart's coupon IDs.
	 *
	 * @return int[]
	 */
	private function cart_coupon_ids(): array {
		return $this->coupon_ids_from_codes( WC()->cart->get_applied_coupons() );
	}

	/**
	 * Resolve bounded coupon codes through WooCommerce's public lookup.
	 *
	 * @param string[] $codes Coupon codes.
	 * @return int[]
	 */
	private function coupon_ids_from_codes( array $codes ): array {
		$ids = array();
		foreach ( array_unique( $codes ) as $code ) {
			$coupon_id = wc_get_coupon_id_by_code( $code );
			if ( $coupon_id > 0 ) {
				$ids[] = $coupon_id;
			}
		}

		return $ids;
	}

	/** Return the authenticated user ID when available. */
	private function current_user_id(): ?int {
		$user_id = get_current_user_id();
		return $user_id > 0 ? $user_id : null;
	}

	/** Return the current WooCommerce customer billing email when available. */
	private function current_billing_email(): string {
		return WC()->customer->get_billing_email();
	}

	/** Build one stable GMT evaluation time. */
	private function now_gmt(): DateTimeImmutable {
		return new DateTimeImmutable( current_time( 'mysql', true ), new DateTimeZone( 'UTC' ) );
	}
}
// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
