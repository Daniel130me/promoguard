<?php
/**
 * Refund context lookup boundary.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Refund;

/** Loads consumed usage policies for one order. */
interface RefundContextStore {
	/**
	 * Return consumed usage contexts for one order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return RefundUsageContext[]
	 */
	public function consumed_for_order( int $order_id ): array;
}
