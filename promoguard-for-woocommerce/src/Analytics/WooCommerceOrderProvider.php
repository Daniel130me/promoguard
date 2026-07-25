<?php
/**
 * WooCommerce order provider.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Analytics;

use WC_Order;

/** Uses WooCommerce CRUD queries so revenue reporting remains HPOS compatible. */
final class WooCommerceOrderProvider implements OrderProvider {
	/**
	 * Load one bounded order page through WooCommerce's public API.
	 *
	 * @param int[] $order_ids Order IDs.
	 * @return OrderSnapshot[]
	 */
	public function find_by_ids( array $order_ids ): array {
		if ( array() === $order_ids ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'include' => $order_ids,
				'limit'   => count( $order_ids ),
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'objects',
			)
		);

		if ( ! is_array( $orders ) ) {
			return array();
		}

		$result = array();
		foreach ( $orders as $order ) {
			$result[] = new OrderSnapshot( $order->get_currency(), (string) $order->get_total() );
		}

		return $result;
	}
}
