<?php
/**
 * WooCommerce refund lifecycle for consumed store credit.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Credit;

use Throwable;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Order_Refund;

/** Restores only the credit-funded, non-shipping portion of persisted refunds. */
final class StoreCreditRefundLifecycle {
	// Typed WooCommerce hooks and focused summaries document this adapter clearly.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag

	private const RESTORED_META = '_promoguard_credit_restored_amount';

	/** Configure refund policy and atomic redemption persistence. */
	public function __construct(
		private readonly CreditRedemptionStore $credits,
		private readonly CreditRefundCalculator $calculator
	) {}

	/** Build the production WooCommerce adapter. */
	public static function from_wordpress(): self {
		return new self( CreditRedemptionRepository::from_wordpress(), new CreditRefundCalculator() );
	}

	/** Register the canonical post-refund WooCommerce hook. */
	public function register(): void {
		add_action( 'woocommerce_order_refunded', array( $this, 'handle_refund' ), 30, 2 );
	}

	/**
	 * Restore eligible consumed credit after WooCommerce persists a refund.
	 *
	 * Repository idempotency ensures repeated hooks cannot restore twice, while
	 * its order-level cap prevents all refunds exceeding the consumed credit.
	 */
	public function handle_refund( int $order_id, int $refund_id ): void {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if (
			! $order instanceof WC_Order
			|| ! $refund instanceof WC_Order_Refund
			|| $refund->get_parent_id() !== $order_id
		) {
			return;
		}

		try {
			$result = $this->credits->restore( $order_id, $refund_id, $this->eligible_amount( $refund ) );
			if ( ! $result->created || ! CreditAmount::is_positive( $result->amount ) ) {
				return;
			}

			$refund->update_meta_data( self::RESTORED_META, $result->amount );
			$refund->save_meta_data();
			$order->add_order_note(
				sprintf(
					/* translators: 1: restored amount, 2: refund ID. */
					__( 'PromoGuard restored %1$s of store credit for refund #%2$d.', 'promoguard-for-woocommerce' ),
					wc_price( (float) $result->amount, array( 'currency' => $order->get_currency() ) ),
					$refund_id
				)
			);
		} catch ( Throwable $exception ) {
			do_action( 'promoguard_store_credit_error', $exception, $order_id, 'restore' );
		}
	}

	/** Calculate eligible merchandise and merchandise-tax value from one refund. */
	private function eligible_amount( WC_Order_Refund $refund ): string {
		$line_amounts = array();
		foreach ( $refund->get_items( 'line_item' ) as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$line_amounts[] = $this->combined_magnitude( (string) $item->get_total(), (string) $item->get_total_tax() );
			}
		}

		$shipping = '0';
		foreach ( $refund->get_items( 'shipping' ) as $item ) {
			if ( $item instanceof WC_Order_Item_Shipping ) {
				$shipping = CreditAmount::add( $shipping, $this->combined_magnitude( (string) $item->get_total(), (string) $item->get_total_tax() ) );
			}
		}

		$fees = '0';
		foreach ( $refund->get_items( 'fee' ) as $item ) {
			if ( $item instanceof WC_Order_Item_Fee ) {
				$fees = CreditAmount::add( $fees, $this->combined_magnitude( (string) $item->get_total(), (string) $item->get_total_tax() ) );
			}
		}

		return $this->calculator->calculate(
			$line_amounts,
			$this->magnitude( (string) $refund->get_total() ),
			$shipping,
			$fees
		);
	}

	/** Combine the magnitudes of an item's total and tax. */
	private function combined_magnitude( string $total, string $tax ): string {
		return CreditAmount::add( $this->magnitude( $total ), $this->magnitude( $tax ) );
	}

	/** Convert one signed WooCommerce amount to an exact non-negative amount. */
	private function magnitude( string $amount ): string {
		return CreditAmount::normalize( ltrim( wc_format_decimal( $amount, 8 ), '-' ) );
	}
}
