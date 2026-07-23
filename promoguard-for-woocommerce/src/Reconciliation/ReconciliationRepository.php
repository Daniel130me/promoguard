<?php
/**
 * Usage-ledger reconciliation repository.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reconciliation;

use DateTimeImmutable;
use PromoGuard\Reservation\UsageStatus;
use PromoGuard\Support\TableNames;
use RuntimeException;
use Throwable;
use wpdb;

/** Rebuilds bounded customer/campaign aggregates with state-first locks. */
final class ReconciliationRepository implements ReconciliationStore {
	/**
	 * Configure site-scoped reconciliation persistence.
	 *
	 * @param TableNames $tables Site-scoped plugin table names.
	 */
	public function __construct( private readonly TableNames $tables ) {}

	/**
	 * Reconcile a primary-key page through short per-state transactions.
	 *
	 * @param DateTimeImmutable $as_of_gmt Inclusive reconciliation boundary.
	 * @param int               $after_id  Last state ID processed.
	 * @param int               $limit     Maximum state rows to inspect.
	 * @throws Throwable When a state transaction fails and is rolled back.
	 */
	public function reconcile_after(
		DateTimeImmutable $as_of_gmt,
		int $after_id,
		int $limit
	): ReconciliationBatch {
		global $wpdb;

		$targets = $this->targets_after( $wpdb, $after_id, $limit );
		$changed = 0;
		foreach ( $targets as $target ) {
			$changed += $this->reconcile_target( $wpdb, $as_of_gmt, $target );
		}

		$processed   = count( $targets );
		$next_cursor = $processed === $limit
			? $targets[ $processed - 1 ]['id']
			: null;

		return new ReconciliationBatch( $processed, $changed, $next_cursor );
	}

	/**
	 * Read a bounded state page through its primary key.
	 *
	 * @param wpdb $wpdb     WordPress database connection.
	 * @param int  $after_id Last state ID processed.
	 * @param int  $limit    Maximum targets to read.
	 * @return array<int,array{id:int,campaign_id:int,customer_id:int}>
	 * @throws RuntimeException When targets cannot be read.
	 */
	private function targets_after( wpdb $wpdb, int $after_id, int $limit ): array {
		$sql = $wpdb->prepare(
			'SELECT id, campaign_id, customer_id FROM %i
			 WHERE id > %d ORDER BY id ASC LIMIT %d',
			$this->tables->customer_campaign_state(),
			$after_id,
			$limit
		);
		if ( null === $sql ) {
			throw new RuntimeException( 'Could not prepare the reconciliation target lookup.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded primary-key background scan.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Could not read reconciliation targets.' );
		}

		return array_map(
			static fn( array $row ): array => array(
				'id'          => (int) $row['id'],
				'campaign_id' => (int) $row['campaign_id'],
				'customer_id' => (int) $row['customer_id'],
			),
			$rows
		);
	}

	/**
	 * Rebuild one aggregate while holding its authoritative state lock.
	 *
	 * @param wpdb                                          $wpdb      WordPress database connection.
	 * @param DateTimeImmutable                             $as_of_gmt Reconciliation boundary.
	 * @param array{id:int,campaign_id:int,customer_id:int} $target    State identity.
	 * @throws Throwable When the transaction fails and is rolled back.
	 */
	private function reconcile_target( wpdb $wpdb, DateTimeImmutable $as_of_gmt, array $target ): int {
		$transaction_open = false;

		try {
			$this->query( $wpdb, 'START TRANSACTION', 'Could not start the reconciliation transaction.' );
			$transaction_open = true;
			$this->lock_state( $wpdb, $target );
			$totals  = $this->usage_totals( $wpdb, $as_of_gmt, $target );
			$changed = $this->update_state( $wpdb, $as_of_gmt, $target, $totals );
			$this->query( $wpdb, 'COMMIT', 'Could not commit the reconciliation transaction.' );
			$transaction_open = false;
			return $changed;
		} catch ( Throwable $exception ) {
			if ( $transaction_open ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required transaction rollback.
				$wpdb->query( 'ROLLBACK' );
			}

			throw $exception;
		}
	}

	/**
	 * Lock the state before reading usages that lifecycle writes protect with it.
	 *
	 * @param wpdb                                          $wpdb   WordPress database connection.
	 * @param array{id:int,campaign_id:int,customer_id:int} $target State identity.
	 * @throws RuntimeException When the state cannot be locked.
	 */
	private function lock_state( wpdb $wpdb, array $target ): void {
		$sql = $wpdb->prepare(
			'SELECT id FROM %i WHERE id = %d LIMIT 1 FOR UPDATE',
			$this->tables->customer_campaign_state(),
			$target['id']
		);
		if ( null === $sql ) {
			throw new RuntimeException( 'Could not prepare the reconciliation state lock.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Primary-key aggregate lock.
		if ( null === $wpdb->get_var( $sql ) ) {
			throw new RuntimeException( 'Reconciliation state no longer exists.' );
		}
	}

	/**
	 * Derive exact counters from the usage ledger under the state lock.
	 *
	 * @param wpdb                                          $wpdb      WordPress database connection.
	 * @param DateTimeImmutable                             $as_of_gmt Reconciliation boundary.
	 * @param array{id:int,campaign_id:int,customer_id:int} $target    State identity.
	 * @return array{consumed_count:int,reserved_count:int,total_discount:string}
	 * @throws RuntimeException When usage totals cannot be read.
	 */
	private function usage_totals( wpdb $wpdb, DateTimeImmutable $as_of_gmt, array $target ): array {
		$sql = $wpdb->prepare(
			'SELECT
			    COALESCE(SUM(status = %s), 0) AS consumed_count,
			    COALESCE(SUM(status = %s AND (reserved_until_gmt IS NULL OR reserved_until_gmt > %s)), 0) AS reserved_count,
			    COALESCE(SUM(CASE WHEN status = %s THEN discount_amount ELSE 0 END), 0) AS total_discount
			 FROM %i WHERE campaign_id = %d AND customer_id = %d',
			UsageStatus::CONSUMED,
			UsageStatus::PENDING,
			$as_of_gmt->format( 'Y-m-d H:i:s' ),
			UsageStatus::CONSUMED,
			$this->tables->usages(),
			$target['campaign_id'],
			$target['customer_id']
		);
		if ( null === $sql ) {
			throw new RuntimeException( 'Could not prepare usage reconciliation totals.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Indexed aggregate runs after the state lock.
		$row = $wpdb->get_row( $sql, ARRAY_A );
		if ( ! is_array( $row ) || '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Could not read usage reconciliation totals.' );
		}

		return array(
			'consumed_count' => (int) $row['consumed_count'],
			'reserved_count' => (int) $row['reserved_count'],
			'total_discount' => (string) $row['total_discount'],
		);
	}

	/**
	 * Persist changed counters and leave matching state untouched.
	 *
	 * @param wpdb                                                               $wpdb      WordPress database connection.
	 * @param DateTimeImmutable                                                  $as_of_gmt Reconciliation boundary.
	 * @param array{id:int,campaign_id:int,customer_id:int}                      $target    State identity.
	 * @param array{consumed_count:int,reserved_count:int,total_discount:string} $totals    Derived ledger totals.
	 */
	private function update_state(
		wpdb $wpdb,
		DateTimeImmutable $as_of_gmt,
		array $target,
		array $totals
	): int {
		$sql = $wpdb->prepare(
			'UPDATE %i
			 SET consumed_count = %d, reserved_count = %d, total_discount = %s,
			     lock_version = lock_version + 1, updated_at_gmt = %s
			 WHERE id = %d
			   AND (consumed_count <> %d OR reserved_count <> %d OR total_discount <> %s)',
			$this->tables->customer_campaign_state(),
			$totals['consumed_count'],
			$totals['reserved_count'],
			$totals['total_discount'],
			$as_of_gmt->format( 'Y-m-d H:i:s' ),
			$target['id'],
			$totals['consumed_count'],
			$totals['reserved_count'],
			$totals['total_discount']
		);

		return $this->query( $wpdb, $sql, 'Could not update reconciled usage counters.' );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Transactional repository receives internally prepared SQL.
		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal constant message is never rendered.
			throw new RuntimeException( $message );
		}

		return true === $result ? 0 : $result;
	}
}
