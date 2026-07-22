<?php
/**
 * Campaign database repository.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Campaign;

use DomainException;
use PromoGuard\Support\TableNames;
use RuntimeException;

/** Provides bounded persistence operations for PromoGuard campaigns. */
final class CampaignRepository implements CampaignStore {
	private const MAX_PAGE_SIZE = 100;

	private const SELECT_COLUMNS = 'id, uuid, name, slug, description, goal, status, priority, starts_at_gmt, ends_at_gmt, usage_rules, conflict_rules, settings, created_by, created_at_gmt, updated_at_gmt';

	/**
	 * Site-scoped PromoGuard table names.
	 *
	 * @var TableNames
	 */
	private TableNames $tables;

	/**
	 * Strict database row mapper.
	 *
	 * @var CampaignHydrator
	 */
	private CampaignHydrator $hydrator;

	/**
	 * Configure campaign persistence dependencies.
	 *
	 * @param TableNames            $tables   Site-scoped plugin table names.
	 * @param CampaignHydrator|null $hydrator Optional persistence mapper.
	 */
	public function __construct( TableNames $tables, ?CampaignHydrator $hydrator = null ) {
		$this->tables   = $tables;
		$this->hydrator = $hydrator ?? new CampaignHydrator();
	}

	/** Build a repository for the active WordPress site. */
	public static function from_wordpress(): self {
		return new self( TableNames::from_wordpress() );
	}

	/**
	 * Insert a new campaign.
	 *
	 * @param Campaign $campaign Validated unsaved campaign.
	 * @throws RuntimeException When persistence fails.
	 */
	public function create( Campaign $campaign ): int {
		if ( null !== $campaign->id ) {
			throw new RuntimeException( 'A persisted campaign cannot be inserted again.' );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Repository writes only to a plugin-owned table through wpdb's prepared insert API.
		$result = $wpdb->insert(
			$this->tables->campaigns(),
			$this->hydrator->to_row( $campaign ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $result || $wpdb->insert_id < 1 ) {
			throw new RuntimeException( 'Campaign could not be created.' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a mutable campaign.
	 *
	 * @param Campaign      $campaign Validated persisted campaign.
	 * @param Campaign|null $current  Previously loaded campaign, when available.
	 * @throws DomainException  When an archived campaign is edited.
	 * @throws RuntimeException When persistence fails.
	 */
	public function update( Campaign $campaign, ?Campaign $current = null ): bool {
		if ( null === $campaign->id ) {
			throw new RuntimeException( 'An unsaved campaign cannot be updated.' );
		}

		$current ??= $this->find( $campaign->id );

		if ( null === $current ) {
			return false;
		}

		if ( CampaignStatus::is_read_only( $current->status ) ) {
			throw new DomainException( 'Archived campaigns are read-only.' );
		}

		global $wpdb;

		$row = $this->hydrator->to_row( $campaign );
		unset( $row['uuid'], $row['created_by'], $row['created_at_gmt'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Repository writes only to a plugin-owned table through wpdb's prepared update API.
		$result = $wpdb->update(
			$this->tables->campaigns(),
			$row,
			array(
				'id'     => $campaign->id,
				'status' => $current->status,
			),
			null,
			array( '%d', '%s' )
		);

		if ( false === $result ) {
			throw new RuntimeException( 'Campaign could not be updated.' );
		}

		return true;
	}

	/**
	 * Find one campaign by its primary key.
	 *
	 * @param int $campaign_id Campaign primary key.
	 */
	public function find( int $campaign_id ): ?Campaign {
		if ( $campaign_id < 1 ) {
			return null;
		}

		global $wpdb;

		$table = $this->tables->campaigns();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- The table and fixed column list are plugin-controlled identifiers.
		$sql = $wpdb->prepare(
			'SELECT ' . self::SELECT_COLUMNS . " FROM {$table} WHERE id = %d LIMIT 1",
			$campaign_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One prepared, bounded primary-key lookup; request-level consumers may cache aggregates.
		$row = $wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $this->hydrator->from_row( $row ) : null;
	}

	/**
	 * Return a bounded campaign page and exact total.
	 *
	 * @param int         $page     One-based page number.
	 * @param int         $per_page Requested page size.
	 * @param string|null $status   Optional exact stored status filter.
	 * @return array{items: Campaign[], total: int, page: int, per_page: int}
	 * @throws DomainException When the status filter is unsupported.
	 */
	public function page( int $page, int $per_page, ?string $status = null ): array {
		if ( null !== $status && ! CampaignStatus::is_stored( $status ) ) {
			throw new DomainException( 'Unsupported campaign status filter.' );
		}

		$page     = max( 1, $page );
		$per_page = min( self::MAX_PAGE_SIZE, max( 1, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		global $wpdb;

		$table = $this->tables->campaigns();

		if ( null === $status ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- The table and fixed column list are plugin-controlled identifiers.
			$items_sql = $wpdb->prepare(
				'SELECT ' . self::SELECT_COLUMNS . " FROM {$table} ORDER BY priority DESC, id DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			);
			$count_sql = "SELECT COUNT(id) FROM {$table}";
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		} else {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- The table and fixed column list are plugin-controlled identifiers.
			$items_sql = $wpdb->prepare(
				'SELECT ' . self::SELECT_COLUMNS . " FROM {$table} WHERE status = %s ORDER BY priority DESC, id DESC LIMIT %d OFFSET %d",
				$status,
				$per_page,
				$offset
			);
			$count_sql = $wpdb->prepare( "SELECT COUNT(id) FROM {$table} WHERE status = %s", $status );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared, bounded administrator list backed by campaign indexes.
		$rows = $wpdb->get_results( $items_sql, ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared exact count accompanies the bounded administrator list.
		$total = $wpdb->get_var( $count_sql );

		$items = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) ) {
				$items[] = $this->hydrator->from_row( $row );
			}
		}

		return array(
			'items'    => $items,
			'total'    => is_numeric( $total ) ? (int) $total : 0,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Archive a campaign without deleting its history.
	 *
	 * @param int $campaign_id Campaign primary key.
	 */
	public function archive( int $campaign_id ): bool {
		if ( $campaign_id < 1 ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Repository writes only to a plugin-owned table through wpdb's prepared update API.
		$result = $wpdb->update(
			$this->tables->campaigns(),
			array(
				'status'         => CampaignStatus::ARCHIVED,
				'updated_at_gmt' => current_time( 'mysql', true ),
			),
			array( 'id' => $campaign_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Permanently delete only an unused Draft campaign.
	 *
	 * The single conditional statement prevents a check/delete gap and never
	 * touches WooCommerce coupons, orders, or preserved snapshot rows.
	 *
	 * @param int $campaign_id Campaign primary key.
	 */
	public function delete_unused_draft( int $campaign_id ): bool {
		if ( $campaign_id < 1 ) {
			return false;
		}

		global $wpdb;

		$campaigns  = $this->tables->campaigns();
		$promotions = $this->tables->campaign_promotions();
		$usages     = $this->tables->usages();
		$decisions  = $this->tables->decisions();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- All interpolated identifiers are centralized plugin-owned table names.
		$sql = $wpdb->prepare(
			"DELETE campaign FROM {$campaigns} AS campaign
			WHERE campaign.id = %d
			AND campaign.status = %s
			AND NOT EXISTS (SELECT 1 FROM {$promotions} AS promotion WHERE promotion.campaign_id = campaign.id)
			AND NOT EXISTS (SELECT 1 FROM {$usages} AS campaign_usage WHERE campaign_usage.campaign_id = campaign.id)
			AND NOT EXISTS (SELECT 1 FROM {$decisions} AS decision_log WHERE decision_log.campaign_id = campaign.id)",
			$campaign_id,
			CampaignStatus::DRAFT
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Atomic, prepared, primary-key-scoped deletion of one unused plugin record.
		$deleted = $wpdb->query( $sql );

		return 1 === $deleted;
	}
}
