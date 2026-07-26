<?php
/**
 * WooCommerce order provider.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Analytics;

use WC_Order;
use WC_Order_Factory;

/** Uses WooCommerce CRUD queries so revenue reporting remains HPOS compatible. */
final class WooCommerceOrderProvider implements OrderProvider {
	/**
	 * Load one bounded order page through WooCommerce's public batch API.
	 *
	 * @param int[] $order_ids Order IDs.
	 * @return OrderSnapshot[]
	 */
	public function find_by_ids( array $order_ids ): array {
		if ( array() === $order_ids ) {
			return array();
		}

		return $this->snapshots( WC_Order_Factory::get_orders( $order_ids, true ) );
	}

	/**
	 * Normalize the public factory boundary, which may preserve invalid-ID placeholders.
	 *
	 * @param mixed $orders WooCommerce factory result.
	 * @return OrderSnapshot[]
	 */
	private function snapshots( mixed $orders ): array {
		if ( ! is_array( $orders ) ) {
			return array();
		}

		$result = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$result[] = new OrderSnapshot( $order->get_currency(), (string) $order->get_total() );
		}

		return $result;
	}
}
