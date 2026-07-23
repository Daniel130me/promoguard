<?php
/**
 * Refund restoration persistence boundary.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Refund;

/** Restores consumed campaign usages atomically and idempotently. */
interface RefundRestorationStore {
	/**
	 * Restore one consumed order/campaign usage.
	 *
	 * @param int                $order_id        WooCommerce order ID.
	 * @param int                $campaign_id     Campaign ID.
	 * @param \DateTimeImmutable $occurred_at_gmt Restoration time.
	 */
	public function restore( int $order_id, int $campaign_id, \DateTimeImmutable $occurred_at_gmt ): bool;
}
