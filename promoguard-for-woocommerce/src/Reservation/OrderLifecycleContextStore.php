<?php
/**
 * Pending order lifecycle context boundary.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

/** Loads pending usage snapshots independently of live coupon assignments. */
interface OrderLifecycleContextStore {
	/**
	 * Return pending campaign contexts for one order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return OrderUsageContext[]
	 */
	public function pending_for_order( int $order_id ): array;
}
