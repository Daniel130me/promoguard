<?php
/**
 * WooCommerce order-provider contract.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Analytics;

/** Loads WooCommerce orders without exposing storage details to analytics code. */
interface OrderProvider {
	/**
	 * Load existing orders by ID.
	 *
	 * @param int[] $order_ids Order IDs.
	 * @return OrderSnapshot[]
	 */
	public function find_by_ids( array $order_ids ): array;
}
