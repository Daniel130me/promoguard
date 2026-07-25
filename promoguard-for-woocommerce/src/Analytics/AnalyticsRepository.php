<?php
/**
 * Indexed analytics read repository.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Analytics;

use PromoGuard\Reservation\UsageStatus;
use PromoGuard\Support\TableNames;
use RuntimeException;
use wpdb;

/** Aggregates promotion performance from plugin-owned, date-indexed tables. */
final class AnalyticsRepository implements AnalyticsStore, OrderIdPageSource {
	private const MAX_REASON_GROUPS = 20;

	/**
	 * Configure site-scoped table names.
	 *
	 * @param TableNames $tables Plugin-owned table names.
	 */
	public function __construct( private readonly TableNames $tables ) {}

	/** Build the repository for the active WordPress site. */
	public static function from_wordpress(): self {
		return new self( TableNames::from_wordpress() );
	}

	/**
	 * Return aggregate ledger and denial metrics.
	 *
	 * @param AnalyticsFilter $filter Validated report filters.
	 */
	public function summary( AnalyticsFilter $filter ): array {
		global $wpdb;

		$totals     = $this->usage_totals( $wpdb, $filter );
		$currencies = $this->currency_totals( $wpdb, $filter );
		$reasons    = $this->denial_reasons( $wpdb, $filter );

		$totals['denials'] = array_sum( array_column( $reasons, 'count' ) );

		return array(
			'totals'         => $totals,
			'currencies'     => $currencies,
			'denial_reasons' => $reasons,
		);
	}

	/**
	 * Read non-monetary totals without adding distinct customers across currencies.
	 *
	 * @param wpdb            $wpdb   WordPress database adapter.
	 * @param AnalyticsFilter $filter Validated report filters.
	 * @return array{redemptions:int,unique_customers:int,campaign_orders:int,global_orders:int,refunds:int,denials:int}
	 */
	private function usage_totals( wpdb $wpdb, AnalyticsFilter $filter ): array {
		$arguments = array(
			UsageStatus::CONSUMED,
			UsageStatus::CONSUMED,
			UsageStatus::CONSUMED,
			UsageStatus::CONSUMED,
			UsageStatus::RESTORED,
			$this->tables->usages(),
			$filter->database_start(),
			$filter->database_end(),
			UsageStatus::CONSUMED,
			UsageStatus::RESTORED,
		);

		if ( null === $filter->campaign_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Variadic argument list matches the fixed query placeholders.
			$prepared = $wpdb->prepare(
				'SELECT
					COALESCE(SUM(status = %s), 0) AS redemptions,
					COUNT(DISTINCT CASE WHEN status = %s THEN customer_id END) AS unique_customers,
					COUNT(DISTINCT CASE WHEN status = %s THEN CONCAT_WS(CHAR(58), order_id, campaign_id) END) AS campaign_orders,
					COUNT(DISTINCT CASE WHEN status = %s THEN order_id END) AS global_orders,
					COALESCE(SUM(status = %s), 0) AS refunds
				 FROM %i
				 WHERE consumed_at_gmt >= %s AND consumed_at_gmt < %s
				   AND status IN (%s, %s)',
				...$arguments
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Variadic argument list matches the fixed query placeholders.
			$prepared = $wpdb->prepare(
				'SELECT
					COALESCE(SUM(status = %s), 0) AS redemptions,
					COUNT(DISTINCT CASE WHEN status = %s THEN customer_id END) AS unique_customers,
					COUNT(DISTINCT CASE WHEN status = %s THEN CONCAT_WS(CHAR(58), order_id, campaign_id) END) AS campaign_orders,
					COUNT(DISTINCT CASE WHEN status = %s THEN order_id END) AS global_orders,
					COALESCE(SUM(status = %s), 0) AS refunds
				 FROM %i
				 WHERE consumed_at_gmt >= %s AND consumed_at_gmt < %s
				   AND status IN (%s, %s) AND campaign_id = %d',
				...array_merge( $arguments, array( $filter->campaign_id ) )
			);
		}

		$row = $this->row( $wpdb, $prepared, 'Analytics totals could not be loaded.' );

		return array(
			'redemptions'      => $this->integer( $row, 'redemptions' ),
			'unique_customers' => $this->integer( $row, 'unique_customers' ),
			'campaign_orders'  => $this->integer( $row, 'campaign_orders' ),
			'global_orders'    => $this->integer( $row, 'global_orders' ),
			'refunds'          => $this->integer( $row, 'refunds' ),
			'denials'          => 0,
		);
	}

	/**
	 * Preserve exact monetary totals per stored order currency.
	 *
	 * @param wpdb            $wpdb   WordPress database adapter.
	 * @param AnalyticsFilter $filter Validated report filters.
	 * @return array<int,array{currency:string,redemptions:int,refunds:int,discount_amount:string,restored_discount_amount:string}>
	 */
	private function currency_totals( wpdb $wpdb, AnalyticsFilter $filter ): array {
		$arguments = array(
			UsageStatus::CONSUMED,
			UsageStatus::RESTORED,
			UsageStatus::CONSUMED,
			UsageStatus::RESTORED,
			$this->tables->usages(),
			$filter->database_start(),
			$filter->database_end(),
			UsageStatus::CONSUMED,
			UsageStatus::RESTORED,
		);

		if ( null === $filter->campaign_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Variadic argument list matches the fixed query placeholders.
			$prepared = $wpdb->prepare(
				'SELECT currency,
					COALESCE(SUM(status = %s), 0) AS redemptions,
					COALESCE(SUM(status = %s), 0) AS refunds,
					COALESCE(SUM(CASE WHEN status = %s THEN discount_amount ELSE 0 END), 0) AS discount_amount,
					COALESCE(SUM(CASE WHEN status = %s THEN discount_amount ELSE 0 END), 0) AS restored_discount_amount
				 FROM %i
				 WHERE consumed_at_gmt >= %s AND consumed_at_gmt < %s
				   AND status IN (%s, %s)
				 GROUP BY currency ORDER BY currency ASC',
				...$arguments
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Variadic argument list matches the fixed query placeholders.
			$prepared = $wpdb->prepare(
				'SELECT currency,
					COALESCE(SUM(status = %s), 0) AS redemptions,
					COALESCE(SUM(status = %s), 0) AS refunds,
					COALESCE(SUM(CASE WHEN status = %s THEN discount_amount ELSE 0 END), 0) AS discount_amount,
					COALESCE(SUM(CASE WHEN status = %s THEN discount_amount ELSE 0 END), 0) AS restored_discount_amount
				 FROM %i
				 WHERE consumed_at_gmt >= %s AND consumed_at_gmt < %s
				   AND status IN (%s, %s) AND campaign_id = %d
				 GROUP BY currency ORDER BY currency ASC',
				...array_merge( $arguments, array( $filter->campaign_id ) )
			);
		}

		$rows = $this->rows( $wpdb, $prepared, 'Analytics currency totals could not be loaded.' );

		return array_map(
			fn ( array $row ): array => array(
				'currency'                 => isset( $row['currency'] ) ? (string) $row['currency'] : '',
				'redemptions'              => $this->integer( $row, 'redemptions' ),
				'refunds'                  => $this->integer( $row, 'refunds' ),
				'discount_amount'          => isset( $row['discount_amount'] ) ? (string) $row['discount_amount'] : '0',
				'restored_discount_amount' => isset( $row['restored_discount_amount'] ) ? (string) $row['restored_discount_amount'] : '0',
			),
			$rows
		);
	}

	/**
	 * Return one bounded page of distinct consumed order IDs.
	 *
	 * @param AnalyticsFilter $filter         Validated report filters.
	 * @param int             $after_order_id Exclusive order-ID cursor.
	 * @param int             $limit          Maximum page size.
	 * @return int[]
	 */
	public function order_ids_after( AnalyticsFilter $filter, int $after_order_id, int $limit ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );
		$args  = array(
			$this->tables->usages(),
			$filter->database_start(),
			$filter->database_end(),
			UsageStatus::CONSUMED,
			max( 0, $after_order_id ),
		);

		if ( null === $filter->campaign_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Variadic arguments match the fixed query placeholders.
			$prepared = $wpdb->prepare(
				'SELECT DISTINCT order_id FROM %i
				 WHERE consumed_at_gmt >= %s AND consumed_at_gmt < %s
				   AND status = %s AND order_id > %d
				 ORDER BY order_id ASC LIMIT %d',
				...array_merge( $args, array( $limit ) )
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Variadic arguments match the fixed query placeholders.
			$prepared = $wpdb->prepare(
				'SELECT DISTINCT order_id FROM %i
				 WHERE consumed_at_gmt >= %s AND consumed_at_gmt < %s
				   AND status = %s AND order_id > %d AND campaign_id = %d
				 ORDER BY order_id ASC LIMIT %d',
				...array_merge( $args, array( $filter->campaign_id, $limit ) )
			);
		}

		$rows = $this->rows( $wpdb, $prepared, 'Analytics order IDs could not be loaded.' );

		return array_values(
			array_filter(
				array_map( fn ( array $row ): int => $this->integer( $row, 'order_id' ), $rows ),
				static fn ( int $order_id ): bool => $order_id > $after_order_id
			)
		);
	}
	/**
	 * Return the bounded denial-reason distribution.
	 *
	 * @param wpdb            $wpdb   WordPress database adapter.
	 * @param AnalyticsFilter $filter Validated report filters.
	 * @return array<int,array{reason:string,count:int}>
	 */
	private function denial_reasons( wpdb $wpdb, AnalyticsFilter $filter ): array {
		$arguments = array(
			$this->tables->decisions(),
			$filter->database_start(),
			$filter->database_end(),
			'denied',
		);

		if ( null === $filter->campaign_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Variadic argument list matches the fixed query placeholders.
			$prepared = $wpdb->prepare(
				'SELECT reason, COUNT(id) AS denial_count
				 FROM %i
				 WHERE created_at_gmt >= %s AND created_at_gmt < %s AND decision = %s
				 GROUP BY reason
				 ORDER BY denial_count DESC, reason ASC LIMIT %d',
				...array_merge( $arguments, array( self::MAX_REASON_GROUPS ) )
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Variadic argument list matches the fixed query placeholders.
			$prepared = $wpdb->prepare(
				'SELECT reason, COUNT(id) AS denial_count
				 FROM %i
				 WHERE created_at_gmt >= %s AND created_at_gmt < %s AND decision = %s
				   AND campaign_id = %d
				 GROUP BY reason
				 ORDER BY denial_count DESC, reason ASC LIMIT %d',
				...array_merge( $arguments, array( $filter->campaign_id, self::MAX_REASON_GROUPS ) )
			);
		}

		$rows   = $this->rows( $wpdb, $prepared, 'Analytics denial totals could not be loaded.' );
		$result = array();

		foreach ( $rows as $row ) {
			$result[] = array(
				'reason' => isset( $row['reason'] ) ? (string) $row['reason'] : '',
				'count'  => $this->integer( $row, 'denial_count' ),
			);
		}

		return $result;
	}

	/**
	 * Return the first row from one prepared aggregate query.
	 *
	 * @param wpdb        $wpdb     WordPress database adapter.
	 * @param string|null $prepared Prepared SQL, or null after a preparation failure.
	 * @param string      $message  Safe failure message.
	 * @return array<string,mixed>
	 * @throws RuntimeException When preparation or execution fails.
	 */
	private function row( wpdb $wpdb, ?string $prepared, string $message ): array {
		$rows = $this->rows( $wpdb, $prepared, $message );

		return $rows[0] ?? array();
	}

	/**
	 * Execute one prepared aggregate query.
	 *
	 * @param wpdb        $wpdb     WordPress database adapter.
	 * @param string|null $prepared Prepared SQL, or null after a preparation failure.
	 * @param string      $message  Safe failure message.
	 * @return array<int,array<string,mixed>>
	 * @throws RuntimeException When preparation or execution fails.
	 */
	private function rows( wpdb $wpdb, ?string $prepared, string $message ): array {
		if ( ! is_string( $prepared ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal constant message is never rendered.
			throw new RuntimeException( $message );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared analytics aggregate over date-indexed plugin tables.
		$rows = $wpdb->get_results( $prepared, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal constant message is never rendered.
			throw new RuntimeException( $message );
		}

		return array_values( array_filter( $rows, 'is_array' ) );
	}

	/**
	 * Normalize one aggregate count.
	 *
	 * @param array<string,mixed> $row Aggregate row.
	 * @param string              $key Aggregate key.
	 */
	private function integer( array $row, string $key ): int {
		return isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ? (int) $row[ $key ] : 0;
	}
}
