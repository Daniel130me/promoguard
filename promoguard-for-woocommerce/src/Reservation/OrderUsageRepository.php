<?php
/**
 * Indexed order usage lookups.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

use InvalidArgumentException;
use JsonException;
use PromoGuard\Campaign\CampaignConfiguration;
use PromoGuard\Support\TableNames;
use RuntimeException;

/** Reads order lifecycle facts through the order/campaign unique index. */
final class OrderUsageRepository implements OrderUsageStore, OrderLifecycleContextStore {
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

	/**
	 * Return pending contexts without depending on a live coupon assignment.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return OrderUsageContext[]
	 * @throws RuntimeException When indexed lookup or rule hydration fails.
	 */
	public function pending_for_order( int $order_id ): array {
		global $wpdb;

		$sql = $wpdb->prepare(
			'SELECT usage_row.campaign_id, usage_row.coupon_code, campaign.usage_rules
			 FROM %i AS usage_row
			 INNER JOIN %i AS campaign ON campaign.id = usage_row.campaign_id
			 WHERE usage_row.order_id = %d AND usage_row.status = %s
			 ORDER BY usage_row.campaign_id ASC',
			$this->tables->usages(),
			$this->tables->campaigns(),
			$order_id,
			UsageStatus::PENDING
		);

		if ( null === $sql ) {
			throw new RuntimeException( 'Could not prepare the pending order usage lookup.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded order/campaign index lookup with primary-key campaign join.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Could not read pending order usage contexts.' );
		}

		$contexts = array();
		foreach ( $rows as $row ) {
			$contexts[] = new OrderUsageContext(
				(int) $row['campaign_id'],
				null === $row['coupon_code'] ? null : (string) $row['coupon_code'],
				$this->hydrate_usage_rules( (string) $row['usage_rules'] )
			);
		}

		return $contexts;
	}

	/**
	 * Decode and validate current campaign lifecycle rules.
	 *
	 * @param string $encoded_rules Persisted usage-rule JSON.
	 * @return array<string,mixed>
	 * @throws RuntimeException When persisted rules are malformed.
	 */
	private function hydrate_usage_rules( string $encoded_rules ): array {
		try {
			$rules = json_decode( $encoded_rules, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is diagnostic context and is never rendered.
			throw new RuntimeException( 'Pending order usage rules are invalid.', 0, $exception );
		}

		if ( ! is_array( $rules ) ) {
			throw new RuntimeException( 'Pending order usage rules must decode to an object.' );
		}

		try {
			return CampaignConfiguration::from_arrays( $rules, array(), array() )->usage_rules();
		} catch ( InvalidArgumentException $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is diagnostic context and is never rendered.
			throw new RuntimeException( 'Pending order usage rules are invalid.', 0, $exception );
		}
	}
}
