<?php
/**
 * Historical usage import repository.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Indexing;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use PromoGuard\Campaign\CampaignConfiguration;
use PromoGuard\Promotion\WooCommerceCouponSource;
use PromoGuard\Reservation\UsageStatus;
use PromoGuard\Support\TableNames;
use RuntimeException;
use Throwable;
use wpdb;

/** Imports consumed/restored usages through short state-first transactions. */
final class HistoricalImportRepository implements HistoricalImportStore {
	/**
	 * Configure site-scoped historical persistence.
	 *
	 * @param TableNames $tables Site-scoped plugin table names.
	 */
	public function __construct( private readonly TableNames $tables ) {}

	/**
	 * Resolve a page's distinct assignment snapshots in one indexed query.
	 *
	 * @param string[] $code_keys WooCommerce-normalized coupon codes.
	 * @return array<string,HistoricalCampaign>
	 * @throws RuntimeException When the assignment lookup fails.
	 */
	public function campaigns_for_codes( array $code_keys ): array {
		$code_keys = array_values( array_unique( $code_keys ) );
		if ( array() === $code_keys ) {
			return array();
		}

		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $code_keys ), '%s' ) );
		$arguments    = array_merge(
			array(
				$this->tables->campaign_promotions(),
				$this->tables->campaigns(),
				WooCommerceCouponSource::SOURCE,
				WooCommerceCouponSource::SOURCE_TYPE,
			),
			$code_keys
		);
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholder list contains only fixed %s tokens; identifiers and values are passed to prepare.
		$sql = $wpdb->prepare(
			"SELECT assignment.id AS promotion_id, assignment.campaign_id,
			        assignment.external_id AS coupon_id, assignment.external_code,
			        campaign.usage_rules
			 FROM %i AS assignment
			 INNER JOIN %i AS campaign ON campaign.id = assignment.campaign_id
			 WHERE assignment.source = %s AND assignment.source_type = %s
			   AND assignment.external_code IN ({$placeholders})
			 ORDER BY assignment.id ASC",
			...$arguments
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		if ( null === $sql ) {
			throw new RuntimeException( 'Could not prepare the historical campaign lookup.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One bounded, indexed assignment lookup per order page.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Could not read historical campaign assignments.' );
		}

		$campaigns = array();
		foreach ( $rows as $row ) {
			$key = wc_format_coupon_code( (string) $row['external_code'] );
			if ( isset( $campaigns[ $key ] ) ) {
				continue;
			}
			$rules             = $this->usage_rules( (string) $row['usage_rules'] );
			$coupon_id         = ctype_digit( (string) $row['coupon_id'] ) ? (int) $row['coupon_id'] : null;
			$campaigns[ $key ] = new HistoricalCampaign(
				(int) $row['campaign_id'],
				(int) $row['promotion_id'],
				$coupon_id > 0 ? $coupon_id : null,
				$rules['counted_statuses'],
				$rules['refund_behavior']
			);
		}

		return $campaigns;
	}

	/**
	 * Import one usage and aggregate contribution exactly once.
	 *
	 * @param HistoricalUsage $usage Validated usage request.
	 * @throws Throwable When persistence fails and is rolled back.
	 */
	public function import( HistoricalUsage $usage ): bool {
		global $wpdb;

		$transaction_open = false;
		try {
			$this->query( $wpdb, 'START TRANSACTION', 'Could not start the historical import transaction.' );
			$transaction_open = true;
			$this->ensure_state( $wpdb, $usage );
			$this->lock_state( $wpdb, $usage );

			if ( $this->usage_exists( $wpdb, $usage ) ) {
				$this->query( $wpdb, 'COMMIT', 'Could not commit the existing historical usage.' );
				$transaction_open = false;
				return false;
			}

			$this->insert_usage( $wpdb, $usage );
			if ( UsageStatus::CONSUMED === $usage->status ) {
				$this->increment_state( $wpdb, $usage );
			}
			$this->query( $wpdb, 'COMMIT', 'Could not commit the historical usage.' );
			$transaction_open = false;
			return true;
		} catch ( Throwable $exception ) {
			if ( $transaction_open ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required transaction rollback.
				$wpdb->query( 'ROLLBACK' );
			}
			throw $exception;
		}
	}

	/**
	 * Decode and validate campaign usage rules.
	 *
	 * @param string $encoded Persisted usage-rule JSON.
	 * @return array{counted_statuses:string[],refund_behavior:string}
	 * @throws RuntimeException When persisted rules are malformed.
	 */
	private function usage_rules( string $encoded ): array {
		try {
			$rules = json_decode( $encoded, true, 512, JSON_THROW_ON_ERROR );
			if ( ! is_array( $rules ) ) {
				throw new RuntimeException( 'Historical campaign rules must decode to an object.' );
			}
			$validated = CampaignConfiguration::from_arrays( $rules, array(), array() )->usage_rules();
		} catch ( JsonException | InvalidArgumentException $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is diagnostic context and is never rendered.
			throw new RuntimeException( 'Historical campaign rules are invalid.', 0, $exception );
		}

		return array(
			'counted_statuses' => $validated['counted_statuses'],
			'refund_behavior'  => $validated['refund_behavior'],
		);
	}

	/**
	 * Ensure the aggregate row exists before acquiring its unique-index lock.
	 *
	 * @param wpdb            $wpdb  WordPress database connection.
	 * @param HistoricalUsage $usage Usage being imported.
	 */
	private function ensure_state( wpdb $wpdb, HistoricalUsage $usage ): void {
		$now = current_time( 'mysql', true );
		$sql = $wpdb->prepare(
			'INSERT INTO %i
			 (campaign_id, customer_id, consumed_count, reserved_count, total_discount,
			  lock_version, created_at_gmt, updated_at_gmt)
			 VALUES (%d, %d, 0, 0, 0, 0, %s, %s)
			 ON DUPLICATE KEY UPDATE id = id',
			$this->tables->customer_campaign_state(),
			$usage->campaign_id,
			$usage->customer_id,
			$now,
			$now
		);
		$this->query( $wpdb, $sql, 'Could not initialize historical campaign state.' );
	}

	/**
	 * Lock authoritative aggregate state before the order/campaign usage.
	 *
	 * @param wpdb            $wpdb  WordPress database connection.
	 * @param HistoricalUsage $usage Usage being imported.
	 * @throws RuntimeException When state cannot be locked.
	 */
	private function lock_state( wpdb $wpdb, HistoricalUsage $usage ): void {
		$sql = $wpdb->prepare(
			'SELECT id FROM %i WHERE campaign_id = %d AND customer_id = %d LIMIT 1 FOR UPDATE',
			$this->tables->customer_campaign_state(),
			$usage->campaign_id,
			$usage->customer_id
		);
		if ( null === $sql ) {
			throw new RuntimeException( 'Could not prepare the historical campaign state lock.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Unique-index lock inside the import transaction.
		if ( null === $wpdb->get_var( $sql ) ) {
			throw new RuntimeException( 'Could not lock historical campaign state.' );
		}
	}

	/**
	 * Check the unique order/campaign row without mutating live usage.
	 *
	 * @param wpdb            $wpdb  WordPress database connection.
	 * @param HistoricalUsage $usage Usage being imported.
	 * @throws RuntimeException When the indexed lookup fails.
	 */
	private function usage_exists( wpdb $wpdb, HistoricalUsage $usage ): bool {
		$sql = $wpdb->prepare(
			'SELECT id FROM %i WHERE order_id = %d AND campaign_id = %d LIMIT 1 FOR UPDATE',
			$this->tables->usages(),
			$usage->order_id,
			$usage->campaign_id
		);
		if ( null === $sql ) {
			throw new RuntimeException( 'Could not prepare the historical usage lookup.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Unique order/campaign lookup inside the transaction.
		$id = $wpdb->get_var( $sql );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Could not read existing historical usage.' );
		}
		return null !== $id;
	}

	/**
	 * Insert one consumed or restored historical usage snapshot.
	 *
	 * @param wpdb            $wpdb  WordPress database connection.
	 * @param HistoricalUsage $usage Usage being imported.
	 * @throws RuntimeException When the usage insert fails.
	 */
	private function insert_usage( wpdb $wpdb, HistoricalUsage $usage ): void {
		$now      = current_time( 'mysql', true );
		$metadata = wp_json_encode(
			array(
				'refund_behavior'   => $usage->refund_behavior,
				'historical_import' => true,
			)
		);
		if ( false === $metadata ) {
			throw new RuntimeException( 'Could not encode historical usage metadata.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned insert inside a state-first transaction.
		$inserted = $wpdb->insert(
			$this->tables->usages(),
			array(
				'uuid'            => wp_generate_uuid4(),
				'campaign_id'     => $usage->campaign_id,
				'promotion_id'    => $usage->promotion_id,
				'customer_id'     => $usage->customer_id,
				'order_id'        => $usage->order_id,
				'order_item_id'   => $usage->order_item_id,
				'coupon_id'       => $usage->coupon_id,
				'coupon_code'     => $usage->coupon_code,
				'status'          => $usage->status,
				'order_status'    => $usage->order_status,
				'discount_amount' => $usage->discount_amount,
				'currency'        => $usage->currency,
				'reservation_key' => hash( 'sha256', 'historical:' . $usage->order_id . ':' . $usage->campaign_id ),
				'consumed_at_gmt' => $usage->consumed_at_gmt->format( 'Y-m-d H:i:s' ),
				'restored_at_gmt' => UsageStatus::RESTORED === $usage->status ? $now : null,
				'created_at_gmt'  => $now,
				'updated_at_gmt'  => $now,
				'metadata'        => $metadata,
			)
		);
		if ( false === $inserted ) {
			throw new RuntimeException( 'Could not create historical campaign usage.' );
		}
	}

	/**
	 * Add one newly imported consumed usage to locked aggregate state.
	 *
	 * @param wpdb            $wpdb  WordPress database connection.
	 * @param HistoricalUsage $usage Imported consumed usage.
	 * @throws RuntimeException When aggregate state cannot be updated.
	 */
	private function increment_state( wpdb $wpdb, HistoricalUsage $usage ): void {
		$consumed = $usage->consumed_at_gmt->format( 'Y-m-d H:i:s' );
		$now      = current_time( 'mysql', true );
		$sql      = $wpdb->prepare(
			'UPDATE %i
			 SET consumed_count = consumed_count + 1,
			     total_discount = total_discount + %s,
			     first_consumed_at_gmt = CASE
			       WHEN first_consumed_at_gmt IS NULL OR first_consumed_at_gmt > %s THEN %s
			       ELSE first_consumed_at_gmt END,
			     last_order_id = CASE
			       WHEN last_consumed_at_gmt IS NULL OR last_consumed_at_gmt <= %s THEN %d
			       ELSE last_order_id END,
			     last_consumed_at_gmt = CASE
			       WHEN last_consumed_at_gmt IS NULL OR last_consumed_at_gmt < %s THEN %s
			       ELSE last_consumed_at_gmt END,
			     lock_version = lock_version + 1,
			     updated_at_gmt = %s
			 WHERE campaign_id = %d AND customer_id = %d',
			$this->tables->customer_campaign_state(),
			$usage->discount_amount,
			$consumed,
			$consumed,
			$consumed,
			$usage->order_id,
			$consumed,
			$consumed,
			$now,
			$usage->campaign_id,
			$usage->customer_id
		);
		if ( 1 !== $this->query( $wpdb, $sql, 'Could not update historical campaign state.' ) ) {
			throw new RuntimeException( 'Historical campaign state changed before usage import.' );
		}
	}

	/**
	 * Execute a required prepared or transaction-control query.
	 *
	 * @param wpdb        $wpdb    WordPress database connection.
	 * @param string|null $sql     Prepared SQL.
	 * @param string      $message Safe failure message.
	 * @throws RuntimeException When preparation or execution fails.
	 */
	private function query( wpdb $wpdb, ?string $sql, string $message ): int {
		if ( null === $sql ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed internal message is never rendered.
			throw new RuntimeException( $message );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Internally prepared transaction query.
		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed internal message is never rendered.
			throw new RuntimeException( $message );
		}
		return true === $result ? 0 : $result;
	}
}
