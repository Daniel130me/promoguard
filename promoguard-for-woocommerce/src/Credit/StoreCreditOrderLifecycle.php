<?php
/**
 * WooCommerce payment lifecycle for reserved store credit.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Credit;

use Throwable;
use WC_Order;

/** Consumes paid reservations and releases failed or cancelled reservations. */
final class StoreCreditOrderLifecycle {
	// Typed WooCommerce hook signatures accompany focused lifecycle summaries.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag
	private const CONSUMED_META = '_promoguard_credit_consumed_amount';
	private const RELEASED_META = '_promoguard_credit_released';

	/** Configure atomic redemption persistence. */
	public function __construct( private readonly CreditRedemptionStore $credits ) {}

	/** Build the active WordPress repository adapter. */
	public static function from_wordpress(): self {
		return new self( CreditRedemptionRepository::from_wordpress() );
	}

	/** Register canonical payment and status hooks. */
	public function register(): void {
		add_action( 'woocommerce_payment_complete', array( $this, 'payment_complete' ), 20 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'status_changed' ), 30, 4 );
	}

	/** Consume reserved credit after WooCommerce confirms payment. */
	public function payment_complete( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			$this->consume( $order );
		}
	}

	/** Apply paid, failed, and cancelled order transitions idempotently. */
	public function status_changed( int $order_id, string $old_status, string $new_status, WC_Order $order ): void {
		unset( $old_status );
		if ( $order_id !== $order->get_id() ) {
			return;
		}

		if ( in_array( $new_status, array( 'failed', 'cancelled' ), true ) ) {
			$this->release( $order );
			return;
		}
		if ( $order->is_paid() ) {
			$this->consume( $order );
		}
	}

	/** Consume one order without allowing a credit error to break gateway callbacks. */
	private function consume( WC_Order $order ): void {
		try {
			$reservation = $this->credits->consume( $order->get_id() );
			if ( null === $reservation || CreditReservation::CONSUMED !== $reservation->status ) {
				return;
			}
			if ( '' === (string) $order->get_meta( self::CONSUMED_META, true ) ) {
				$order->update_meta_data( self::CONSUMED_META, $reservation->consumed_amount );
				$order->add_order_note(
					sprintf(
						/* translators: %s: consumed store-credit amount. */
						__( 'PromoGuard consumed %s of store credit after payment.', 'promoguard-for-woocommerce' ),
						wc_price( (float) $reservation->consumed_amount, array( 'currency' => $reservation->currency ) )
					)
				);
				$order->save();
			}
		} catch ( Throwable $exception ) {
			do_action( 'promoguard_store_credit_error', $exception, $order->get_id(), 'consume' );
		}
	}

	/** Release one unpaid order without breaking WooCommerce status changes. */
	private function release( WC_Order $order ): void {
		try {
			$reservation = $this->credits->release( $order->get_id() );
			if ( null === $reservation || CreditReservation::RELEASED !== $reservation->status ) {
				return;
			}
			if ( 'yes' !== $order->get_meta( self::RELEASED_META, true ) ) {
				$order->update_meta_data( self::RELEASED_META, 'yes' );
				$order->add_order_note( __( 'PromoGuard released the pending store-credit reservation.', 'promoguard-for-woocommerce' ) );
				$order->save();
			}
		} catch ( Throwable $exception ) {
			do_action( 'promoguard_store_credit_error', $exception, $order->get_id(), 'release' );
		}
	}
}
