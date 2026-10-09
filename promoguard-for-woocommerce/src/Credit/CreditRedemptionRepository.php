<?php
/**
 * Atomic store-credit redemption persistence.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Credit;

use InvalidArgumentException;
use PromoGuard\Support\TableNames;
use RuntimeException;
use Throwable;

/** Persists order reservations and append-only consumption/refund ledger entries. */
final class CreditRedemptionRepository implements CreditRedemptionStore {
	// Public methods inherit the interface contract; private names and lock comments
	// document their bounded scalar inputs and transactional failure boundaries.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionCommentThrowTag.Missing
	private const CONSUMPTION_SOURCE = 'checkout';
	private const CONSUMPTION_TYPE   = 'redemption';
	private const REFUND_SOURCE      = 'refund';
	private const REFUND_TYPE        = 'refund_restore';

	/** Configure site-scoped table names. */
	public function __construct( private readonly TableNames $tables ) {}

	/** Build a repository for the active WordPress site. */
	public static function from_wordpress(): self {
		return new self( TableNames::from_wordpress() );
	}

	/** {@inheritDoc} */
	public function available_balance( int $user_id, string $currency ): string {
		if ( $user_id < 1 ) {
			return '0';
		}

		global $wpdb;

		$accounts     = $this->tables->credit_accounts();
		$reservations = $this->tables->credit_reservations();
		$currency     = $this->currency( $currency );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table identifiers; indexed values are prepared.
		$sql = $wpdb->prepare(
			"SELECT a.balance,
				COALESCE(SUM(CASE WHEN r.status = %s THEN r.amount ELSE 0 END), 0) AS reserved_amount
			FROM {$accounts} a
			LEFT JOIN {$reservations} r ON r.account_id = a.id
			WHERE a.wp_user_id = %d AND a.currency = %s
			GROUP BY a.id, a.balance
			LIMIT 1",
			CreditReservation::RESERVED,
			$user_id,
			$currency
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One bounded account/index aggregation avoids per-reservation queries.
		$row = $wpdb->get_row( $sql, ARRAY_A );
		if ( ! is_array( $row ) ) {
			return '0';
		}

		return CreditAmount::subtract( (string) $row['balance'], (string) $row['reserved_amount'] );
	}

	/** {@inheritDoc} */
	public function reserve(
		int $user_id,
		int $order_id,
		string $maximum_amount,
		string $currency
	): ?CreditReservation {
		$this->validate_identity( $user_id, $order_id );
		$maximum_amount = $this->amount( $maximum_amount );
		$currency       = $this->currency( $currency );
		if ( ! CreditAmount::is_positive( $maximum_amount ) ) {
			return null;
		}

		return $this->transaction(
			function () use ( $user_id, $order_id, $maximum_amount, $currency ): ?CreditReservation {
				$existing = $this->lock_reservation( $order_id );
				if (
					is_array( $existing )
					&& in_array( $existing['status'], array( CreditReservation::RESERVED, CreditReservation::CONSUMED ), true )
				) {
					$this->assert_reservation_identity( $existing, $user_id, $currency );
					return $this->reservation_from_row( $existing );
				}

				$account = $this->lock_account_for_user( $user_id, $currency );
				if ( ! is_array( $account ) ) {
					return null;
				}
				if ( is_array( $existing ) ) {
					$this->assert_reservation_identity( $existing, $user_id, $currency );
				}

				$reserved  = $this->reserved_total( (int) $account['id'], $order_id );
				$available = CreditAmount::subtract( (string) $account['balance'], $reserved );
				$amount    = CreditAmount::minimum( $maximum_amount, $available );
				if ( ! CreditAmount::is_positive( $amount ) ) {
					return null;
				}

				$now = current_time( 'mysql', true );
				if ( is_array( $existing ) ) {
					$this->reactivate_reservation( (int) $existing['id'], $amount, $now );
					$existing['amount']          = $amount;
					$existing['status']          = CreditReservation::RESERVED;
					$existing['reserved_at_gmt'] = $now;
					return $this->reservation_from_row( $existing );
				}

				return $this->insert_reservation( $account, $user_id, $order_id, $currency, $amount, $now );
			}
		);
	}

	/** {@inheritDoc} */
	public function consume( int $order_id ): ?CreditReservation {
		if ( $order_id < 1 ) {
			throw new RuntimeException( 'Store credit requires a valid WooCommerce order.' );
		}

		return $this->transaction(
			function () use ( $order_id ): ?CreditReservation {
				$row = $this->lock_reservation( $order_id );
				if ( ! is_array( $row ) || CreditReservation::RELEASED === $row['status'] ) {
					return null;
				}
				if ( CreditReservation::CONSUMED === $row['status'] ) {
					return $this->reservation_from_row( $row );
				}

				$account = $this->lock_account( (int) $row['account_id'] );
				if ( ! is_array( $account ) ) {
					throw new RuntimeException( 'Reserved store-credit account could not be locked.' );
				}
				$amount  = (string) $row['amount'];
				$balance = CreditAmount::subtract( (string) $account['balance'], $amount );
				$now     = current_time( 'mysql', true );
				$this->update_account_balance( (int) $account['id'], $balance, $now );
				$transaction_id = $this->append_ledger(
					$account,
					(int) $row['wp_user_id'],
					'-' . $amount,
					$balance,
					self::CONSUMPTION_TYPE,
					self::CONSUMPTION_SOURCE,
					'order:' . $order_id,
					'Store credit redeemed for order #' . $order_id,
					$now
				);
				$this->mark_consumed( (int) $row['id'], $amount, $transaction_id, $now );

				$row['status']                     = CreditReservation::CONSUMED;
				$row['consumed_amount']            = $amount;
				$row['consumption_transaction_id'] = $transaction_id;
				return $this->reservation_from_row( $row );
			}
		);
	}

	/** {@inheritDoc} */
	public function release( int $order_id ): ?CreditReservation {
		if ( $order_id < 1 ) {
			throw new RuntimeException( 'Store credit requires a valid WooCommerce order.' );
		}

		return $this->transaction(
			function () use ( $order_id ): ?CreditReservation {
				$row = $this->lock_reservation( $order_id );
				if ( ! is_array( $row ) ) {
					return null;
				}
				if ( CreditReservation::RESERVED !== $row['status'] ) {
					return $this->reservation_from_row( $row );
				}

				global $wpdb;
				$now = current_time( 'mysql', true );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Status transition is protected by the reservation row lock.
				$updated = $wpdb->update(
					$this->tables->credit_reservations(),
					array(
						'status'          => CreditReservation::RELEASED,
						'released_at_gmt' => $now,
						'updated_at_gmt'  => $now,
					),
					array( 'id' => (int) $row['id'] ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
				if ( 1 !== $updated ) {
					throw new RuntimeException( 'Store-credit reservation could not be released.' );
				}

				$row['status'] = CreditReservation::RELEASED;
				return $this->reservation_from_row( $row );
			}
		);
	}

	/** {@inheritDoc} */
	public function restore( int $order_id, int $refund_id, string $maximum_amount ): CreditRestoreResult {
		if ( $order_id < 1 || $refund_id < 1 ) {
			throw new RuntimeException( 'Store-credit restoration requires valid order and refund IDs.' );
		}
		$maximum_amount = $this->amount( $maximum_amount );
		if ( ! CreditAmount::is_positive( $maximum_amount ) ) {
			return new CreditRestoreResult( false, '0', $this->reservation_balance( $order_id ) );
		}

		return $this->transaction(
			function () use ( $order_id, $refund_id, $maximum_amount ): CreditRestoreResult {
				$row = $this->lock_reservation( $order_id );
				if ( ! is_array( $row ) || CreditReservation::CONSUMED !== $row['status'] ) {
					return new CreditRestoreResult( false, '0', '0' );
				}

				$existing = $this->existing_refund_transaction( $refund_id );
				if ( is_array( $existing ) ) {
					return new CreditRestoreResult( false, (string) $existing['amount'], (string) $existing['balance_after'] );
				}

				$remaining = CreditAmount::subtract( (string) $row['consumed_amount'], (string) $row['restored_amount'] );
				$amount    = CreditAmount::minimum( $maximum_amount, $remaining );
				$account   = $this->lock_account( (int) $row['account_id'] );
				if ( ! is_array( $account ) ) {
					throw new RuntimeException( 'Consumed store-credit account could not be locked.' );
				}
				if ( ! CreditAmount::is_positive( $amount ) ) {
					return new CreditRestoreResult( false, '0', (string) $account['balance'] );
				}

				$balance  = CreditAmount::add( (string) $account['balance'], $amount );
				$restored = CreditAmount::add( (string) $row['restored_amount'], $amount );
				$now      = current_time( 'mysql', true );
				$this->update_account_balance( (int) $account['id'], $balance, $now );
				$this->append_ledger(
					$account,
					(int) $row['wp_user_id'],
					$amount,
					$balance,
					self::REFUND_TYPE,
					self::REFUND_SOURCE,
					'refund:' . $refund_id,
					'Store credit restored for refund #' . $refund_id,
					$now
				);
				$this->update_restored_amount( (int) $row['id'], $restored, $now );

				return new CreditRestoreResult( true, $amount, $balance );
			}
		);
	}

	/** {@inheritDoc} */
	public function reservation( int $order_id ): ?CreditReservation {
		if ( $order_id < 1 ) {
			return null;
		}

		global $wpdb;
		$table = $this->tables->credit_reservations();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table identifier; order ID is prepared.
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d LIMIT 1", $order_id );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded unique order lookup.
		$row = $wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $this->reservation_from_row( $row ) : null;
	}

	/** Run one callback inside a database transaction. */
	private function transaction( callable $callback ): mixed {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit transaction required for account and reservation locks.
		$wpdb->query( 'START TRANSACTION' );

		try {
			$result = $callback();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit transaction commit.
			$wpdb->query( 'COMMIT' );
			return $result;
		} catch ( Throwable $exception ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback preserves the original failure.
			$wpdb->query( 'ROLLBACK' );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is diagnostic context only.
			throw new RuntimeException( 'Store-credit redemption transaction failed.', 0, $exception );
		}
	}

	/**
	 * Lock an order reservation through its unique index.
	 *
	 * @return array<string,mixed>|null
	 */
	private function lock_reservation( int $order_id ): ?array {
		global $wpdb;
		$table = $this->tables->credit_reservations();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table identifier; order ID is prepared.
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d LIMIT 1 FOR UPDATE", $order_id );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Unique reservation lock inside a transaction.
		$row = $wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Lock a currency-scoped account through its unique user/currency index.
	 *
	 * @return array<string,mixed>|null
	 */
	private function lock_account_for_user( int $user_id, string $currency ): ?array {
		global $wpdb;
		$table = $this->tables->credit_accounts();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table identifier; indexed values are prepared.
		$sql = $wpdb->prepare(
			"SELECT id, wp_user_id, currency, balance FROM {$table} WHERE wp_user_id = %d AND currency = %s LIMIT 1 FOR UPDATE",
			$user_id,
			$currency
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Account row lock serializes all balance changes.
		$row = $wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Lock an account by primary key.
	 *
	 * @return array<string,mixed>|null
	 */
	private function lock_account( int $account_id ): ?array {
		global $wpdb;
		$table = $this->tables->credit_accounts();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table identifier; primary key is prepared.
		$sql = $wpdb->prepare( "SELECT id, wp_user_id, currency, balance FROM {$table} WHERE id = %d LIMIT 1 FOR UPDATE", $account_id );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Account row lock serializes all balance changes.
		$row = $wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/** Sum active reservations while the owning account row is locked. */
	private function reserved_total( int $account_id, int $excluded_order_id ): string {
		global $wpdb;
		$table = $this->tables->credit_reservations();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table identifier; indexed values are prepared.
		$sql = $wpdb->prepare(
			"SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE account_id = %d AND status = %s AND order_id <> %d",
			$account_id,
			CreditReservation::RESERVED,
			$excluded_order_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded account/status index aggregation under the account lock.
		$value = $wpdb->get_var( $sql );

		return is_string( $value ) || is_numeric( $value ) ? (string) $value : '0';
	}

	/**
	 * Insert a new order reservation.
	 *
	 * @param array<string,mixed> $account Locked credit account.
	 */
	private function insert_reservation(
		array $account,
		int $user_id,
		int $order_id,
		string $currency,
		string $amount,
		string $now
	): CreditReservation {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert is protected by the locked account row and unique order index.
		$inserted = $wpdb->insert(
			$this->tables->credit_reservations(),
			array(
				'uuid'                       => wp_generate_uuid4(),
				'account_id'                 => (int) $account['id'],
				'wp_user_id'                 => $user_id,
				'order_id'                   => $order_id,
				'currency'                   => $currency,
				'amount'                     => $amount,
				'status'                     => CreditReservation::RESERVED,
				'consumed_amount'            => '0',
				'restored_amount'            => '0',
				'consumption_transaction_id' => null,
				'reserved_at_gmt'            => $now,
				'updated_at_gmt'             => $now,
			),
			array( '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( false === $inserted ) {
			throw new RuntimeException( 'Store-credit reservation could not be created.' );
		}

		return new CreditReservation(
			(int) $wpdb->insert_id,
			$user_id,
			$order_id,
			$currency,
			$amount,
			CreditReservation::RESERVED,
			'0',
			'0'
		);
	}

	/** Reactivate a released reservation for a retried payment. */
	private function reactivate_reservation( int $reservation_id, string $amount, string $now ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Update is protected by reservation and account locks.
		$updated = $wpdb->update(
			$this->tables->credit_reservations(),
			array(
				'amount'          => $amount,
				'status'          => CreditReservation::RESERVED,
				'reserved_at_gmt' => $now,
				'released_at_gmt' => null,
				'updated_at_gmt'  => $now,
			),
			array( 'id' => $reservation_id ),
			array( '%s', '%s', '%s', null, '%s' ),
			array( '%d' )
		);
		if ( 1 !== $updated ) {
			throw new RuntimeException( 'Store-credit reservation could not be reactivated.' );
		}
	}

	/** Mark a reservation consumed after its debit ledger entry is written. */
	private function mark_consumed( int $reservation_id, string $amount, int $transaction_id, string $now ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Update is part of the locked debit transaction.
		$updated = $wpdb->update(
			$this->tables->credit_reservations(),
			array(
				'status'                     => CreditReservation::CONSUMED,
				'consumed_amount'            => $amount,
				'consumption_transaction_id' => $transaction_id,
				'consumed_at_gmt'            => $now,
				'updated_at_gmt'             => $now,
			),
			array( 'id' => $reservation_id ),
			array( '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
		if ( 1 !== $updated ) {
			throw new RuntimeException( 'Store-credit reservation could not be consumed.' );
		}
	}

	/** Update one locked account to an already-calculated exact balance. */
	private function update_account_balance( int $account_id, string $balance, string $now ): void {
		global $wpdb;
		$table = $this->tables->credit_accounts();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table identifier; exact values are prepared.
		$sql = $wpdb->prepare(
			"UPDATE {$table}
			SET balance = %s, lock_version = lock_version + 1, updated_at_gmt = %s
			WHERE id = %d",
			$balance,
			$now,
			$account_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Single exact update protected by the account row lock.
		$updated = $wpdb->query( $sql );
		if ( 1 !== $updated ) {
			throw new RuntimeException( 'Store-credit account balance could not be updated.' );
		}
	}

	/**
	 * Append one immutable balance transaction and return its ID.
	 *
	 * @param array<string,mixed> $account Locked credit account.
	 */
	private function append_ledger(
		array $account,
		int $user_id,
		string $amount,
		string $balance,
		string $type,
		string $source,
		string $reference,
		string $description,
		string $now
	): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Ledger append shares the account transaction.
		$inserted = $wpdb->insert(
			$this->tables->credit_transactions(),
			array(
				'uuid'           => wp_generate_uuid4(),
				'account_id'     => (int) $account['id'],
				'wp_user_id'     => $user_id,
				'type'           => $type,
				'source'         => $source,
				'reference_key'  => $reference,
				'amount'         => $amount,
				'balance_after'  => $balance,
				'currency'       => (string) $account['currency'],
				'description'    => $description,
				'created_at_gmt' => $now,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			throw new RuntimeException( 'Store-credit ledger entry could not be created.' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Return a locking idempotency lookup for one refund.
	 *
	 * @return array<string,mixed>|null
	 */
	private function existing_refund_transaction( int $refund_id ): ?array {
		global $wpdb;
		$table = $this->tables->credit_transactions();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table identifier; unique values are prepared.
		$sql = $wpdb->prepare(
			"SELECT amount, balance_after FROM {$table} WHERE source = %s AND reference_key = %s LIMIT 1 FOR UPDATE",
			self::REFUND_SOURCE,
			'refund:' . $refund_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Unique idempotency lookup inside the account transaction.
		$row = $wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/** Persist the cumulative amount restored to one consumed reservation. */
	private function update_restored_amount( int $reservation_id, string $amount, string $now ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Update shares the locked restoration transaction.
		$updated = $wpdb->update(
			$this->tables->credit_reservations(),
			array(
				'restored_amount' => $amount,
				'updated_at_gmt'  => $now,
			),
			array( 'id' => $reservation_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		if ( 1 !== $updated ) {
			throw new RuntimeException( 'Store-credit restoration total could not be updated.' );
		}
	}

	/** Read the account balance associated with an order reservation. */
	private function reservation_balance( int $order_id ): string {
		$reservation = $this->reservation( $order_id );
		if ( null === $reservation ) {
			return '0';
		}

		global $wpdb;
		$accounts     = $this->tables->credit_accounts();
		$reservations = $this->tables->credit_reservations();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table identifiers; order ID is prepared.
		$sql = $wpdb->prepare(
			"SELECT a.balance FROM {$accounts} a INNER JOIN {$reservations} r ON r.account_id = a.id WHERE r.order_id = %d LIMIT 1",
			$order_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded primary/unique-key join.
		$balance = $wpdb->get_var( $sql );

		return is_string( $balance ) || is_numeric( $balance ) ? (string) $balance : '0';
	}

	/**
	 * Map one validated database row to the public lifecycle snapshot.
	 *
	 * @param array<string,mixed> $row Reservation database row.
	 */
	private function reservation_from_row( array $row ): CreditReservation {
		return new CreditReservation(
			(int) $row['id'],
			(int) $row['wp_user_id'],
			(int) $row['order_id'],
			(string) $row['currency'],
			CreditAmount::normalize( (string) $row['amount'] ),
			(string) $row['status'],
			CreditAmount::normalize( (string) $row['consumed_amount'] ),
			CreditAmount::normalize( (string) $row['restored_amount'] )
		);
	}

	/**
	 * Ensure an existing order reservation cannot be reassigned.
	 *
	 * @param array<string,mixed> $row Reservation database row.
	 */
	private function assert_reservation_identity( array $row, int $user_id, string $currency ): void {
		if ( (int) $row['wp_user_id'] !== $user_id || (string) $row['currency'] !== $currency ) {
			throw new RuntimeException( 'Store-credit reservation identity is inconsistent.' );
		}
	}

	/** Validate user and order identifiers. */
	private function validate_identity( int $user_id, int $order_id ): void {
		if ( $user_id < 1 || $order_id < 1 ) {
			throw new RuntimeException( 'Store-credit reservation requires valid user and order IDs.' );
		}
	}

	/** Normalize a non-negative amount for persistence. */
	private function amount( string $amount ): string {
		try {
			return CreditAmount::normalize( $amount );
		} catch ( InvalidArgumentException $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is diagnostic context only.
			throw new RuntimeException( 'Store-credit redemption amount is invalid.', 0, $exception );
		}
	}

	/** Normalize an ISO-style three-letter currency code. */
	private function currency( string $currency ): string {
		$currency = strtoupper( trim( $currency ) );
		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			throw new RuntimeException( 'Store credit requires a three-letter currency code.' );
		}

		return $currency;
	}
}
