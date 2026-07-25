<?php
/**
 * Paginated campaign analytics repository.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Analytics;

use PromoGuard\Reservation\UsageStatus;
use PromoGuard\Support\TableNames;
use RuntimeException;
use wpdb;

/**
 * Aggregates one campaign page without per-campaign database queries.
 *
 * @phpstan-type CurrencyRow array{currency:string,redemptions:int,refunds:int,discount_amount:string,restored_discount_amount:string,average_discount_amount:string}
 * @phpstan-type CampaignRow array{campaign_id:int,campaign_name:string,redemptions:int,unique_customers:int,campaign_orders:int,refunds:int,denials:int,currencies:array<int,CurrencyRow>}
 */
final class CampaignAnalyticsRepository implements CampaignAnalyticsStore {
	private const MAX_PAGE_SIZE = 50;

	/**
	 * Configure site-scoped plugin table names.
	 *
	 * @param TableNames $tables Plugin-owned table names.
	 */
	public function __construct( private readonly TableNames $tables ) {}

	/** Build the repository for the active WordPress site. */
	public static function from_wordpress(): self {
		return new self( TableNames::from_wordpress() );
	}

	/**
	 * Return one activity-ranked campaign page.
	 *
	 * @param AnalyticsFilter $filter   Validated report filters.
	 * @param int             $page     One-based page.
	 * @param int             $per_page Requested page size.
	 */
	public function campaigns( AnalyticsFilter $filter, int $page, int $per_page ): array {
		global $wpdb;

		$page       = max( 1, $page );
		$per_page   = min( self::MAX_PAGE_SIZE, max( 1, $per_page ) );
		$offset     = ( $page - 1 ) * $per_page;
		$query      = $this->activity_query( $filter );
		$campaigns  = $this->tables->campaigns();
		$item_sql   = "SELECT campaign.id AS campaign_id, campaign.name AS campaign_name, activity.*
			FROM ({$query['sql']}) AS activity
			INNER JOIN {$campaigns} AS campaign ON campaign.id = activity.campaign_id
			ORDER BY activity.redemptions DESC, activity.campaign_id DESC
			LIMIT %d OFFSET %d";
		$count_sql  = "SELECT COUNT(*) FROM ({$query['sql']}) AS activity";
		$item_args  = array_merge( $query['args'], array( $per_page, $offset ) );
		$count_args = $query['args'];

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Query structure and identifiers are repository-owned; values remain prepared.
		$prepared_items = $wpdb->prepare( $item_sql, ...$item_args );
		$prepared_count = $wpdb->prepare( $count_sql, ...$count_args );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$rows  = $this->rows( $wpdb, $prepared_items, 'Campaign analytics could not be loaded.' );
		$total = $this->count( $wpdb, $prepared_count );
		$items = $this->normalize_items( $rows );

		$this->attach_currencies( $wpdb, $filter, $items );

		return array(
			'items'    => array_values( $items ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Build reusable usage-and-denial activity SQL.
	 *
	 * @param AnalyticsFilter $filter Validated report filters.
	 * @return array{sql:string,args:array<int,int|string>}
	 */
	private function activity_query( AnalyticsFilter $filter ): array {
		$usage_campaign    = '';
		$decision_campaign = '';
		$usage_args        = array(
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
		$decision_args     = array(
			$this->tables->decisions(),
			$filter->database_start(),
			$filter->database_end(),
			'denied',
		);

		if ( null !== $filter->campaign_id ) {
			$usage_campaign    = ' AND campaign_id = %d';
			$decision_campaign = ' AND campaign_id = %d';
			$usage_args[]      = $filter->campaign_id;
			$decision_args[]   = $filter->campaign_id;
		}

		$sql = "SELECT campaign_id,
				SUM(redemptions) AS redemptions,
				SUM(unique_customers) AS unique_customers,
				SUM(campaign_orders) AS campaign_orders,
				SUM(refunds) AS refunds,
				SUM(denials) AS denials
			FROM (
				SELECT campaign_id,
					COALESCE(SUM(status = %s), 0) AS redemptions,
					COUNT(DISTINCT CASE WHEN status = %s THEN customer_id END) AS unique_customers,
					COUNT(DISTINCT CASE WHEN status = %s THEN order_id END) AS campaign_orders,
					COALESCE(SUM(status = %s), 0) AS refunds,
					0 AS denials
				FROM %i
				WHERE consumed_at_gmt >= %s AND consumed_at_gmt < %s
					AND status IN (%s, %s){$usage_campaign}
				GROUP BY campaign_id
				UNION ALL
				SELECT campaign_id, 0, 0, 0, 0, COUNT(id)
				FROM %i
				WHERE created_at_gmt >= %s AND created_at_gmt < %s
					AND decision = %s AND campaign_id IS NOT NULL{$decision_campaign}
				GROUP BY campaign_id
			) AS campaign_activity
			GROUP BY campaign_id";

		return array(
			'sql'  => $sql,
			'args' => array_merge( $usage_args, $decision_args ),
		);
	}

	/**
	 * Normalize aggregate rows and key them by campaign ID.
	 *
	 * @param array<int,array<string,mixed>> $rows Aggregate rows.
	 * @return array<int,CampaignRow>
	 */
	private function normalize_items( array $rows ): array {
		$items = array();
		foreach ( $rows as $row ) {
			$campaign_id = $this->integer( $row, 'campaign_id' );
			if ( $campaign_id < 1 ) {
				continue;
			}
			$items[ $campaign_id ] = array(
				'campaign_id'      => $campaign_id,
				'campaign_name'    => isset( $row['campaign_name'] ) ? (string) $row['campaign_name'] : '',
				'redemptions'      => $this->integer( $row, 'redemptions' ),
				'unique_customers' => $this->integer( $row, 'unique_customers' ),
				'campaign_orders'  => $this->integer( $row, 'campaign_orders' ),
				'refunds'          => $this->integer( $row, 'refunds' ),
				'denials'          => $this->integer( $row, 'denials' ),
				'currencies'       => array(),
			);
		}

		return $items;
	}

	/**
	 * Attach one aggregate currency query for the campaigns on the current page.
	 *
	 * @param wpdb                   $wpdb   WordPress database adapter.
	 * @param AnalyticsFilter        $filter Validated report filters.
	 * @param array<int,CampaignRow> $items Campaign rows keyed by ID.
	 */
	private function attach_currencies( wpdb $wpdb, AnalyticsFilter $filter, array &$items ): void {
		if ( array() === $items ) {
			return;
		}

		$campaign_ids = array_keys( $items );
		$placeholders = implode( ', ', array_fill( 0, count( $campaign_ids ), '%d' ) );
		$sql          = "SELECT campaign_id, currency,
				COALESCE(SUM(status = %s), 0) AS redemptions,
				COALESCE(SUM(status = %s), 0) AS refunds,
				COALESCE(SUM(CASE WHEN status = %s THEN discount_amount ELSE 0 END), 0) AS discount_amount,
				COALESCE(SUM(CASE WHEN status = %s THEN discount_amount ELSE 0 END), 0) AS restored_discount_amount
			FROM %i
			WHERE consumed_at_gmt >= %s AND consumed_at_gmt < %s
				AND status IN (%s, %s)
				AND campaign_id IN ({$placeholders})
			GROUP BY campaign_id, currency
			ORDER BY campaign_id ASC, currency ASC";
		$args         = array_merge(
			array(
				UsageStatus::CONSUMED,
				UsageStatus::RESTORED,
				UsageStatus::CONSUMED,
				UsageStatus::RESTORED,
				$this->tables->usages(),
				$filter->database_start(),
				$filter->database_end(),
				UsageStatus::CONSUMED,
				UsageStatus::RESTORED,
			),
			$campaign_ids
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- The only dynamic SQL fragment is a bounded placeholder list.
		$prepared = $wpdb->prepare( $sql, ...$args );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->rows( $wpdb, $prepared, 'Campaign currency analytics could not be loaded.' );

		foreach ( $rows as $row ) {
			$campaign_id = $this->integer( $row, 'campaign_id' );
			if ( ! isset( $items[ $campaign_id ] ) ) {
				continue;
			}
			$redemptions                           = $this->integer( $row, 'redemptions' );
			$discount                              = isset( $row['discount_amount'] ) ? (string) $row['discount_amount'] : '0';
			$items[ $campaign_id ]['currencies'][] = array(
				'currency'                 => isset( $row['currency'] ) ? (string) $row['currency'] : '',
				'redemptions'              => $redemptions,
				'refunds'                  => $this->integer( $row, 'refunds' ),
				'discount_amount'          => $discount,
				'restored_discount_amount' => isset( $row['restored_discount_amount'] ) ? (string) $row['restored_discount_amount'] : '0',
				'average_discount_amount'  => 0 === $redemptions
					? '0'
					: OrderRevenueCalculator::decimal( (float) $discount / $redemptions ),
			);
		}
	}

	/**
	 * Execute one prepared row query.
	 *
	 * @param wpdb        $wpdb     WordPress database adapter.
	 * @param string|null $prepared Prepared SQL.
	 * @param string      $message  Safe failure message.
	 * @return array<int,array<string,mixed>>
	 * @throws RuntimeException When preparation or execution fails.
	 */
	private function rows( wpdb $wpdb, ?string $prepared, string $message ): array {
		if ( ! is_string( $prepared ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal caller supplies a fixed safe message.
			throw new RuntimeException( $message );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared, page-bounded analytics query.
		$rows = $wpdb->get_results( $prepared, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal caller supplies a fixed safe message.
			throw new RuntimeException( $message );
		}

		return array_values( array_filter( $rows, 'is_array' ) );
	}

	/**
	 * Execute one prepared count query.
	 *
	 * @param wpdb        $wpdb     WordPress database adapter.
	 * @param string|null $prepared Prepared SQL.
	 * @throws RuntimeException When preparation fails.
	 */
	private function count( wpdb $wpdb, ?string $prepared ): int {
		if ( ! is_string( $prepared ) ) {
			throw new RuntimeException( 'Campaign analytics count could not be loaded.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared exact count for a bounded administrator page.
		$total = $wpdb->get_var( $prepared );

		return is_numeric( $total ) ? (int) $total : 0;
	}

	/**
	 * Normalize one aggregate integer.
	 *
	 * @param array<string,mixed> $row Aggregate row.
	 * @param string              $key Aggregate key.
	 */
	private function integer( array $row, string $key ): int {
		return isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ? (int) $row[ $key ] : 0;
	}
}
