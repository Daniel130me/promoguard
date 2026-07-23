<?php
/**
 * Customer identity database repository.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Customer;

use DomainException;
use PromoGuard\Support\TableNames;
use RuntimeException;
use Throwable;

/** Persists customer identities through bounded, indexed database operations. */
final class CustomerRepository implements CustomerIdentityStore {
	private const COLUMNS = 'id, wp_user_id, merged_into_customer_id, created_at_gmt, updated_at_gmt';

	/**
	 * Configure customer identity persistence.
	 *
	 * @param TableNames       $tables   Site-scoped plugin table names.
	 * @param CustomerHydrator $hydrator Customer row mapper.
	 */
	public function __construct(
		private readonly TableNames $tables,
		private readonly CustomerHydrator $hydrator = new CustomerHydrator()
	) {}

	/** Build a repository for the active WordPress site. */
	public static function from_wordpress(): self {
		return new self( TableNames::from_wordpress() );
	}

	/**
	 * Execute one identity operation transactionally.
	 *
	 * @template T
	 * @param callable():T $operation Identity operation.
	 * @return T
	 * @throws Throwable When the operation or transaction fails.
	 */
	public function transaction( callable $operation ): mixed {
		$this->control_transaction( 'START TRANSACTION' );

		try {
			$result = $operation();
			$this->control_transaction( 'COMMIT' );
			return $result;
		} catch ( Throwable $exception ) {
			$this->rollback();
			throw $exception;
		}
	}

	/**
	 * Find and lock the canonical customer for a WordPress user ID.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 */
	public function find_by_user_id( int $wp_user_id ): ?Customer {
		if ( $wp_user_id < 1 ) {
			return null;
		}

		global $wpdb;

		$table = $this->tables->customers();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Fixed columns and plugin-owned table name; the user ID is prepared.
		$sql = $wpdb->prepare(
			'SELECT ' . self::COLUMNS . " FROM {$table} WHERE wp_user_id = %d AND merged_into_customer_id IS NULL LIMIT 1 FOR UPDATE",
			$wp_user_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Unique indexed identity lookup inside the caller's short transaction.
		$row = $wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $this->hydrator->from_row( $row ) : null;
	}

	/**
	 * Find and lock the canonical customer that owns an identifier.
	 *
	 * @param string $type Identifier type.
	 * @param string $hash Hashed identifier value.
	 */
	public function find_by_identifier( string $type, string $hash ): ?Customer {
		global $wpdb;

		$customers  = $this->tables->customers();
		$identities = $this->tables->customer_identifiers();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Fixed columns and plugin-owned table names; identifier values are prepared.
		$sql = $wpdb->prepare(
			'SELECT customer.' . str_replace( ', ', ', customer.', self::COLUMNS ) .
			" FROM {$identities} AS identifier
			INNER JOIN {$customers} AS customer ON customer.id = identifier.customer_id
			WHERE identifier.identifier_type = %s
			AND identifier.identifier_hash = %s
			AND customer.merged_into_customer_id IS NULL
			LIMIT 1 FOR UPDATE",
			$type,
			$hash
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Unique type/hash lookup and primary-key join inside the caller's transaction.
		$row = $wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $this->hydrator->from_row( $row ) : null;
	}

	/**
	 * Create and return a persisted customer.
	 *
	 * @param int|null $wp_user_id WordPress user ID for authenticated customers.
	 * @throws RuntimeException When the insert fails.
	 */
	public function create( ?int $wp_user_id ): Customer {
		global $wpdb;

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared insert into one plugin-owned identity table.
		$inserted = $wpdb->insert(
			$this->tables->customers(),
			array(
				'wp_user_id'              => $wp_user_id,
				'merged_into_customer_id' => null,
				'created_at_gmt'          => $now,
				'updated_at_gmt'          => $now,
			),
			array( '%d', '%d', '%s', '%s' )
		);

		if ( false === $inserted || $wpdb->insert_id < 1 ) {
			throw new RuntimeException( 'Customer identity could not be created.' );
		}

		return $this->hydrator->from_row(
			array(
				'id'                      => (int) $wpdb->insert_id,
				'wp_user_id'              => $wp_user_id,
				'merged_into_customer_id' => null,
				'created_at_gmt'          => $now,
				'updated_at_gmt'          => $now,
			)
		);
	}

	/**
	 * Attach a new hashed identifier to a customer.
	 *
	 * @param int    $customer_id Customer ID.
	 * @param string $type        Identifier type.
	 * @param string $hash        Hashed identifier value.
	 * @param bool   $is_primary  Whether this is the primary identifier.
	 * @throws RuntimeException When the unique identifier cannot be attached.
	 */
	public function attach_identifier( int $customer_id, string $type, string $hash, bool $is_primary ): void {
		global $wpdb;

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared insert into one plugin-owned identifier table.
		$inserted = $wpdb->insert(
			$this->tables->customer_identifiers(),
			array(
				'customer_id'       => $customer_id,
				'identifier_type'   => $type,
				'identifier_hash'   => $hash,
				'is_primary'        => $is_primary ? 1 : 0,
				'first_seen_at_gmt' => $now,
				'last_seen_at_gmt'  => $now,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			// The unique type/hash index makes a concurrent ownership race fail closed.
			throw new RuntimeException( 'Customer identifier could not be attached.' );
		}
	}

	/**
	 * Record another observation of an existing identifier.
	 *
	 * @param int    $customer_id Customer ID.
	 * @param string $type        Identifier type.
	 * @param string $hash        Hashed identifier value.
	 * @throws RuntimeException When the update fails.
	 */
	public function touch_identifier( int $customer_id, string $type, string $hash ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed, prepared update of one plugin-owned identifier row.
		$updated = $wpdb->update(
			$this->tables->customer_identifiers(),
			array( 'last_seen_at_gmt' => current_time( 'mysql', true ) ),
			array(
				'customer_id'     => $customer_id,
				'identifier_type' => $type,
				'identifier_hash' => $hash,
			),
			array( '%s' ),
			array( '%d', '%s', '%s' )
		);

		if ( false === $updated ) {
			throw new RuntimeException( 'Customer identifier could not be updated.' );
		}
	}

	/**
	 * Merge one guest into an authenticated customer.
	 *
	 * The caller owns the surrounding transaction. Both customer rows are locked
	 * in primary-key order, so concurrent merges use a consistent lock order.
	 *
	 * @param int $guest_customer_id         Guest customer ID.
	 * @param int $authenticated_customer_id Authenticated customer ID.
	 * @throws DomainException When either identity is missing or has the wrong role.
	 */
	public function merge_guest( int $guest_customer_id, int $authenticated_customer_id ): void {
		if ( $guest_customer_id === $authenticated_customer_id ) {
			throw new DomainException( 'A customer cannot be merged into itself.' );
		}

		$customers = $this->lock_customers( $guest_customer_id, $authenticated_customer_id );
		$guest     = $customers[ $guest_customer_id ] ?? null;
		$user      = $customers[ $authenticated_customer_id ] ?? null;

		if ( null === $guest || null === $user ) {
			throw new DomainException( 'Both customer identities must exist before merging.' );
		}

		if ( $guest->merged_into_customer_id === $authenticated_customer_id ) {
			return;
		}

		if ( ! $guest->is_guest() || $guest->is_merged() || $user->is_guest() || $user->is_merged() ) {
			throw new DomainException( 'Only an active guest can merge into an active authenticated customer.' );
		}

		$this->merge_campaign_state( $guest_customer_id, $authenticated_customer_id );
		$this->move_identifiers( $guest_customer_id, $authenticated_customer_id );
		$this->move_usage_history( $guest_customer_id, $authenticated_customer_id );
		$this->mark_merged( $guest_customer_id, $authenticated_customer_id );
	}

	/**
	 * Lock both customer rows in deterministic order.
	 *
	 * @param int $first_customer_id  First requested customer ID.
	 * @param int $second_customer_id Second requested customer ID.
	 * @return array<int,Customer>
	 */
	private function lock_customers( int $first_customer_id, int $second_customer_id ): array {
		global $wpdb;

		$ids   = array( $first_customer_id, $second_customer_id );
		$table = $this->tables->customers();
		sort( $ids, SORT_NUMERIC );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Fixed columns and plugin-owned table; both IDs are prepared.
		$sql = $wpdb->prepare(
			'SELECT ' . self::COLUMNS . " FROM {$table} WHERE id IN (%d, %d) ORDER BY id ASC FOR UPDATE",
			$ids[0],
			$ids[1]
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Two bounded primary-key locks in deterministic order.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$customers = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) ) {
				$customer                   = $this->hydrator->from_row( $row );
				$customers[ $customer->id ] = $customer;
			}
		}

		return $customers;
	}

	/**
	 * Combine bounded per-campaign counters, then remove the guest rows.
	 *
	 * @param int $guest_customer_id         Guest customer ID.
	 * @param int $authenticated_customer_id Authenticated customer ID.
	 * @throws RuntimeException When campaign state cannot be merged.
	 */
	private function merge_campaign_state( int $guest_customer_id, int $authenticated_customer_id ): void {
		global $wpdb;

		$table = $this->tables->customer_campaign_state();
		$now   = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned table; both IDs and timestamp are prepared.
		$merge_sql  = $wpdb->prepare(
			"INSERT INTO {$table}
				(campaign_id, customer_id, consumed_count, reserved_count, total_discount, first_consumed_at_gmt, last_consumed_at_gmt, last_order_id, lock_version, created_at_gmt, updated_at_gmt)
			SELECT campaign_id, %d, consumed_count, reserved_count, total_discount, first_consumed_at_gmt, last_consumed_at_gmt, last_order_id, lock_version, created_at_gmt, %s
			FROM {$table}
			WHERE customer_id = %d
			ON DUPLICATE KEY UPDATE
				consumed_count = consumed_count + VALUES(consumed_count),
				reserved_count = reserved_count + VALUES(reserved_count),
				total_discount = total_discount + VALUES(total_discount),
				first_consumed_at_gmt = COALESCE(LEAST(first_consumed_at_gmt, VALUES(first_consumed_at_gmt)), first_consumed_at_gmt, VALUES(first_consumed_at_gmt)),
				last_consumed_at_gmt = COALESCE(GREATEST(last_consumed_at_gmt, VALUES(last_consumed_at_gmt)), last_consumed_at_gmt, VALUES(last_consumed_at_gmt)),
				last_order_id = COALESCE(VALUES(last_order_id), last_order_id),
				lock_version = lock_version + VALUES(lock_version) + 1,
				updated_at_gmt = VALUES(updated_at_gmt)",
			$authenticated_customer_id,
			$now,
			$guest_customer_id
		);
		$delete_sql = $wpdb->prepare( "DELETE FROM {$table} WHERE customer_id = %d", $guest_customer_id );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$this->require_query( $merge_sql, 'Customer campaign state could not be merged.' );
		$this->require_query( $delete_sql, 'Merged guest campaign state could not be removed.' );
	}

	/**
	 * Move all unique guest identifiers to the authenticated customer.
	 *
	 * @param int $guest_customer_id         Guest customer ID.
	 * @param int $authenticated_customer_id Authenticated customer ID.
	 */
	private function move_identifiers( int $guest_customer_id, int $authenticated_customer_id ): void {
		global $wpdb;

		$table = $this->tables->customer_identifiers();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned table and prepared customer IDs.
		$sql = $wpdb->prepare(
			"UPDATE {$table} SET customer_id = %d WHERE customer_id = %d",
			$authenticated_customer_id,
			$guest_customer_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$this->require_query( $sql, 'Guest identifiers could not be moved.' );
	}

	/**
	 * Keep usage lifecycle and decision history aligned with canonical state.
	 *
	 * @param int $guest_customer_id         Guest customer ID.
	 * @param int $authenticated_customer_id Authenticated customer ID.
	 */
	private function move_usage_history( int $guest_customer_id, int $authenticated_customer_id ): void {
		global $wpdb;

		foreach ( array( $this->tables->usages(), $this->tables->decisions() ) as $table ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Centralized plugin table name; both customer IDs are prepared.
			$sql = $wpdb->prepare(
				"UPDATE {$table} SET customer_id = %d WHERE customer_id = %d",
				$authenticated_customer_id,
				$guest_customer_id
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$this->require_query( $sql, 'Guest identity history could not be moved.' );
		}
	}
	/**
	 * Preserve the guest as a tombstone pointing to its canonical customer.
	 *
	 * @param int $guest_customer_id         Guest customer ID.
	 * @param int $authenticated_customer_id Authenticated customer ID.
	 * @throws RuntimeException When the merge tombstone cannot be persisted.
	 */
	private function mark_merged( int $guest_customer_id, int $authenticated_customer_id ): void {
		global $wpdb;

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared updates of the two already locked plugin customer rows.
		$guest_updated = $wpdb->update(
			$this->tables->customers(),
			array(
				'merged_into_customer_id' => $authenticated_customer_id,
				'updated_at_gmt'          => $now,
			),
			array(
				'id'         => $guest_customer_id,
				'wp_user_id' => null,
			),
			array( '%d', '%s' ),
			array( '%d', '%d' )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared timestamp update of the canonical plugin customer row.
		$user_updated = $wpdb->update(
			$this->tables->customers(),
			array( 'updated_at_gmt' => $now ),
			array( 'id' => $authenticated_customer_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $guest_updated || false === $user_updated ) {
			throw new RuntimeException( 'Customer merge could not be finalized.' );
		}
	}

	/**
	 * Execute a required write query.
	 *
	 * @param string $sql     Prepared SQL statement.
	 * @param string $message Safe failure message.
	 * @throws RuntimeException When the query fails.
	 */
	private function require_query( string $sql, string $message ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared by the bounded private caller immediately before execution.
		if ( false === $wpdb->query( $sql ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Private callers provide fixed safe messages that are never rendered directly.
			throw new RuntimeException( $message );
		}
	}

	/**
	 * Execute a transaction control statement.
	 *
	 * @param string $statement Fixed transaction statement.
	 * @throws RuntimeException When transaction control fails.
	 */
	private function control_transaction( string $statement ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Internal fixed transaction control statement.
		if ( false === $wpdb->query( $statement ) ) {
			throw new RuntimeException( 'Customer identity transaction could not be completed.' );
		}
	}

	/** Roll back a failed identity operation without masking its original exception. */
	private function rollback(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Internal fixed transaction rollback.
		$wpdb->query( 'ROLLBACK' );
	}
}
