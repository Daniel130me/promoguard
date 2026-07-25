<?php
/**
 * WooCommerce cumulative refund integration.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Refund;

use DateTimeImmutable;
use DateTimeZone;
use PromoGuard\Support\TableNames;
use RuntimeException;
use WC_Order;
use WC_Order_Refund;

/** Classifies cumulative refunds and records campaign usage outcomes. */
final class WooCommerceRefundLifecycle {
	private const OUTCOME_META_KEY = '_promoguard_refund_outcomes';

	/**
	 * Configure the refund policy service.
	 *
	 * @param RefundService $refunds Cumulative refund policy service.
	 */
	public function __construct( private readonly RefundService $refunds ) {}

	/** Build the production refund adapter for the active site. */
	public static function from_wordpress(): self {
		$repository = new RefundRepository( TableNames::from_wordpress() );
		return new self( new RefundService( $repository, $repository ) );
	}

	/** Register the canonical post-refund WooCommerce hook. */
	public function register(): void {
		add_action( 'woocommerce_order_refunded', array( $this, 'handle_refund' ), 20, 2 );
	}

	/**
	 * Apply PromoGuard policy after WooCommerce has persisted a refund.
	 *
	 * @param int $order_id  Parent WooCommerce order ID.
	 * @param int $refund_id WooCommerce refund order ID.
	 * @throws RuntimeException When WooCommerce hook context is inconsistent.
	 */
	public function handle_refund( int $order_id, int $refund_id ): void {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if (
			! $order instanceof WC_Order
			|| ! $refund instanceof WC_Order_Refund
			|| $refund->get_parent_id() !== $order_id
		) {
			throw new RuntimeException( 'WooCommerce refund context is inconsistent.' );
		}

		$outcomes = $this->refunds->handle(
			new RefundEvent(
				$order_id,
				$this->is_full_refund( $order ),
				new DateTimeImmutable( current_time( 'mysql', true ), new DateTimeZone( 'UTC' ) )
			)
		);
		if ( array_diff( $outcomes, array( RefundService::UNCHANGED ) ) ) {
			do_action( 'promoguard_analytics_changed' );
		}
		$this->record_outcomes( $order, $refund_id, $outcomes );
	}

	/**
	 * Whether cumulative persisted refunds cover the order total.
	 *
	 * WooCommerce exposes cumulative refunded and remaining amounts through its
	 * CRUD object, preserving HPOS and legacy-order compatibility.
	 *
	 * @param WC_Order $order Refunded WooCommerce order.
	 */
	private function is_full_refund( WC_Order $order ): bool {
		return (float) $order->get_total_refunded() > 0.0
			&& (float) $order->get_remaining_refund_amount() <= 0.0;
	}

	/**
	 * Add one administrator note for each newly observed policy outcome.
	 *
	 * @param WC_Order          $order     Refunded WooCommerce order.
	 * @param int               $refund_id Refund order ID.
	 * @param array<int,string> $outcomes  Outcomes keyed by campaign ID.
	 */
	private function record_outcomes( WC_Order $order, int $refund_id, array $outcomes ): void {
		$recorded = $order->get_meta( self::OUTCOME_META_KEY, true );
		$recorded = is_array( $recorded ) ? $recorded : array();
		$changed  = false;

		foreach ( $outcomes as $campaign_id => $outcome ) {
			if ( RefundService::UNCHANGED === $outcome || isset( $recorded[ $campaign_id ] ) ) {
				continue;
			}

			$order->add_order_note( $this->outcome_note( $campaign_id, $refund_id, $outcome ) );
			$recorded[ $campaign_id ] = $outcome;
			$changed                  = true;
		}

		if ( $changed ) {
			$order->update_meta_data( self::OUTCOME_META_KEY, $recorded );
			$order->save_meta_data();
		}
	}

	/**
	 * Build the administrator note for one refund outcome.
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param int    $refund_id   Refund order ID.
	 * @param string $outcome     Refund service outcome.
	 */
	private function outcome_note( int $campaign_id, int $refund_id, string $outcome ): string {
		if ( RefundService::RESTORED === $outcome ) {
			/* translators: 1: campaign ID, 2: refund ID. */
			return sprintf( __( 'PromoGuard restored campaign %1$d usage after full refund #%2$d.', 'promoguard-for-woocommerce' ), $campaign_id, $refund_id );
		}

		if ( RefundService::KEPT === $outcome ) {
			/* translators: 1: campaign ID, 2: refund ID. */
			return sprintf( __( 'PromoGuard kept campaign %1$d usage consumed after full refund #%2$d.', 'promoguard-for-woocommerce' ), $campaign_id, $refund_id );
		}

		/* translators: 1: campaign ID, 2: refund ID. */
		return sprintf( __( 'PromoGuard requires manual review for campaign %1$d after full refund #%2$d.', 'promoguard-for-woocommerce' ), $campaign_id, $refund_id );
	}
}
