<?php
/**
 * Indexed administration read repository.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Administration;

use DomainException;
use PromoGuard\Campaign\CampaignStatus;
use PromoGuard\Reservation\UsageStatus;
use PromoGuard\Support\TableNames;
use RuntimeException;
use wpdb;

/** Reads bounded dashboard, usage, and decision data from plugin-owned tables. */
final class AdministrationRepository implements AdministrationStore {
	private const MAX_PAGE_SIZE = 100;

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

	/** {@inheritDoc} */
	public function overview(): array {
		global $wpdb;

		$campaign_rows = $this->grouped_counts( $wpdb, $this->tables->campaigns(), 'status' );
		$usage_rows    = $this->grouped_counts( $wpdb, $this->tables->usages(), 'status' );
		$campaigns     = $this->normalize_counts( CampaignStatus::stored(), $campaign_rows );
		$usages        = $this->normalize_counts( self::usage_statuses(), $usage_rows );

		return array(
			'campaigns' => $campaigns,
			'usages'    => $usages,
			'totals'    => array(
				'campaigns' => array_sum( $campaigns ),
				'usages'    => array_sum( $usages ),
			),
		);
	}

	/**
	 * Return a bounded usage-history page.
	 *
	 * @param int         $page        One-based page number.
	 * @param int         $per_page    Requested page size.
	 * @param int|null    $campaign_id Optional campaign filter.
	 * @param int|null    $order_id    Optional WooCommerce order filter.
	 * @param string|null $status      Optional usage-status filter.
	 * @return array{items: array<int,array<string,mixed>>, total:int, page:int, per_page:int}
	 * @throws DomainException When a filter is invalid.
	 */
	public function usages(
		int $page,
		int $per_page,
		?int $campaign_id,
		?int $order_id,
		?string $status
	): array {
		if ( null !== $status && ! in_array( $status, self::usage_statuses(), true ) ) {
			throw new DomainException( 'Unsupported usage status filter.' );
		}

		$campaign_id = $this->optional_id( $campaign_id );
		$order_id    = $this->optional_id( $order_id );
		$conditions  = array();
		$values      = array();

		if ( null !== $campaign_id ) {
			$conditions[] = 'campaign_usage.campaign_id = %d';
			$values[]     = $campaign_id;
		}
		if ( null !== $order_id ) {
			$conditions[] = 'campaign_usage.order_id = %d';
			$values[]     = $order_id;
		}
		if ( null !== $status ) {
			$conditions[] = 'campaign_usage.status = %s';
			$values[]     = $status;
		}

		$columns = 'campaign_usage.id, campaign_usage.campaign_id, campaign.name AS campaign_name,
			campaign_usage.customer_id, customer.wp_user_id, campaign_usage.order_id,
			campaign_usage.coupon_code, campaign_usage.status, campaign_usage.order_status,
			campaign_usage.discount_amount, campaign_usage.currency,
			campaign_usage.reserved_at_gmt, campaign_usage.consumed_at_gmt,
			campaign_usage.released_at_gmt, campaign_usage.restored_at_gmt,
			campaign_usage.created_at_gmt';
		$from    = $this->tables->usages() . ' AS campaign_usage
			INNER JOIN ' . $this->tables->campaigns() . ' AS campaign
				ON campaign.id = campaign_usage.campaign_id
			INNER JOIN ' . $this->tables->customers() . ' AS customer
				ON customer.id = campaign_usage.customer_id';

		return $this->page( $columns, $from, $conditions, $values, $page, $per_page );
	}

	/**
	 * Return a bounded eligibility-decision page.
	 *
	 * @param int         $page        One-based page number.
	 * @param int         $per_page    Requested page size.
	 * @param int|null    $campaign_id Optional campaign filter.
	 * @param int|null    $order_id    Optional WooCommerce order filter.
	 * @param string|null $reason      Optional exact reason filter.
	 * @return array{items: array<int,array<string,mixed>>, total:int, page:int, per_page:int}
	 * @throws DomainException When a filter is invalid.
	 */
	public function decisions(
		int $page,
		int $per_page,
		?int $campaign_id,
		?int $order_id,
		?string $reason
	): array {
		$campaign_id = $this->optional_id( $campaign_id );
		$order_id    = $this->optional_id( $order_id );
		$reason      = null === $reason || '' === trim( $reason ) ? null : trim( $reason );

		if ( null !== $reason && strlen( $reason ) > 64 ) {
			throw new DomainException( 'Decision reason filter is too long.' );
		}

		$conditions = array();
		$values     = array();
		if ( null !== $campaign_id ) {
			$conditions[] = 'decision_log.campaign_id = %d';
			$values[]     = $campaign_id;
		}
		if ( null !== $order_id ) {
			$conditions[] = 'decision_log.order_id = %d';
			$values[]     = $order_id;
		}
		if ( null !== $reason ) {
			// All persisted decision records are denials, keeping this index prefix selective.
			$conditions[] = 'decision_log.decision = %s AND decision_log.reason = %s';
			$values[]     = 'denied';
			$values[]     = $reason;
		}

		$columns = 'decision_log.id, decision_log.campaign_id, campaign.name AS campaign_name,
			decision_log.customer_id, decision_log.order_id, decision_log.coupon_code,
			decision_log.context, decision_log.decision, decision_log.reason,
			decision_log.customer_message, decision_log.admin_explanation,
			decision_log.created_at_gmt';
		$from    = $this->tables->decisions() . ' AS decision_log
			LEFT JOIN ' . $this->tables->campaigns() . ' AS campaign
				ON campaign.id = decision_log.campaign_id';

		return $this->page( $columns, $from, $conditions, $values, $page, $per_page );
	}

	/**
	 * Return grouped values through an index-leading status column.
	 *
	 * @param wpdb   $wpdb   WordPress database adapter.
	 * @param string $table  Plugin-owned table.
	 * @param string $column Fixed plugin-owned column.
	 * @return array<int,array<string,mixed>>
	 * @throws RuntimeException When the overview query fails.
	 */
	private function grouped_counts( wpdb $wpdb, string $table, string $column ): array {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Both identifiers are fixed plugin-owned schema values.
		$sql = "SELECT {$column} AS group_name, COUNT(id) AS item_count
			FROM {$table}
			GROUP BY {$column}";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One grouped operational count over an indexed status column.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( 'Administration overview could not be loaded.' );
		}

		return array_values( array_filter( $rows, 'is_array' ) );
	}

	/**
	 * Normalize grouped database rows to a complete stable status map.
	 *
	 * @param string[]                       $allowed Allowed group names.
	 * @param array<int,array<string,mixed>> $rows    Database count rows.
	 * @return array<string,int>
	 */
	private function normalize_counts( array $allowed, array $rows ): array {
		$counts = array_fill_keys( $allowed, 0 );

		foreach ( $rows as $row ) {
			$name = isset( $row['group_name'] ) && is_string( $row['group_name'] )
				? $row['group_name']
				: '';
			if ( isset( $counts[ $name ] ) && isset( $row['item_count'] ) && is_numeric( $row['item_count'] ) ) {
				$counts[ $name ] = (int) $row['item_count'];
			}
		}

		return $counts;
	}

	/**
	 * Execute one bounded item query and one exact count query.
	 *
	 * @param string                $columns    Explicit select list.
	 * @param string                $from       Plugin-owned table and joins.
	 * @param string[]              $conditions Prepared SQL predicates.
	 * @param array<int,int|string> $values     Predicate values.
	 * @param int                   $page       One-based page.
	 * @param int                   $per_page   Requested page size.
	 * @return array{items: array<int,array<string,mixed>>, total:int, page:int, per_page:int}
	 * @throws RuntimeException When a query cannot be prepared or executed.
	 */
	private function page(
		string $columns,
		string $from,
		array $conditions,
		array $values,
		int $page,
		int $per_page
	): array {
		global $wpdb;

		$page      = max( 1, $page );
		$per_page  = min( self::MAX_PAGE_SIZE, max( 1, $per_page ) );
		$offset    = ( $page - 1 ) * $per_page;
		$where     = array() === $conditions ? '' : ' WHERE ' . implode( ' AND ', $conditions );
		$item_args = array_merge( $values, array( $per_page, $offset ) );
		$items_sql = "SELECT {$columns} FROM {$from}{$where}
			ORDER BY 1 DESC LIMIT %d OFFSET %d";
		$count_sql = "SELECT COUNT(*) FROM {$from}{$where}";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- SQL structure contains only repository-owned columns, tables, and placeholder predicates.
		$prepared_items = $wpdb->prepare( $items_sql, ...$item_args );
		$prepared_count = array() === $values ? $count_sql : $wpdb->prepare( $count_sql, ...$values );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_string( $prepared_items ) || ! is_string( $prepared_count ) ) {
			throw new RuntimeException( 'Administration history query could not be prepared.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared and strictly bounded administration history page.
		$rows = $wpdb->get_results( $prepared_items, ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact count accompanies one bounded administrator page.
		$total = $wpdb->get_var( $prepared_count );

		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( 'Administration history could not be loaded.' );
		}

		return array(
			'items'    => array_values( array_filter( $rows, 'is_array' ) ),
			'total'    => is_numeric( $total ) ? (int) $total : 0,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Normalize an optional positive identifier.
	 *
	 * @param int|null $identifier Optional identifier.
	 * @throws DomainException When an identifier is not positive.
	 */
	private function optional_id( ?int $identifier ): ?int {
		if ( null === $identifier ) {
			return null;
		}
		if ( $identifier < 1 ) {
			throw new DomainException( 'Administration filters require positive identifiers.' );
		}

		return $identifier;
	}

	/**
	 * Return every persisted usage lifecycle state.
	 *
	 * @return string[]
	 */
	private static function usage_statuses(): array {
		return array(
			UsageStatus::PENDING,
			UsageStatus::CONSUMED,
			UsageStatus::RELEASED,
			UsageStatus::RESTORED,
		);
	}
}
