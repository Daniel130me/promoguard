<?php
/**
 * In-memory order usage test double.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Support;

use PromoGuard\Reservation\OrderUsageStore;

/** Returns configured active campaigns without database access. */
final class InMemoryOrderUsageStore implements OrderUsageStore {
	/**
	 * Active campaign IDs.
	 *
	 * @var int[]
	 */
	private array $campaign_ids;

	/**
	 * Configure active campaigns.
	 *
	 * @param int[] $campaign_ids Active campaign IDs.
	 */
	public function __construct( array $campaign_ids = array() ) {
		$this->campaign_ids = $campaign_ids;
	}

	/**
	 * Return configured active campaigns.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return int[]
	 */
	public function active_campaign_ids_for_order( int $order_id ): array {
		unset( $order_id );
		return $this->campaign_ids;
	}
}
