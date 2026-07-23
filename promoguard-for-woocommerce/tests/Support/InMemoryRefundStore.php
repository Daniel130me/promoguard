<?php
/**
 * In-memory refund test double.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Support;

use DateTimeImmutable;
use PromoGuard\Refund\RefundContextStore;
use PromoGuard\Refund\RefundRestorationStore;
use PromoGuard\Refund\RefundUsageContext;

/** Records refund reads and restorations without persistence. */
final class InMemoryRefundStore implements RefundContextStore, RefundRestorationStore {
	/**
	 * Configured consumed usage contexts.
	 *
	 * @var RefundUsageContext[]
	 */
	public array $contexts = array();

	/**
	 * Number of consumed-context reads.
	 *
	 * @var int
	 */
	public int $context_reads = 0;

	/**
	 * Recorded restoration attempts.
	 *
	 * @var array<int,array{order_id:int,campaign_id:int}>
	 */
	public array $restorations = array();

	/**
	 * Whether restoration changes persisted state.
	 *
	 * @var bool
	 */
	public bool $changes_state = true;

	/**
	 * Return configured contexts.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return RefundUsageContext[]
	 */
	public function consumed_for_order( int $order_id ): array {
		unset( $order_id );
		++$this->context_reads;
		return $this->contexts;
	}

	/**
	 * Record one restoration attempt.
	 *
	 * @param int               $order_id        WooCommerce order ID.
	 * @param int               $campaign_id     Campaign ID.
	 * @param DateTimeImmutable $occurred_at_gmt Restoration time.
	 */
	public function restore( int $order_id, int $campaign_id, DateTimeImmutable $occurred_at_gmt ): bool {
		unset( $occurred_at_gmt );
		$this->restorations[] = compact( 'order_id', 'campaign_id' );
		return $this->changes_state;
	}
}
