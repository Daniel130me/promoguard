<?php
/**
 * WooCommerce order lifecycle integration.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

use DateTimeImmutable;
use DateTimeZone;
use PromoGuard\Application\EligibilityService;
use PromoGuard\Campaign\CampaignRepository;
use PromoGuard\Checkout\CheckoutEvaluation;
use PromoGuard\Checkout\CheckoutTarget;
use PromoGuard\Checkout\CheckoutTargetResolver;
use PromoGuard\Checkout\CheckoutValidator;
use PromoGuard\Decision\DecisionRepository;
use PromoGuard\Decision\DenialLogger;
use PromoGuard\Promotion\CampaignPromotionRepository;
use PromoGuard\Support\TableNames;
use RuntimeException;
use WC_Order;
use WC_Order_Item_Coupon;

/** Maps WooCommerce order-status changes to campaign usage transitions. */
final class WooCommerceOrderLifecycle {
	private const DENIAL_META_KEY = '_promoguard_order_validation_denials';

	/**
	 * Configure order lifecycle dependencies.
	 *
	 * @param CheckoutTargetResolver      $targets            Indexed coupon target resolver.
	 * @param OrderReservationCoordinator $order_reservations Final order validation and reservation.
	 * @param UsageLifecycleService       $lifecycle          Usage lifecycle policy service.
	 */
	public function __construct(
		private readonly CheckoutTargetResolver $targets,
		private readonly OrderReservationCoordinator $order_reservations,
		private readonly UsageLifecycleService $lifecycle
	) {}

	/** Build the production lifecycle adapter for the active site. */
	public static function from_wordpress(): self {
		$tables     = TableNames::from_wordpress();
		$targets    = new CheckoutTargetResolver(
			new CampaignPromotionRepository( $tables ),
			new CampaignRepository( $tables )
		);
		$validator  = new CheckoutValidator( $targets, EligibilityService::from_wordpress() );
		$denials    = new DenialLogger( new DecisionRepository( $tables ) );
		$request_id = wp_generate_uuid4();

		return new self(
			$targets,
			new OrderReservationCoordinator(
				$validator,
				$denials,
				new ReservationService( new ReservationRepository( $tables ) ),
				new ReservationRequestFactory(),
				new OrderUsageRepository( $tables ),
				static fn(): string => wp_generate_uuid4(),
				$request_id
			),
			new UsageLifecycleService( new UsageLifecycleRepository( $tables ) )
		);
	}

	/** Register the canonical WooCommerce order-status hook. */
	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'transition_order' ), 20, 4 );
	}

	/**
	 * Validate first-counted admin/REST orders, then apply usage transitions.
	 *
	 * @param int      $order_id   WooCommerce order ID.
	 * @param string   $old_status Previous order status.
	 * @param string   $new_status New order status.
	 * @param WC_Order $order      Updated order.
	 * @throws RuntimeException When hook context, validation, or persistence fails.
	 */
	public function transition_order(
		int $order_id,
		string $old_status,
		string $new_status,
		WC_Order $order
	): void {
		unset( $old_status );

		if ( $order_id !== $order->get_id() ) {
			throw new RuntimeException( 'Order lifecycle context is inconsistent.' );
		}

		$now_gmt = new DateTimeImmutable( current_time( 'mysql', true ), new DateTimeZone( 'UTC' ) );
		$items   = $this->campaign_coupon_items( $order );
		$denied  = $this->recorded_denials( $order );

		if ( $this->requires_final_validation( $items, $new_status ) ) {
			$new_denials = $this->order_reservations->reserve(
				$this->coupon_ids_from_codes( $order->get_coupon_codes() ),
				$order->get_customer_id() > 0 ? $order->get_customer_id() : null,
				$order->get_billing_email(),
				$order_id,
				$order->get_currency(),
				$now_gmt,
				DenialLogger::CONTEXT_ORDER_VALIDATION,
				array_keys( $denied )
			);
			$this->record_denials( $order, $new_denials );

			foreach ( $new_denials as $campaign_id => $evaluation ) {
				$denied[ $campaign_id ] = $evaluation->decision->reason;
			}
		}

		foreach ( $items as $campaign_id => $item ) {
			if ( isset( $denied[ $campaign_id ] ) ) {
				continue;
			}

			$target = $item['target'];
			$this->lifecycle->transition(
				new UsageTransition(
					campaign_id: $campaign_id,
					order_id: $order_id,
					order_status: $new_status,
					discount_amount: $item['discount_amount'],
					occurred_at_gmt: $now_gmt
				),
				$target->campaign->configuration->usage_rules()
			);
		}
	}

	/**
	 * Whether any protected campaign counts the incoming status.
	 *
	 * @param array<int,array{target:CheckoutTarget,coupon_id:int,discount_amount:string}> $items  Protected coupon items.
	 * @param string                                                                       $status Incoming order status.
	 */
	private function requires_final_validation( array $items, string $status ): bool {
		foreach ( $items as $item ) {
			$rules = $item['target']->campaign->configuration->usage_rules();
			if ( in_array( $status, $rules['counted_statuses'], true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return campaigns already denied at their first counted status.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array<int,string>
	 */
	private function recorded_denials( WC_Order $order ): array {
		$value = $order->get_meta( self::DENIAL_META_KEY, true );
		if ( ! is_array( $value ) ) {
			return array();
		}

		$denials = array();
		foreach ( $value as $campaign_id => $reason ) {
			if ( ctype_digit( (string) $campaign_id ) && is_string( $reason ) && '' !== $reason ) {
				$denials[ (int) $campaign_id ] = $reason;
			}
		}

		return $denials;
	}

	/**
	 * Add one administrator note and marker for each newly denied campaign.
	 *
	 * @param WC_Order                      $order   WooCommerce order.
	 * @param array<int,CheckoutEvaluation> $denials New denials keyed by campaign ID.
	 */
	private function record_denials( WC_Order $order, array $denials ): void {
		$recorded = $this->recorded_denials( $order );
		$changed  = false;

		foreach ( $denials as $campaign_id => $evaluation ) {
			if ( isset( $recorded[ $campaign_id ] ) ) {
				continue;
			}

			$order->add_order_note(
				sprintf(
					/* translators: %s: administrative campaign eligibility explanation. */
					__( 'PromoGuard did not consume this campaign usage: %s', 'promoguard-for-woocommerce' ),
					$evaluation->decision->admin_explanation
				)
			);
			$recorded[ $campaign_id ] = $evaluation->decision->reason;
			$changed                  = true;
		}

		if ( $changed ) {
			$order->update_meta_data( self::DENIAL_META_KEY, $recorded );
			$order->save_meta_data();
		}
	}

	/**
	 * Resolve at most one coupon item per protected campaign.
	 *
	 * Campaign configuration already restricts orders to one coupon per campaign.
	 *
	 * @param WC_Order $order Updated WooCommerce order.
	 * @return array<int,array{target:CheckoutTarget,coupon_id:int,discount_amount:string}>
	 */
	private function campaign_coupon_items( WC_Order $order ): array {
		$campaigns = array();

		foreach ( $order->get_items( 'coupon' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Coupon ) {
				continue;
			}

			$coupon_id = wc_get_coupon_id_by_code( $item->get_code() );
			$target    = $this->targets->resolve( $coupon_id );

			if ( null === $target ) {
				continue;
			}

			$campaign_id = $target->assignment->campaign_id;
			if ( isset( $campaigns[ $campaign_id ] ) ) {
				continue;
			}

			$campaigns[ $campaign_id ] = array(
				'target'          => $target,
				'coupon_id'       => $coupon_id,
				'discount_amount' => wc_format_decimal( $item->get_discount(), 8 ),
			);
		}

		return $campaigns;
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
}
