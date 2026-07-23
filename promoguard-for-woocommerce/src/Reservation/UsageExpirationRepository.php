<?php
/**
 * Atomic expired reservation cleanup.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

use DateTimeImmutable;
use PromoGuard\Support\TableNames;
use RuntimeException;
use Throwable;
use wpdb;

/** Releases bounded expired usages while preserving authoritative counters. */
final class UsageExpirationRepository implements ExpirationStore {
	/**
	 * Configure site-scoped expiration persistence.
	 *
	 * @param TableNames $tables Site-scoped plugin table names.
	 */
	public function __construct( private readonly TableNames $tables ) {}

	/**
	 * Release expired reservations from a bounded candidate set.
	 *
	 * Candidate selection uses the status/expiry index. Each distinct state is
	 * then handled in its own short transaction so one busy customer cannot hold
	 * locks for the rest of the batch.
	 *
	 * @param DateTimeImmutable $expired_before_gmt Inclusive GMT expiry boundary.
	 * @param int               $candidate_limit    Maximum candidate rows to inspect.
	 * @throws Throwable When a state transition fails and is rolled back.
	 */
	public function release_expired( DateTimeImmutable $expired_before_gmt, int $candidate_limit ): int {
		global $wpdb;

		$candidates = $this->find_candidates( $wpdb, $expired_before_gmt, $candidate_limit );
		$released   = 0;

		foreach ( $candidates as $candidate ) {
			$released += $this->release_candidate( $wpdb, $expired_before_gmt, $candidate );
		}

		return $released;
	}

	/**
	 * Find a bounded set through the status/expiry index and deduplicate states.
	 *
	 * @param wpdb              $wpdb               WordPress database connection.
	 * @param DateTimeImmutable $expired_before_gmt Inclusive GMT expiry boundary.
	 * @param int               $candidate_limit    Maximum rows to inspect.
	 * @return array<int,array{campaign_id:int,customer_id:int}>
	 * @throws RuntimeException When the indexed candidate lookup fails.
	 */
	private function find_candidates(
		wpdb $wpdb,
		DateTimeImmutable $expired_before_gmt,
		int $candidate_limit
	): array {
		$sql = $wpdb->prepare(
			'SELECT campaign_id, customer_id FROM %i
			 WHERE status = %s AND reserved_until_gmt <= %s
			 ORDER BY reserved_until_gmt ASC
			 LIMIT %d',
			$this->tables->usages(),
			UsageStatus::PENDING,
			$expired_before_gmt->format( 'Y-m-d H:i:s' ),
			$candidate_limit
		);

		if ( null === $sql ) {
			throw new RuntimeException( 'Could not prepare the expired reservation lookup.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded indexed background lookup.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( 'Could not read expired reservation candidates.' );
		}

		$candidates = array();
		foreach ( $rows as $row ) {
			$campaign_id = (int) $row['campaign_id'];
			$customer_id = (int) $row['customer_id'];
			$key         = $campaign_id . ':' . $customer_id;

			$candidates[ $key ] = array(
				'campaign_id' => $campaign_id,
				'customer_id' => $customer_id,
			);
		}

		return array_values( $candidates );
	}

	/**
	 * Release all expired pending rows for one locked campaign/customer state.
	 *
	 * @param wpdb                                   $wpdb               WordPress database connection.
	 * @param DateTimeImmutable                      $expired_before_gmt Inclusive GMT expiry boundary.
	 * @param array{campaign_id:int,customer_id:int} $candidate       State identity.
	 * @throws Throwable When the transaction fails and is rolled back.
	 */
	private function release_candidate(
		wpdb $wpdb,
		DateTimeImmutable $expired_before_gmt,
		array $candidate
	): int {
		$transaction_open = false;

		try {
			$this->query( $wpdb, 'START TRANSACTION', 'Could not start the expiration transaction.' );
			$transaction_open = true;
			$this->lock_state( $wpdb, $candidate );

			$released = $this->release_locked_usages( $wpdb, $expired_before_gmt, $candidate );
			if ( $released > 0 ) {
				$this->decrement_reserved_count( $wpdb, $expired_before_gmt, $candidate, $released );
			}

			$this->query( $wpdb, 'COMMIT', 'Could not commit the expiration transaction.' );
			$transaction_open = false;
			return $released;
		} catch ( Throwable $exception ) {
			if ( $transaction_open ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required transaction rollback.
				$wpdb->query( 'ROLLBACK' );
			}

			throw $exception;
		}
	}

	/**
	 * Lock the authoritative state before touching its usage rows.
	 *
	 * @param wpdb                                   $wpdb      WordPress database connection.
	 * @param array{campaign_id:int,customer_id:int} $candidate State identity.
	 * @throws RuntimeException When the state row cannot be locked.
	 */
	private function lock_state( wpdb $wpdb, array $candidate ): void {
		$sql = $wpdb->prepare(
			'SELECT id FROM %i
			 WHERE campaign_id = %d AND customer_id = %d
			 LIMIT 1 FOR UPDATE',
			$this->tables->customer_campaign_state(),
			$candidate['campaign_id'],
			$candidate['customer_id']
		);

		if ( null === $sql ) {
			throw new RuntimeException( 'Could not prepare the expiration state lock.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Unique state lock preserves reservation lock order.
		$state_id = $wpdb->get_var( $sql );
		if ( null === $state_id ) {
			throw new RuntimeException( 'Expired reservation is missing customer campaign state.' );
		}
	}

	/**
	 * Release expired pending rows after their state is locked.
	 *
	 * @param wpdb                                   $wpdb               WordPress database connection.
	 * @param DateTimeImmutable                      $expired_before_gmt Inclusive GMT expiry boundary.
	 * @param array{campaign_id:int,customer_id:int} $candidate       State identity.
	 */
	private function release_locked_usages(
		wpdb $wpdb,
		DateTimeImmutable $expired_before_gmt,
		array $candidate
	): int {
		$time = $expired_before_gmt->format( 'Y-m-d H:i:s' );
		$sql  = $wpdb->prepare(
			'UPDATE %i
			 SET status = %s, released_at_gmt = %s, updated_at_gmt = %s
			 WHERE campaign_id = %d AND customer_id = %d
			   AND status = %s AND reserved_until_gmt <= %s',
			$this->tables->usages(),
			UsageStatus::RELEASED,
			$time,
			$time,
			$candidate['campaign_id'],
			$candidate['customer_id'],
			UsageStatus::PENDING,
			$time
		);

		return $this->query( $wpdb, $sql, 'Could not release expired reservations.' );
	}

	/**
	 * Reconcile the locked counter by the exact number of rows changed.
	 *
	 * @param wpdb                                   $wpdb               WordPress database connection.
	 * @param DateTimeImmutable                      $expired_before_gmt Batch GMT boundary.
	 * @param array{campaign_id:int,customer_id:int} $candidate       State identity.
	 * @param int                                    $released           Changed usage count.
	 * @throws RuntimeException When the state counter cannot be reconciled.
	 */
	private function decrement_reserved_count(
		wpdb $wpdb,
		DateTimeImmutable $expired_before_gmt,
		array $candidate,
		int $released
	): void {
		$sql = $wpdb->prepare(
			'UPDATE %i
			 SET reserved_count = GREATEST(reserved_count - %d, 0),
			     lock_version = lock_version + 1,
			     updated_at_gmt = %s
			 WHERE campaign_id = %d AND customer_id = %d',
			$this->tables->customer_campaign_state(),
			$released,
			$expired_before_gmt->format( 'Y-m-d H:i:s' ),
			$candidate['campaign_id'],
			$candidate['customer_id']
		);

		if ( 1 !== $this->query( $wpdb, $sql, 'Could not reconcile expired reservation counters.' ) ) {
			throw new RuntimeException( 'Customer campaign state changed during expiration cleanup.' );
		}
	}

	/**
	 * Execute one prepared transaction query.
	 *
	 * @param wpdb        $wpdb    WordPress database connection.
	 * @param string|null $sql     Prepared SQL.
	 * @param string      $message Safe internal failure message.
	 * @throws RuntimeException When preparation or execution fails.
	 */
	private function query( wpdb $wpdb, ?string $sql, string $message ): int {
		if ( null === $sql ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal constant message is never rendered.
			throw new RuntimeException( $message );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Transactional repository receives only internally prepared SQL.
		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal constant message is never rendered.
			throw new RuntimeException( $message );
		}

		return true === $result ? 0 : $result;
	}
}
