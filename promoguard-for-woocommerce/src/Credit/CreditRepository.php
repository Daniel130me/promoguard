<?php
/**
 * WordPress database store-credit persistence.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Credit;

use InvalidArgumentException;
use PromoGuard\Support\TableNames;
use RuntimeException;
use Throwable;

/** Persists currency-scoped balances and append-only ledger transactions. */
final class CreditRepository implements CreditStore {
	/**
	 * Configure site-scoped credit persistence.
	 *
	 * @param TableNames $tables Site-scoped plugin table names.
	 */
	public function __construct( private readonly TableNames $tables ) {}

	/** Build a repository for the active WordPress site. */
	public static function from_wordpress(): self {
		return new self( TableNames::from_wordpress() );
	}

	/**
	 * Create one credit entry unless its source reference already exists.
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param string $amount      Positive decimal amount.
	 * @param string $currency    ISO-style three-letter currency code.
	 * @param string $type        Stable transaction type.
	 * @param string $source      Stable subsystem source.
	 * @param string $reference   Source-scoped idempotency reference.
	 * @param string $description Administrator-facing ledger description.
	 * @throws RuntimeException When validation or persistence fails.
	 */
	public function grant(
		int $user_id,
		string $amount,
		string $currency,
		string $type,
		string $source,
		string $reference,
		string $description
	): CreditGrantResult {
		try {
			$amount = CreditAmount::normalize( $amount );
		} catch ( InvalidArgumentException $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The previous exception is diagnostic context, not rendered output.
			throw new RuntimeException( 'Store-credit amount is invalid.', 0, $exception );
		}

		$currency = $this->currency( $currency );
		$this->validate_grant( $user_id, $amount, $type, $source, $reference, $description );

		global $wpdb;

		$accounts     = $this->tables->credit_accounts();
		$transactions = $this->tables->credit_transactions();
		$now          = current_time( 'mysql', true );

		// Account locking serializes every balance change for this user and currency.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction boundaries cannot use the object cache.
		$wpdb->query( 'START TRANSACTION' );

		try {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table identifier; values are prepared.
			$account_insert = $wpdb->prepare(
				"INSERT INTO {$accounts} (wp_user_id, currency, balance, lock_version, created_at_gmt, updated_at_gmt)
				VALUES (%d, %s, 0, 0, %s, %s)
				ON DUPLICATE KEY UPDATE wp_user_id = VALUES(wp_user_id)",
				$user_id,
				$currency,
				$now,
				$now
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Atomic account initialization inside the credit transaction.
			if ( false === $wpdb->query( $account_insert ) ) {
				throw new RuntimeException( 'Store-credit account could not be initialized.' );
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table identifier; indexed values are prepared.
			$account_sql = $wpdb->prepare(
				"SELECT id, balance FROM {$accounts} WHERE wp_user_id = %d AND currency = %s LIMIT 1 FOR UPDATE",
				$user_id,
				$currency
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Required row lock on the unique user/currency key.
			$account = $wpdb->get_row( $account_sql, ARRAY_A );
			if ( ! is_array( $account ) ) {
				throw new RuntimeException( 'Store-credit account could not be locked.' );
			}

			// A locking read ensures a retried request observes a committed entry.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table identifier; unique values are prepared.
			$existing_sql = $wpdb->prepare(
				"SELECT id FROM {$transactions} WHERE source = %s AND reference_key = %s LIMIT 1 FOR UPDATE",
				$source,
				$reference
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Idempotency lookup uses the unique source/reference index.
			$existing_id = $wpdb->get_var( $existing_sql );
			if ( null !== $existing_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Commits cannot use the object cache.
				$wpdb->query( 'COMMIT' );

				return new CreditGrantResult( false, (string) $account['balance'], (int) $existing_id );
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table identifier; exact decimal and primary key are prepared.
			$balance_update = $wpdb->prepare(
				"UPDATE {$accounts}
				SET balance = balance + %s, lock_version = lock_version + 1, updated_at_gmt = %s
				WHERE id = %d",
				$amount,
				$now,
				(int) $account['id']
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Atomic update on a locked primary key.
			if ( 1 !== $wpdb->query( $balance_update ) ) {
				throw new RuntimeException( 'Store-credit balance could not be updated.' );
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table identifier; primary key is prepared.
			$balance_sql = $wpdb->prepare(
				"SELECT balance FROM {$accounts} WHERE id = %d LIMIT 1",
				(int) $account['id']
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded primary-key read records the exact post-update balance.
			$balance = $wpdb->get_var( $balance_sql );
			if ( ! is_string( $balance ) && ! is_numeric( $balance ) ) {
				throw new RuntimeException( 'Updated store-credit balance could not be read.' );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Append-only ledger insert is part of the locked balance transaction.
			$inserted = $wpdb->insert(
				$transactions,
				array(
					'uuid'           => wp_generate_uuid4(),
					'account_id'     => (int) $account['id'],
					'wp_user_id'     => $user_id,
					'type'           => $type,
					'source'         => $source,
					'reference_key'  => $reference,
					'amount'         => $amount,
					'balance_after'  => (string) $balance,
					'currency'       => $currency,
					'description'    => '' === $description ? null : $description,
					'created_at_gmt' => $now,
				),
				array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( false === $inserted ) {
				throw new RuntimeException( 'Store-credit ledger entry could not be created.' );
			}

			$transaction_id = (int) $wpdb->insert_id;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Commits cannot use the object cache.
			$wpdb->query( 'COMMIT' );

			return new CreditGrantResult( true, (string) $balance, $transaction_id );
		} catch ( Throwable $exception ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollbacks cannot use the object cache.
			$wpdb->query( 'ROLLBACK' );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The previous exception is diagnostic context, not rendered output.
			throw new RuntimeException( 'Store-credit transaction failed.', 0, $exception );
		}
	}

	/**
	 * Return one user's balance for an exact currency.
	 *
	 * @param int    $user_id  WordPress user ID.
	 * @param string $currency ISO-style three-letter currency code.
	 */
	public function balance( int $user_id, string $currency ): string {
		if ( $user_id < 1 ) {
			return '0';
		}

		global $wpdb;

		$table    = $this->tables->credit_accounts();
		$currency = $this->currency( $currency );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table identifier; unique values are prepared.
		$sql = $wpdb->prepare(
			"SELECT balance FROM {$table} WHERE wp_user_id = %d AND currency = %s LIMIT 1",
			$user_id,
			$currency
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One indexed balance lookup.
		$balance = $wpdb->get_var( $sql );

		return is_string( $balance ) || is_numeric( $balance ) ? (string) $balance : '0';
	}

	/**
	 * Validate bounded ledger fields before beginning a database transaction.
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param string $amount      Normalized positive amount.
	 * @param string $type        Transaction type.
	 * @param string $source      Transaction source.
	 * @param string $reference   Idempotency reference.
	 * @param string $description Ledger description.
	 * @throws RuntimeException When a field is invalid.
	 */
	private function validate_grant(
		int $user_id,
		string $amount,
		string $type,
		string $source,
		string $reference,
		string $description
	): void {
		if ( $user_id < 1 ) {
			throw new RuntimeException( 'Store credit requires a valid WordPress user.' );
		}
		if ( ! CreditAmount::is_positive( $amount ) ) {
			throw new RuntimeException( 'Store-credit grants must be greater than zero.' );
		}
		if ( '' === $type || strlen( $type ) > 32 || '' === $source || strlen( $source ) > 64 ) {
			throw new RuntimeException( 'Store-credit transaction type or source is invalid.' );
		}
		if ( '' === $reference || strlen( $reference ) > 191 || strlen( $description ) > 255 ) {
			throw new RuntimeException( 'Store-credit reference or description is invalid.' );
		}
	}

	/**
	 * Normalize one three-letter currency code.
	 *
	 * @param string $currency Candidate currency code.
	 * @throws RuntimeException When the currency is invalid.
	 */
	private function currency( string $currency ): string {
		$currency = strtoupper( trim( $currency ) );
		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			throw new RuntimeException( 'Store credit requires a three-letter currency code.' );
		}

		return $currency;
	}
}
