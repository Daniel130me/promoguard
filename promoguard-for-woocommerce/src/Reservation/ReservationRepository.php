<?php
/**
 * Atomic reservation repository.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

use RuntimeException;
use Throwable;
use wpdb;
use PromoGuard\Support\TableNames;

/** Persists reservations through short, state-locked InnoDB transactions. */
final class ReservationRepository implements ReservationStore {
	/**
	 * Configure site-scoped reservation tables.
	 *
	 * @param TableNames $tables Site-scoped plugin table names.
	 */
	public function __construct( private readonly TableNames $tables ) {}

	/** Build a repository for the active WordPress site. */
	public static function from_wordpress(): self {
		return new self( TableNames::from_wordpress() );
	}

	/**
	 * Reserve one order/campaign usage atomically.
	 *
	 * @param ReservationRequest $request Validated reservation facts.
	 * @throws ReservationLimitException When committed usage meets the limit.
	 * @throws Throwable When an unexpected dependency failure is rolled back.
	 */
	public function reserve( ReservationRequest $request ): ReservationResult {
		global $wpdb;

		$transaction_open = false;

		try {
			$this->query( $wpdb, 'START TRANSACTION', 'Could not start the reservation transaction.' );
			$transaction_open = true;

			$this->ensure_state( $wpdb, $request );
			$state    = $this->lock_state( $wpdb, $request );
			$released = $this->release_expired( $wpdb, $request );

			if ( $released > 0 ) {
				$this->decrement_reserved_count( $wpdb, $request, $released );
				$state['reserved_count'] = max( 0, $state['reserved_count'] - $released );
			}

			$existing = $this->find_existing_usage( $wpdb, $request );
			if ( null !== $existing && in_array( $existing['status'], array( UsageStatus::PENDING, UsageStatus::CONSUMED ), true ) ) {
				$this->query( $wpdb, 'COMMIT', 'Could not commit the existing reservation.' );
				$transaction_open = false;

				return new ReservationResult( $existing['id'], $existing['status'], false );
			}

			if ( $state['consumed_count'] + $state['reserved_count'] >= $request->maximum_uses ) {
				throw new ReservationLimitException( 'Customer campaign usage limit reached.' );
			}

			$created  = null === $existing;
			$usage_id = $created
				? $this->insert_usage( $wpdb, $request )
				: $this->reactivate_usage( $wpdb, $request, $existing );

			$this->increment_reserved_count( $wpdb, $request );
			$this->query( $wpdb, 'COMMIT', 'Could not commit the reservation.' );
			$transaction_open = false;

			return new ReservationResult( $usage_id, UsageStatus::PENDING, $created );
		} catch ( Throwable $exception ) {
			if ( $transaction_open ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required transaction rollback.
				$wpdb->query( 'ROLLBACK' );
			}

			throw $exception;
		}
	}

	/**
	 * Ensure the unique campaign/customer state row exists before locking it.
	 *
	 * @param wpdb               $wpdb   WordPress database connection.
	 * @param ReservationRequest $request Validated reservation facts.
	 * @throws RuntimeException When state initialization fails.
	 */
	private function ensure_state( wpdb $wpdb, ReservationRequest $request ): void {
		$table = $this->tables->customer_campaign_state();
		$time  = $request->reserved_at_gmt->format( 'Y-m-d H:i:s' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned table and fixed columns; values are prepared.
		$sql = $wpdb->prepare(
			'INSERT INTO %i
				(campaign_id, customer_id, consumed_count, reserved_count, total_discount, lock_version, created_at_gmt, updated_at_gmt)
			 VALUES (%d, %d, 0, 0, 0, 0, %s, %s)
			 ON DUPLICATE KEY UPDATE id = id',
			$table,
			$request->campaign_id,
			$request->customer_id,
			$time,
			$time
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$this->query( $wpdb, $sql, 'Could not initialize customer campaign state.' );
	}

	/**
	 * Lock and return the authoritative campaign/customer counters.
	 *
	 * @param wpdb               $wpdb   WordPress database connection.
	 * @param ReservationRequest $request Validated reservation facts.
	 * @return array{consumed_count:int,reserved_count:int}
	 * @throws RuntimeException When the state row cannot be locked.
	 */
	private function lock_state( wpdb $wpdb, ReservationRequest $request ): array {
		$table = $this->tables->customer_campaign_state();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned table and fixed columns; indexed IDs are prepared.
		$sql = $wpdb->prepare(
			'SELECT consumed_count, reserved_count FROM %i
			 WHERE campaign_id = %d AND customer_id = %d
			 LIMIT 1 FOR UPDATE',
			$table,
			$request->campaign_id,
			$request->customer_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		if ( null === $sql ) {
			throw new RuntimeException( 'Could not prepare the customer campaign state lock.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Required unique-index row lock.
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception is never rendered directly.
			throw $this->database_exception( $wpdb, 'Could not lock customer campaign state.' );
		}

		return array(
			'consumed_count' => (int) $row['consumed_count'],
			'reserved_count' => (int) $row['reserved_count'],
		);
	}

	/**
	 * Release expired pending usages while their campaign/customer state is locked.
	 *
	 * @param wpdb               $wpdb   WordPress database connection.
	 * @param ReservationRequest $request Validated reservation facts.
	 * @throws RuntimeException When expired usages cannot be released.
	 */
	private function release_expired( wpdb $wpdb, ReservationRequest $request ): int {
		$table = $this->tables->usages();
		$time  = $request->reserved_at_gmt->format( 'Y-m-d H:i:s' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned table and fixed values; lifecycle facts are prepared.
		$sql = $wpdb->prepare(
			'UPDATE %i
			 SET status = %s, released_at_gmt = %s, updated_at_gmt = %s
			 WHERE campaign_id = %d AND customer_id = %d
			   AND status = %s AND reserved_until_gmt <= %s',
			$table,
			UsageStatus::RELEASED,
			$time,
			$time,
			$request->campaign_id,
			$request->customer_id,
			UsageStatus::PENDING,
			$time
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return $this->query( $wpdb, $sql, 'Could not release expired reservations.' );
	}

	/**
	 * Keep the state counter aligned with rows released in this transaction.
	 *
	 * @param wpdb               $wpdb    WordPress database connection.
	 * @param ReservationRequest $request Validated reservation facts.
	 * @param int                $released Number of released usages.
	 * @throws RuntimeException When the state counter cannot be updated.
	 */
	private function decrement_reserved_count( wpdb $wpdb, ReservationRequest $request, int $released ): void {
		$table = $this->tables->customer_campaign_state();
		$time  = $request->reserved_at_gmt->format( 'Y-m-d H:i:s' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned table and fixed arithmetic; values are prepared.
		$sql = $wpdb->prepare(
			'UPDATE %i
			 SET reserved_count = GREATEST(reserved_count - %d, 0),
			     lock_version = lock_version + 1,
			     updated_at_gmt = %s
			 WHERE campaign_id = %d AND customer_id = %d',
			$table,
			$released,
			$time,
			$request->campaign_id,
			$request->customer_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$this->query( $wpdb, $sql, 'Could not reconcile released reservation counters.' );
	}

	/**
	 * Find the idempotent order/campaign usage under the current transaction.
	 *
	 * @param wpdb               $wpdb   WordPress database connection.
	 * @param ReservationRequest $request Validated reservation facts.
	 * @return array{id:int,status:string}|null
	 * @throws RuntimeException When the indexed usage lookup fails.
	 */
	private function find_existing_usage( wpdb $wpdb, ReservationRequest $request ): ?array {
		$table = $this->tables->usages();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned table and fixed columns; unique-index values are prepared.
		$sql = $wpdb->prepare(
			'SELECT id, status FROM %i WHERE order_id = %d AND campaign_id = %d LIMIT 1 FOR UPDATE',
			$table,
			$request->order_id,
			$request->campaign_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		if ( null === $sql ) {
			throw new RuntimeException( 'Could not prepare the existing usage lookup.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Required unique-index idempotency lock.
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( null === $row ) {
			if ( '' !== $wpdb->last_error ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception is never rendered directly.
				throw $this->database_exception( $wpdb, 'Could not read the existing order campaign usage.' );
			}

			return null;
		}

		return array(
			'id'     => (int) $row['id'],
			'status' => (string) $row['status'],
		);
	}

	/**
	 * Insert one new pending usage row.
	 *
	 * @param wpdb               $wpdb   WordPress database connection.
	 * @param ReservationRequest $request Validated reservation facts.
	 * @throws RuntimeException When the usage cannot be inserted.
	 */
	private function insert_usage( wpdb $wpdb, ReservationRequest $request ): int {
		$table = $this->tables->usages();
		$time  = $request->reserved_at_gmt->format( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned insert inside the locked transaction.
		$inserted = $wpdb->insert(
			$table,
			array(
				'uuid'               => $request->uuid,
				'campaign_id'        => $request->campaign_id,
				'promotion_id'       => $request->promotion_id,
				'customer_id'        => $request->customer_id,
				'order_id'           => $request->order_id,
				'coupon_id'          => $request->coupon_id,
				'coupon_code'        => $request->coupon_code,
				'status'             => UsageStatus::PENDING,
				'discount_amount'    => '0',
				'currency'           => $request->currency,
				'reservation_key'    => $request->reservation_key,
				'reserved_until_gmt' => $request->reserved_until_gmt->format( 'Y-m-d H:i:s' ),
				'reserved_at_gmt'    => $time,
				'created_at_gmt'     => $time,
				'updated_at_gmt'     => $time,
				'metadata'           => '{}',
			)
		);

		if ( false === $inserted || $wpdb->insert_id < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception is never rendered directly.
			throw $this->database_exception( $wpdb, 'Could not create the usage reservation.' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Re-activate the same order/campaign after its prior reservation expired.
	 *
	 * @param wpdb                        $wpdb     WordPress database connection.
	 * @param ReservationRequest          $request  Validated reservation facts.
	 * @param array{id:int,status:string} $existing Existing usage row.
	 * @throws RuntimeException When the usage cannot be reactivated.
	 */
	private function reactivate_usage( wpdb $wpdb, ReservationRequest $request, array $existing ): int {
		if ( UsageStatus::RELEASED !== $existing['status'] ) {
			throw new RuntimeException( 'Existing usage status cannot be reserved.' );
		}

		$time = $request->reserved_at_gmt->format( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned update inside the locked transaction.
		$updated = $wpdb->update(
			$this->tables->usages(),
			array(
				'promotion_id'       => $request->promotion_id,
				'customer_id'        => $request->customer_id,
				'coupon_id'          => $request->coupon_id,
				'coupon_code'        => $request->coupon_code,
				'status'             => UsageStatus::PENDING,
				'currency'           => $request->currency,
				'reservation_key'    => $request->reservation_key,
				'reserved_until_gmt' => $request->reserved_until_gmt->format( 'Y-m-d H:i:s' ),
				'reserved_at_gmt'    => $time,
				'released_at_gmt'    => null,
				'updated_at_gmt'     => $time,
			),
			array(
				'id'     => $existing['id'],
				'status' => UsageStatus::RELEASED,
			)
		);

		if ( false === $updated ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception is never rendered directly.
			throw $this->database_exception( $wpdb, 'Could not reactivate the usage reservation.' );
		}

		if ( 1 !== $updated ) {
			throw new RuntimeException( 'Existing usage changed before reservation reactivation.' );
		}

		return $existing['id'];
	}

	/**
	 * Increment the locked reserved counter exactly once.
	 *
	 * @param wpdb               $wpdb   WordPress database connection.
	 * @param ReservationRequest $request Validated reservation facts.
	 * @throws RuntimeException When the state counter cannot be updated.
	 */
	private function increment_reserved_count( wpdb $wpdb, ReservationRequest $request ): void {
		$table = $this->tables->customer_campaign_state();
		$time  = $request->reserved_at_gmt->format( 'Y-m-d H:i:s' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned table and fixed arithmetic; values are prepared.
		$sql = $wpdb->prepare(
			'UPDATE %i
			 SET reserved_count = reserved_count + 1,
			     lock_version = lock_version + 1,
			     updated_at_gmt = %s
			 WHERE campaign_id = %d AND customer_id = %d',
			$table,
			$time,
			$request->campaign_id,
			$request->customer_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$updated = $this->query( $wpdb, $sql, 'Could not increment the reservation counter.' );

		if ( 1 !== $updated ) {
			throw new RuntimeException( 'Customer campaign state changed before reservation increment.' );
		}
	}

	/**
	 * Execute one transaction query and normalize database failures.
	 *
	 * @param wpdb        $wpdb    WordPress database connection.
	 * @param string|null $sql     Prepared transaction SQL.
	 * @param string      $message Safe internal failure message.
	 * @throws RuntimeException When preparation or execution fails.
	 */
	private function query( wpdb $wpdb, ?string $sql, string $message ): int {
		if ( null === $sql ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal constant message is never rendered directly.
			throw new RuntimeException( $message );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Transactional repository receives only internally built SQL.
		$result = $wpdb->query( $sql );

		if ( false === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception is never rendered directly.
			throw $this->database_exception( $wpdb, $message );
		}

		return true === $result ? 0 : $result;
	}

	/**
	 * Classify only MySQL contention failures as safe to retry.
	 *
	 * @param wpdb   $wpdb    WordPress database connection.
	 * @param string $message Safe internal failure message.
	 */
	private function database_exception( wpdb $wpdb, string $message ): RuntimeException {
		$error = strtolower( $wpdb->last_error );

		if ( str_contains( $error, 'deadlock' ) || str_contains( $error, 'lock wait timeout' ) ) {
			return new RetryableReservationException( $message );
		}

		return new RuntimeException( $message );
	}
}
