<?php
/**
 * Order usage lookup boundary.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

/** Finds active campaign usages already attached to an order. */
interface OrderUsageStore {
	/**
	 * Return pending or consumed campaign IDs for one order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return int[]
	 */
	public function active_campaign_ids_for_order( int $order_id ): array;
}
