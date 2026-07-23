<?php
/**
 * Indexed order usage lookups.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

use PromoGuard\Support\TableNames;
use RuntimeException;

/** Reads active campaign IDs through the order/campaign unique index. */
final class OrderUsageRepository implements OrderUsageStore {
	/**
	 * Configure site-scoped usage persistence.
	 *
	 * @param TableNames $tables Site-scoped plugin table names.
	 */
	public function __construct( private readonly TableNames $tables ) {}

	/**
	 * Return pending or consumed campaign IDs for one order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return int[]
	 * @throws RuntimeException When the indexed lookup fails.
	 */
	public function active_campaign_ids_for_order( int $order_id ): array {
		global $wpdb;

		$sql = $wpdb->prepare(
			'SELECT campaign_id FROM %i
			 WHERE order_id = %d AND status IN (%s, %s)
			 ORDER BY campaign_id ASC',
			$this->tables->usages(),
			$order_id,
			UsageStatus::PENDING,
			UsageStatus::CONSUMED
		);

		if ( null === $sql ) {
			throw new RuntimeException( 'Could not prepare the active order usage lookup.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded order/campaign index lookup.
		$campaign_ids = $wpdb->get_col( $sql );
		if ( ! is_array( $campaign_ids ) || '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Could not read active order usages.' );
		}

		return array_values( array_unique( array_map( 'intval', $campaign_ids ) ) );
	}
}
