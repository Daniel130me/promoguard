<?php
/**
 * Analytics order-ID page source.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Analytics;

/** Supplies bounded, ascending order-ID pages from the PromoGuard ledger. */
interface OrderIdPageSource {
	/**
	 * Return distinct order IDs after the supplied cursor.
	 *
	 * @param AnalyticsFilter $filter         Validated report filters.
	 * @param int             $after_order_id Exclusive order-ID cursor.
	 * @param int             $limit          Maximum page size.
	 * @return int[]
	 */
	public function order_ids_after( AnalyticsFilter $filter, int $after_order_id, int $limit ): array;
}
