<?php
/**
 * Atomic usage lifecycle repository.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

use PromoGuard\Support\TableNames;
use RuntimeException;
use Throwable;
use wpdb;

/** Consumes and releases reservations through state-first InnoDB locking. */
final class UsageLifecycleRepository implements UsageLifecycleStore {
	/**
	 * Configure site-scoped lifecycle persistence.
	 *
	 * @param TableNames $tables Site-scoped plugin table names.
	 */
	public function __construct( private readonly TableNames $tables ) {}

	/** Build a repository for the active WordPress site. */
	public static function from_wordpress(): self {
		return new self( TableNames::from_wordpress() );
	}

	/**
	 * Consume a pending reservation once.
	 *
	 * @param UsageTransition $transition Validated transition facts.
	 * @throws Throwable When the atomic transition fails and is rolled back.
	 */
	public function consume( UsageTransition $transition ): bool {
		return $this->apply( $transition, UsageStatus::CONSUMED );
	}

	/**
	 * Release a pending reservation once.
	 *
	 * @param UsageTransition $transition Validated transition facts.
	 * @throws Throwable When the atomic transition fails and is rolled back.
	 */
	public function release( UsageTransition $transition ): bool {
		return $this->apply( $transition, UsageStatus::RELEASED );
	}

	/**
	 * Apply one state-first lifecycle transition.
	 *
	 * @param UsageTransition $transition Validated transition facts.
	 * @param string          $target_status Consumed or released target status.
	 * @throws Throwable When the atomic transition fails and is rolled back.
	 */
	private function apply( UsageTransition $transition, string $target_status ): bool {
		global $wpdb;

		$customer_id = $this->find_usage_customer( $wpdb, $transition );
		if ( null === $customer_id ) {
			return false;
		}

		$transaction_open = false;

		try {
			$this->query( $wpdb, 'START TRANSACTION', 'Could not start the usage lifecycle transaction.' );
			$transaction_open = true;

			$this->lock_state( $wpdb, $transition->campaign_id, $customer_id );
			$usage = $this->lock_usage( $wpdb, $transition, $customer_id );

			if ( null === $usage || UsageStatus::PENDING !== $usage['status'] ) {
				$this->query( $wpdb, 'COMMIT', 'Could not commit the unchanged usage lifecycle transaction.' );
				$transaction_open = false;
				return false;
			}

			$changed = UsageStatus::CONSUMED === $target_status
				? $this->consume_locked( $wpdb, $transition, $customer_id, $usage['id'] )
				: $this->release_locked( $wpdb, $transition, $customer_id, $usage['id'] );

			$this->query( $wpdb, 'COMMIT', 'Could not commit the usage lifecycle transition.' );
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
	 * Pre-read the immutable usage/customer link before state-first locking.
	 *
	 * @param wpdb            $wpdb       WordPress database connection.
	 * @param UsageTransition $transition Validated transition facts.
	 * @throws RuntimeException When the indexed customer lookup fails.
	 */
	private function find_usage_customer( wpdb $wpdb, UsageTransition $transition ): ?int {
		$sql = $wpdb->prepare(
			'SELECT customer_id FROM %i WHERE order_id = %d AND campaign_id = %d LIMIT 1',
			$this->tables->usages(),
			$transition->order_id,
			$transition->campaign_id
		);

		if ( null === $sql ) {
			throw new RuntimeException( 'Could not prepare the usage customer lookup.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Unique order/campaign lookup establishes deterministic state-first lock order.
		$customer_id = $wpdb->get_var( $sql );

		if ( null === $customer_id ) {
			if ( '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'Could not read the usage customer.' );
			}

			return null;
		}

		return (int) $customer_id;
	}

	/**
	 * Lock the authoritative campaign/customer counter row.
	 *
	 * @param wpdb $wpdb        WordPress database connection.
	 * @param int  $campaign_id Campaign ID.
	 * @param int  $customer_id Internal customer ID.
	 * @throws RuntimeException When the state row cannot be locked.
	 */
	private function lock_state( wpdb $wpdb, int $campaign_id, int $customer_id ): void {
		$sql = $wpdb->prepare(
			'SELECT id FROM %i WHERE campaign_id = %d AND customer_id = %d LIMIT 1 FOR UPDATE',
			$this->tables->customer_campaign_state(),
			$campaign_id,
			$customer_id
		);

		if ( null === $sql ) {
			throw new RuntimeException( 'Could not prepare the lifecycle state lock.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Unique campaign/customer row lock protects counters.
		$state_id = $wpdb->get_var( $sql );

		if ( null === $state_id ) {
			throw new RuntimeException( 'Reserved usage is missing customer campaign state.' );
		}
	}

	/**
	 * Lock the usage after its authoritative state.
	 *
	 * @param wpdb            $wpdb        WordPress database connection.
	 * @param UsageTransition $transition  Validated transition facts.
	 * @param int             $customer_id Pre-read internal customer ID.
	 * @return array{id:int,status:string}|null
	 * @throws RuntimeException When the usage row cannot be locked.
	 */
	private function lock_usage( wpdb $wpdb, UsageTransition $transition, int $customer_id ): ?array {
		$sql = $wpdb->prepare(
			'SELECT id, status FROM %i
			 WHERE order_id = %d AND campaign_id = %d AND customer_id = %d
			 LIMIT 1 FOR UPDATE',
			$this->tables->usages(),
			$transition->order_id,
			$transition->campaign_id,
			$customer_id
		);

		if ( null === $sql ) {
			throw new RuntimeException( 'Could not prepare the lifecycle usage lock.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Unique order/campaign row lock follows the state lock.
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( null === $row ) {
			if ( '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'Could not lock the usage lifecycle row.' );
			}

			return null;
		}

		return array(
			'id'     => (int) $row['id'],
			'status' => (string) $row['status'],
		);
	}

	/**
	 * Consume the locked usage and update counters exactly once.
	 *
	 * @param wpdb            $wpdb        WordPress database connection.
	 * @param UsageTransition $transition  Validated transition facts.
	 * @param int             $customer_id Internal customer ID.
	 * @param int             $usage_id    Locked usage ID.
	 * @throws RuntimeException When consumption persistence fails.
	 */
	private function consume_locked(
		wpdb $wpdb,
		UsageTransition $transition,
		int $customer_id,
		int $usage_id
	): bool {
		$time          = $transition->occurred_at_gmt->format( 'Y-m-d H:i:s' );
		$sql           = $wpdb->prepare(
			'UPDATE %i
			 SET status = %s, order_status = %s, discount_amount = %s,
			     consumed_at_gmt = %s, updated_at_gmt = %s
			 WHERE id = %d AND status = %s',
			$this->tables->usages(),
			UsageStatus::CONSUMED,
			$transition->order_status,
			$transition->discount_amount,
			$time,
			$time,
			$usage_id,
			UsageStatus::PENDING
		);
		$usage_changed = $this->query( $wpdb, $sql, 'Could not consume the pending usage.' );
		if ( 1 !== $usage_changed ) {
			return false;
		}

		$state_sql = $wpdb->prepare(
			'UPDATE %i
			 SET reserved_count = GREATEST(reserved_count - 1, 0),
			     consumed_count = consumed_count + 1,
			     total_discount = total_discount + %s,
			     first_consumed_at_gmt = COALESCE(first_consumed_at_gmt, %s),
			     last_consumed_at_gmt = %s,
			     last_order_id = %d,
			     lock_version = lock_version + 1,
			     updated_at_gmt = %s
			 WHERE campaign_id = %d AND customer_id = %d',
			$this->tables->customer_campaign_state(),
			$transition->discount_amount,
			$time,
			$time,
			$transition->order_id,
			$time,
			$transition->campaign_id,
			$customer_id
		);

		if ( 1 !== $this->query( $wpdb, $state_sql, 'Could not update consumed usage counters.' ) ) {
			throw new RuntimeException( 'Customer campaign state changed during usage consumption.' );
		}

		return true;
	}

	/**
	 * Release the locked usage and decrement its reservation once.
	 *
	 * @param wpdb            $wpdb        WordPress database connection.
	 * @param UsageTransition $transition  Validated transition facts.
	 * @param int             $customer_id Internal customer ID.
	 * @param int             $usage_id    Locked usage ID.
	 * @throws RuntimeException When release persistence fails.
	 */
	private function release_locked(
		wpdb $wpdb,
		UsageTransition $transition,
		int $customer_id,
		int $usage_id
	): bool {
		$time          = $transition->occurred_at_gmt->format( 'Y-m-d H:i:s' );
		$sql           = $wpdb->prepare(
			'UPDATE %i
			 SET status = %s, order_status = %s, released_at_gmt = %s, updated_at_gmt = %s
			 WHERE id = %d AND status = %s',
			$this->tables->usages(),
			UsageStatus::RELEASED,
			$transition->order_status,
			$time,
			$time,
			$usage_id,
			UsageStatus::PENDING
		);
		$usage_changed = $this->query( $wpdb, $sql, 'Could not release the pending usage.' );
		if ( 1 !== $usage_changed ) {
			return false;
		}

		$state_sql = $wpdb->prepare(
			'UPDATE %i
			 SET reserved_count = GREATEST(reserved_count - 1, 0),
			     lock_version = lock_version + 1,
			     updated_at_gmt = %s
			 WHERE campaign_id = %d AND customer_id = %d',
			$this->tables->customer_campaign_state(),
			$time,
			$transition->campaign_id,
			$customer_id
		);

		if ( 1 !== $this->query( $wpdb, $state_sql, 'Could not update released usage counters.' ) ) {
			throw new RuntimeException( 'Customer campaign state changed during usage release.' );
		}

		return true;
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
