<?php
/**
 * WooCommerce order lifecycle integration.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

use DateTimeImmutable;
use DateTimeZone;
use PromoGuard\Campaign\CampaignRepository;
use PromoGuard\Checkout\CheckoutTarget;
use PromoGuard\Checkout\CheckoutTargetResolver;
use PromoGuard\Promotion\CampaignPromotionRepository;
use PromoGuard\Support\TableNames;
use RuntimeException;
use WC_Order;
use WC_Order_Item_Coupon;

/** Maps WooCommerce order-status changes to campaign usage transitions. */
final class WooCommerceOrderLifecycle {
	/**
	 * Configure order lifecycle dependencies.
	 *
	 * @param CheckoutTargetResolver $targets   Indexed coupon target resolver.
	 * @param UsageLifecycleService  $lifecycle Usage lifecycle policy service.
	 */
	public function __construct(
		private readonly CheckoutTargetResolver $targets,
		private readonly UsageLifecycleService $lifecycle
	) {}

	/** Build the production lifecycle adapter for the active site. */
	public static function from_wordpress(): self {
		$tables = TableNames::from_wordpress();

		return new self(
			new CheckoutTargetResolver(
				new CampaignPromotionRepository( $tables ),
				new CampaignRepository( $tables )
			),
			new UsageLifecycleService( new UsageLifecycleRepository( $tables ) )
		);
	}

	/** Register the canonical WooCommerce order-status hook. */
	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'transition_order' ), 20, 4 );
	}

	/**
	 * Apply each protected campaign transition represented on the order.
	 *
	 * @param int      $order_id   WooCommerce order ID.
	 * @param string   $old_status Previous order status.
	 * @param string   $new_status New order status.
	 * @param WC_Order $order      Updated order.
	 * @throws RuntimeException When hook context or lifecycle persistence fails.
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

		foreach ( $this->campaign_coupon_items( $order ) as $item ) {
			$target = $item['target'];
			$this->lifecycle->transition(
				new UsageTransition(
					campaign_id: $target->assignment->campaign_id,
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
	 * Resolve at most one coupon item per protected campaign.
	 *
	 * Campaign configuration already restricts orders to one coupon per campaign.
	 *
	 * @param WC_Order $order Updated WooCommerce order.
	 * @return array<int,array{target:CheckoutTarget,discount_amount:string}>
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
				'discount_amount' => wc_format_decimal( $item->get_discount(), 8 ),
			);
		}

		return $campaigns;
	}
}
