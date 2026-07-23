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
use PromoGuard\Promotion\CampaignPromotionRepository;
use PromoGuard\Support\TableNames;
use RuntimeException;
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
	 * Configure checkout validation.
	 *
	 * @param CheckoutValidator $validator Request-cached validator.
	 */
	public function __construct( private readonly CheckoutValidator $validator ) {}

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
			)
		);
	}

	/** Register validation and message hooks for Classic and Store API checkout. */
	public function register(): void {
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'filter_coupon_is_valid' ), 10, 3 );
		add_filter( 'woocommerce_coupon_error', array( $this, 'filter_coupon_error' ), 10, 3 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_classic_checkout' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'validate_store_api_checkout' ), 20, 2 );
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

		$evaluation = $this->validator->evaluate(
			$coupon->get_id(),
			$this->cart_coupon_ids(),
			$this->current_user_id(),
			$this->current_billing_email(),
			$this->now_gmt(),
			true
		);

		if ( null === $evaluation ) {
			return $valid;
		}

		$this->latest[ $coupon->get_id() ] = $evaluation;
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
	 * @param callable(CheckoutEvaluation):void $on_denied  Transport-specific denial handler.
	 */
	private function validate_final( array $coupon_ids, ?int $user_id, ?string $email, callable $on_denied ): void {
		foreach ( array_unique( $coupon_ids ) as $coupon_id ) {
			$evaluation = $this->validator->evaluate(
				$coupon_id,
				$coupon_ids,
				$user_id,
				$email,
				$this->now_gmt(),
				false
			);

			if ( null !== $evaluation && ! $evaluation->decision->allowed ) {
				$this->latest[ $coupon_id ] = $evaluation;
				$on_denied( $evaluation );
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
