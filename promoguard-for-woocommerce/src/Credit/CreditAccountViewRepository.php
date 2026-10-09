<?php
/**
 * Read-optimized store-credit account persistence.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Credit;

use PromoGuard\Support\TableNames;

/** Loads currency balances and bounded ledger pages for My Account. */
final class CreditAccountViewRepository {
	// Typed arguments and return shapes document this bounded read model.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag

	public const PAGE_SIZE = 20;
	private const MAX_PAGE = 1000;

	/** Configure site-scoped table names. */
	public function __construct( private readonly TableNames $tables ) {}

	/** Build a repository for the active WordPress site. */
	public static function from_wordpress(): self {
		return new self( TableNames::from_wordpress() );
	}

	/**
	 * Return all currency-scoped balances in one grouped query.
	 *
	 * @return array<int,array{currency:string,balance:string,reserved:string,available:string}>
	 */
	public function balances( int $user_id ): array {
		if ( $user_id < 1 ) {
			return array();
		}

		global $wpdb;

		$accounts     = $this->tables->credit_accounts();
		$reservations = $this->tables->credit_reservations();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table identifiers; the user ID is prepared.
		$sql = $wpdb->prepare(
			"SELECT a.currency, a.balance,
				COALESCE(SUM(CASE WHEN r.status = 'reserved' THEN r.amount ELSE 0 END), 0) AS reserved
			FROM {$accounts} a
			LEFT JOIN {$reservations} r ON r.account_id = a.id AND r.status = 'reserved'
			WHERE a.wp_user_id = %d
			GROUP BY a.id, a.currency, a.balance
			ORDER BY a.currency ASC",
			$user_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One indexed, customer-scoped read intentionally reflects current balances.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$balances = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$balance    = CreditAmount::normalize( (string) ( $row['balance'] ?? '0' ) );
			$reserved   = CreditAmount::normalize( (string) ( $row['reserved'] ?? '0' ) );
			$reserved   = CreditAmount::minimum( $reserved, $balance );
			$balances[] = array(
				'currency'  => (string) ( $row['currency'] ?? '' ),
				'balance'   => $balance,
				'reserved'  => $reserved,
				'available' => CreditAmount::subtract( $balance, $reserved ),
			);
		}

		return $balances;
	}

	/**
	 * Return one bounded ledger page without a separate COUNT query.
	 *
	 * @return array{entries:array<int,array{id:int,type:string,source:string,reference:string,amount:string,balance:string,currency:string,description:string,created_at_gmt:string}>,page:int,has_previous:bool,has_next:bool}
	 */
	public function ledger( int $user_id, int $page ): array {
		$page = max( 1, min( self::MAX_PAGE, $page ) );
		if ( $user_id < 1 ) {
			return array(
				'entries'      => array(),
				'page'         => $page,
				'has_previous' => false,
				'has_next'     => false,
			);
		}

		global $wpdb;

		$table  = $this->tables->credit_transactions();
		$limit  = self::PAGE_SIZE + 1;
		$offset = ( $page - 1 ) * self::PAGE_SIZE;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table identifier; customer, limit, and offset are prepared.
		$sql = $wpdb->prepare(
			"SELECT id, type, source, reference_key, amount, balance_after, currency, description, created_at_gmt
			FROM {$table}
			WHERE wp_user_id = %d
			ORDER BY created_at_gmt DESC, id DESC
			LIMIT %d OFFSET %d",
			$user_id,
			$limit,
			$offset
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One bounded, indexed ledger read avoids an additional count query.
		$rows     = $wpdb->get_results( $sql, ARRAY_A );
		$rows     = is_array( $rows ) ? $rows : array();
		$has_next = count( $rows ) > self::PAGE_SIZE;
		$rows     = array_slice( $rows, 0, self::PAGE_SIZE );

		$entries = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$entries[] = array(
				'id'             => (int) ( $row['id'] ?? 0 ),
				'type'           => (string) ( $row['type'] ?? '' ),
				'source'         => (string) ( $row['source'] ?? '' ),
				'reference'      => (string) ( $row['reference_key'] ?? '' ),
				'amount'         => (string) ( $row['amount'] ?? '0' ),
				'balance'        => (string) ( $row['balance_after'] ?? '0' ),
				'currency'       => (string) ( $row['currency'] ?? '' ),
				'description'    => (string) ( $row['description'] ?? '' ),
				'created_at_gmt' => (string) ( $row['created_at_gmt'] ?? '' ),
			);
		}

		return array(
			'entries'      => $entries,
			'page'         => $page,
			'has_previous' => $page > 1,
			'has_next'     => $has_next,
		);
	}
}
