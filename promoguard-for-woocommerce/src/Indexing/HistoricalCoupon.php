<?php
/**
 * Historical order coupon snapshot.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Indexing;

use InvalidArgumentException;

/** Carries one WooCommerce coupon item without retaining the order object. */
final class HistoricalCoupon {
	/**
	 * Create a validated historical coupon snapshot.
	 *
	 * @param int    $order_item_id WooCommerce coupon item ID.
	 * @param string $code          Display code snapshot.
	 * @param string $code_key      WooCommerce-normalized lookup key.
	 * @param string $discount      Decimal discount amount.
	 * @throws InvalidArgumentException When coupon facts are malformed.
	 */
	public function __construct(
		public readonly int $order_item_id,
		public readonly string $code,
		public readonly string $code_key,
		public readonly string $discount
	) {
		if (
			$order_item_id < 1
			|| '' === trim( $code )
			|| '' === trim( $code_key )
			|| strlen( $code ) > 255
			|| strlen( $code_key ) > 255
			|| ! is_numeric( $discount )
			|| (float) $discount < 0
		) {
			throw new InvalidArgumentException( 'Historical coupon facts are invalid.' );
		}
	}
}
