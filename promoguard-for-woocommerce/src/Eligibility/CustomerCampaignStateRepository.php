<?php
/**
 * Customer campaign state repository.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Eligibility;

use DateTimeImmutable;
use DateTimeZone;
use PromoGuard\Support\TableNames;

/** Reads eligibility counters through the unique campaign/customer index. */
final class CustomerCampaignStateRepository implements CustomerCampaignStateStore {
	private const COLUMNS = 'campaign_id, customer_id, consumed_count, reserved_count, total_discount, first_consumed_at_gmt, last_consumed_at_gmt, last_order_id, lock_version';

	/**
	 * Configure site-scoped state persistence.
	 *
	 * @param TableNames $tables Site-scoped plugin table names.
	 */
	public function __construct( private readonly TableNames $tables ) {}

	/** Build a repository for the active WordPress site. */
	public static function from_wordpress(): self {
		return new self( TableNames::from_wordpress() );
	}

	/**
	 * Find one campaign/customer counter snapshot.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @param int $customer_id Customer ID.
	 */
	public function find( int $campaign_id, int $customer_id ): ?CustomerCampaignState {
		if ( $campaign_id < 1 || $customer_id < 1 ) {
			return null;
		}

		global $wpdb;

		$table = $this->tables->customer_campaign_state();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Fixed columns and plugin-owned table; both indexed IDs are prepared.
		$sql = $wpdb->prepare(
			'SELECT ' . self::COLUMNS . " FROM {$table} WHERE campaign_id = %d AND customer_id = %d LIMIT 1",
			$campaign_id,
			$customer_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One bounded lookup backed by the unique campaign/customer index.
		$row = $wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Hydrate a persisted counter snapshot.
	 *
	 * @param array<string,mixed> $row Database row.
	 */
	private function hydrate( array $row ): CustomerCampaignState {
		$gmt = new DateTimeZone( 'UTC' );

		return new CustomerCampaignState(
			campaign_id: (int) $row['campaign_id'],
			customer_id: (int) $row['customer_id'],
			consumed_count: (int) $row['consumed_count'],
			reserved_count: (int) $row['reserved_count'],
			total_discount: (string) $row['total_discount'],
			first_consumed_at_gmt: null === $row['first_consumed_at_gmt'] ? null : new DateTimeImmutable( (string) $row['first_consumed_at_gmt'], $gmt ),
			last_consumed_at_gmt: null === $row['last_consumed_at_gmt'] ? null : new DateTimeImmutable( (string) $row['last_consumed_at_gmt'], $gmt ),
			last_order_id: null === $row['last_order_id'] ? null : (int) $row['last_order_id'],
			lock_version: (int) $row['lock_version']
		);
	}
}
