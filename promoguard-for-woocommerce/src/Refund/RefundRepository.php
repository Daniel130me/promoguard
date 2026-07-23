<?php
/**
 * Refund context and restoration repository.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Refund;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use PromoGuard\Campaign\CampaignConfiguration;
use PromoGuard\Reservation\UsageStatus;
use PromoGuard\Support\TableNames;
use RuntimeException;
use Throwable;
use wpdb;

/** Reads refund policies and restores usage through state-first locking. */
final class RefundRepository implements RefundContextStore, RefundRestorationStore {
	/**
	 * Configure site-scoped refund persistence.
	 *
	 * @param TableNames $tables Site-scoped plugin table names.
	 */
	public function __construct( private readonly TableNames $tables ) {}

	/**
	 * Return consumed usage policies in one bounded order lookup.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return RefundUsageContext[]
	 * @throws RuntimeException When persisted refund contexts cannot be read.
	 */
	public function consumed_for_order( int $order_id ): array {
		global $wpdb;

		$sql = $wpdb->prepare(
			'SELECT usage_row.campaign_id, usage_row.metadata, campaign.usage_rules
			 FROM %i AS usage_row
			 INNER JOIN %i AS campaign ON campaign.id = usage_row.campaign_id
			 WHERE usage_row.order_id = %d AND usage_row.status = %s
			 ORDER BY usage_row.campaign_id ASC',
			$this->tables->usages(),
			$this->tables->campaigns(),
			$order_id,
			UsageStatus::CONSUMED
		);
		if ( null === $sql ) {
			throw new RuntimeException( 'Could not prepare the consumed refund context lookup.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded order/campaign lookup with a primary-key campaign join.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Could not read consumed refund contexts.' );
		}

		$contexts = array();
		foreach ( $rows as $row ) {
			$contexts[] = new RefundUsageContext(
				(int) $row['campaign_id'],
				$this->refund_behavior( (string) $row['metadata'], (string) $row['usage_rules'] )
			);
		}

		return $contexts;
	}

	/**
	 * Restore one consumed usage and its aggregate counters exactly once.
	 *
	 * @param int               $order_id        WooCommerce order ID.
	 * @param int               $campaign_id     Campaign ID.
	 * @param DateTimeImmutable $occurred_at_gmt Restoration time.
	 * @throws RuntimeException When restoration persistence fails.
	 * @throws Throwable When another restoration failure is rolled back.
	 */
	public function restore( int $order_id, int $campaign_id, DateTimeImmutable $occurred_at_gmt ): bool {
		global $wpdb;

		$customer_id = $this->find_usage_customer( $wpdb, $order_id, $campaign_id );
		if ( null === $customer_id ) {
			return false;
		}

		$transaction_open = false;
		try {
			$this->query( $wpdb, 'START TRANSACTION', 'Could not start the refund restoration transaction.' );
			$transaction_open = true;

			$this->lock_state( $wpdb, $campaign_id, $customer_id );
			$usage = $this->lock_usage( $wpdb, $order_id, $campaign_id, $customer_id );
			if ( null === $usage || UsageStatus::CONSUMED !== $usage['status'] ) {
				$this->query( $wpdb, 'COMMIT', 'Could not commit the unchanged refund transaction.' );
				$transaction_open = false;
				return false;
			}

			$time = $occurred_at_gmt->format( 'Y-m-d H:i:s' );
			$sql  = $wpdb->prepare(
				'UPDATE %i
				 SET status = %s, restored_at_gmt = %s, updated_at_gmt = %s
				 WHERE id = %d AND status = %s',
				$this->tables->usages(),
				UsageStatus::RESTORED,
				$time,
				$time,
				$usage['id'],
				UsageStatus::CONSUMED
			);
			if ( 1 !== $this->query( $wpdb, $sql, 'Could not restore the consumed usage.' ) ) {
				$this->query( $wpdb, 'COMMIT', 'Could not commit the unchanged refund transaction.' );
				$transaction_open = false;
				return false;
			}

			$state_sql = $wpdb->prepare(
				'UPDATE %i
				 SET consumed_count = GREATEST(consumed_count - 1, 0),
				     total_discount = GREATEST(total_discount - %s, 0),
				     lock_version = lock_version + 1,
				     updated_at_gmt = %s
				 WHERE campaign_id = %d AND customer_id = %d',
				$this->tables->customer_campaign_state(),
				$usage['discount_amount'],
				$time,
				$campaign_id,
				$customer_id
			);
			if ( 1 !== $this->query( $wpdb, $state_sql, 'Could not update restored usage counters.' ) ) {
				throw new RuntimeException( 'Customer campaign state changed during refund restoration.' );
			}

			$this->query( $wpdb, 'COMMIT', 'Could not commit the refund restoration.' );
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
	 * Prefer the immutable usage snapshot, with a legacy campaign fallback.
	 *
	 * @param string $encoded_metadata Usage metadata JSON.
	 * @param string $encoded_rules    Current campaign usage-rule JSON.
	 * @throws RuntimeException When the persisted policy cannot be hydrated.
	 */
	private function refund_behavior( string $encoded_metadata, string $encoded_rules ): string {
		try {
			$metadata = json_decode( $encoded_metadata, true, 512, JSON_THROW_ON_ERROR );
			if ( is_array( $metadata ) && isset( $metadata['refund_behavior'] ) ) {
				$behavior = (string) $metadata['refund_behavior'];
				if ( ! CampaignConfiguration::supports_refund_behavior( $behavior ) ) {
					throw new RuntimeException( 'Persisted refund policy is invalid.' );
				}

				return $behavior;
			}

			$rules = json_decode( $encoded_rules, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException | InvalidArgumentException $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is diagnostic context and is never rendered.
			throw new RuntimeException( 'Persisted refund policy is invalid.', 0, $exception );
		}

		if ( ! is_array( $rules ) ) {
			throw new RuntimeException( 'Persisted campaign rules are invalid.' );
		}

		try {
			return (string) CampaignConfiguration::from_arrays( $rules, array(), array() )
				->usage_rules()['refund_behavior'];
		} catch ( InvalidArgumentException $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is diagnostic context and is never rendered.
			throw new RuntimeException( 'Persisted campaign rules are invalid.', 0, $exception );
		}
	}

	/**
	 * Pre-read the immutable usage/customer link for state-first locking.
	 *
	 * @param wpdb $wpdb        WordPress database connection.
	 * @param int  $order_id    WooCommerce order ID.
	 * @param int  $campaign_id Campaign ID.
	 * @throws RuntimeException When the usage/customer link cannot be read.
	 */
	private function find_usage_customer( wpdb $wpdb, int $order_id, int $campaign_id ): ?int {
		$sql = $wpdb->prepare(
			'SELECT customer_id FROM %i WHERE order_id = %d AND campaign_id = %d LIMIT 1',
			$this->tables->usages(),
			$order_id,
			$campaign_id
		);
		if ( null === $sql ) {
			throw new RuntimeException( 'Could not prepare the refund customer lookup.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Unique order/campaign lookup establishes lock order.
		$customer_id = $wpdb->get_var( $sql );
		if ( null === $customer_id ) {
			if ( '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'Could not read the refund usage customer.' );
			}

			return null;
		}

		return (int) $customer_id;
	}

	/**
	 * Lock the aggregate state row before its usage row.
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
			throw new RuntimeException( 'Could not prepare the refund state lock.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Unique campaign/customer counter lock.
		if ( null === $wpdb->get_var( $sql ) ) {
			throw new RuntimeException( 'Consumed usage is missing customer campaign state.' );
		}
	}

	/**
	 * Lock the usage row after its aggregate state.
	 *
	 * @param wpdb $wpdb        WordPress database connection.
	 * @param int  $order_id    WooCommerce order ID.
	 * @param int  $campaign_id Campaign ID.
	 * @param int  $customer_id Internal customer ID.
	 * @return array{id:int,status:string,discount_amount:string}|null
	 * @throws RuntimeException When the usage row cannot be locked.
	 */
	private function lock_usage( wpdb $wpdb, int $order_id, int $campaign_id, int $customer_id ): ?array {
		$sql = $wpdb->prepare(
			'SELECT id, status, discount_amount FROM %i
			 WHERE order_id = %d AND campaign_id = %d AND customer_id = %d
			 LIMIT 1 FOR UPDATE',
			$this->tables->usages(),
			$order_id,
			$campaign_id,
			$customer_id
		);
		if ( null === $sql ) {
			throw new RuntimeException( 'Could not prepare the refund usage lock.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Unique usage lock follows the aggregate state lock.
		$row = $wpdb->get_row( $sql, ARRAY_A );
		if ( null === $row ) {
			if ( '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'Could not lock the refund usage.' );
			}

			return null;
		}

		return array(
			'id'              => (int) $row['id'],
			'status'          => (string) $row['status'],
			'discount_amount' => (string) $row['discount_amount'],
		);
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
