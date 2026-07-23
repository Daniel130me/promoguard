<?php
/**
 * Campaign promotion assignment repository.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Promotion;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use JsonException;
use PromoGuard\Campaign\CampaignStatus;
use PromoGuard\Support\TableNames;
use RuntimeException;
use Throwable;

/** Owns atomic attachment, reassignment, and detachment operations. */
final class CampaignPromotionRepository implements CampaignPromotionStore {
	private const DATE_FORMAT   = 'Y-m-d H:i:s';
	private const MAX_PAGE_SIZE = 100;
	private const COLUMNS       = 'id, uuid, campaign_id, source, source_type, external_id, external_code, channel, label, sort_order, settings, created_at_gmt, updated_at_gmt';

	/**
	 * Site-scoped plugin table names.
	 *
	 * @var TableNames
	 */
	private TableNames $tables;

	/**
	 * Initialize the repository.
	 *
	 * @param TableNames $tables Site-scoped plugin table names.
	 */
	public function __construct( TableNames $tables ) {
		$this->tables = $tables;
	}

	/** Build a repository for the active WordPress site. */
	public static function from_wordpress(): self {
		return new self( TableNames::from_wordpress() );
	}

	/**
	 * Attach or explicitly reassign one source promotion.
	 *
	 * @param int                 $campaign_id       Destination campaign ID.
	 * @param Promotion           $promotion         Resolved source promotion.
	 * @param bool                $allow_reassignment Whether ownership may change.
	 * @param string|null         $channel           Optional acquisition channel.
	 * @param string|null         $label             Optional administrative label.
	 * @param int                 $sort_order        Administrative sort order.
	 * @param array<string,mixed> $settings          Assignment settings.
	 * @throws DomainException  When the campaign or reassignment is invalid.

	 * @throws Throwable        When an unexpected transaction operation fails.
	 */
	public function assign(
		int $campaign_id,
		Promotion $promotion,
		bool $allow_reassignment = false,
		?string $channel = null,
		?string $label = null,
		int $sort_order = 0,
		array $settings = array()
	): CampaignPromotion {
		global $wpdb;

		$now_gmt = new DateTimeImmutable( current_time( 'mysql', true ), new DateTimeZone( 'UTC' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Starts the short plugin-owned assignment transaction.
		$wpdb->query( 'START TRANSACTION' );

		try {
			$this->lock_mutable_campaign( $campaign_id );
			$current = $this->find_by_source( $promotion->source, $promotion->source_type, $promotion->external_id, true );

			if ( null !== $current && $current->campaign_id !== $campaign_id && ! $allow_reassignment ) {
				throw new DomainException( 'Promotion is already assigned to another campaign.' );
			}

			$uuid           = null === $current ? wp_generate_uuid4() : $current->uuid;
			$created_at_gmt = null === $current ? $now_gmt : $current->created_at_gmt;

			$assignment = new CampaignPromotion(
				id: $current?->id,
				uuid: $uuid,
				campaign_id: $campaign_id,
				source: $promotion->source,
				source_type: $promotion->source_type,
				external_id: $promotion->external_id,
				external_code: $promotion->code,
				channel: $channel,
				label: $label ?? $promotion->label,
				sort_order: $sort_order,
				settings: $settings,
				created_at_gmt: $created_at_gmt,
				updated_at_gmt: $now_gmt
			);

			$assignment = null === $current
				? $this->insert( $assignment )
				: $this->update( $assignment );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Commits only the plugin-owned assignment transaction.
			$wpdb->query( 'COMMIT' );

			return $assignment;
		} catch ( Throwable $exception ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Rolls back only the plugin-owned assignment transaction.
			$wpdb->query( 'ROLLBACK' );
			throw $exception;
		}
	}

	/**
	 * Find one assignment by its globally unique source identity.
	 *
	 * @param string $source      Source key.
	 * @param string $source_type Source promotion type.
	 * @param string $external_id Stable source identifier.
	 * @param bool   $for_update  Whether to lock the matching row.
	 */
	public function find_by_source(
		string $source,
		string $source_type,
		string $external_id,
		bool $for_update = false
	): ?CampaignPromotion {
		global $wpdb;

		$table       = $this->tables->campaign_promotions();
		$lock_clause = $for_update ? ' FOR UPDATE' : '';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Fixed columns/table and a fixed optional lock clause; all values are placeholders.
		$sql = $wpdb->prepare(
			'SELECT ' . self::COLUMNS . " FROM {$table} WHERE source = %s AND source_type = %s AND external_id = %s LIMIT 1{$lock_clause}",
			$source,
			$source_type,
			$external_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Unique indexed source lookup; callers control request caching.
		$row = $wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Return a bounded campaign assignment list.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @param int $limit       Requested result cap.
	 * @return CampaignPromotion[]
	 */
	public function for_campaign( int $campaign_id, int $limit = self::MAX_PAGE_SIZE ): array {
		$limit = min( self::MAX_PAGE_SIZE, max( 1, $limit ) );

		global $wpdb;

		$table = $this->tables->campaign_promotions();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Fixed columns and plugin-owned table; values use placeholders.
		$sql = $wpdb->prepare(
			'SELECT ' . self::COLUMNS . " FROM {$table} WHERE campaign_id = %d ORDER BY sort_order ASC, id ASC LIMIT %d",
			$campaign_id,
			$limit
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded indexed administrator lookup.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$assignments = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) ) {
				$assignments[] = $this->hydrate( $row );
			}
		}

		return $assignments;
	}

	/**
	 * Delete only the active assignment row, preserving coupons and snapshots.
	 *
	 * @param int $assignment_id Assignment primary key.
	 * @param int $campaign_id   Owning campaign ID.
	 */
	public function detach( int $assignment_id, int $campaign_id ): bool {
		if ( $assignment_id < 1 || $campaign_id < 1 ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes one plugin assignment only; source coupons and snapshot tables are untouched.
		$deleted = $wpdb->delete(
			$this->tables->campaign_promotions(),
			array(
				'id'          => $assignment_id,
				'campaign_id' => $campaign_id,
			),
			array( '%d', '%d' )
		);

		return 1 === $deleted;
	}

	/**
	 * Lock and validate the destination campaign.
	 *
	 * @param int $campaign_id Destination campaign ID.
	 * @throws DomainException When the campaign is missing or immutable.
	 */
	private function lock_mutable_campaign( int $campaign_id ): void {
		global $wpdb;

		$table = $this->tables->campaigns();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Centralized plugin table identifier; campaign ID is prepared.
		$sql = $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d LIMIT 1 FOR UPDATE", $campaign_id );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Primary-key lock inside the assignment transaction.
		$status = $wpdb->get_var( $sql );

		if ( ! is_string( $status ) ) {
			throw new DomainException( 'Campaign does not exist.' );
		}

		if ( CampaignStatus::ARCHIVED === $status ) {
			throw new DomainException( 'Archived campaigns are read-only.' );
		}
	}

	/**
	 * Persist a new assignment and return it with its primary key.
	 *
	 * @param CampaignPromotion $assignment Unsaved assignment.
	 * @throws RuntimeException When the insert fails.
	 */
	private function insert( CampaignPromotion $assignment ): CampaignPromotion {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared insert into one plugin-owned assignment table.
		$inserted = $wpdb->insert(
			$this->tables->campaign_promotions(),
			$this->to_row( $assignment ),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted || $wpdb->insert_id < 1 ) {
			throw new RuntimeException( 'Promotion assignment could not be created.' );
		}

		return $this->copy_with_id( $assignment, (int) $wpdb->insert_id );
	}

	/**
	 * Persist an existing assignment, including explicit reassignment.
	 *
	 * @param CampaignPromotion $assignment Persisted assignment.
	 * @throws RuntimeException When the assignment is unsaved or the update fails.
	 */
	private function update( CampaignPromotion $assignment ): CampaignPromotion {
		global $wpdb;

		if ( null === $assignment->id ) {
			throw new RuntimeException( 'Unsaved promotion assignment cannot be updated.' );
		}

		$row = $this->to_row( $assignment );
		unset( $row['uuid'], $row['source'], $row['source_type'], $row['external_id'], $row['created_at_gmt'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared update of the locked plugin assignment row.
		$updated = $wpdb->update(
			$this->tables->campaign_promotions(),
			$row,
			array( 'id' => $assignment->id ),
			null,
			array( '%d' )
		);

		if ( false === $updated ) {
			throw new RuntimeException( 'Promotion assignment could not be updated.' );
		}

		return $assignment;
	}

	/**
	 * Convert an assignment to its database representation.
	 *
	 * @param CampaignPromotion $assignment Assignment to serialize.
	 * @return array<string,int|string|null>
	 * @throws RuntimeException When settings cannot be encoded.
	 */
	private function to_row( CampaignPromotion $assignment ): array {
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- JSON_THROW_ON_ERROR is required to avoid silently persisting invalid settings.
			$settings = json_encode( (object) $assignment->settings, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is diagnostic context, never rendered.
			throw new RuntimeException( 'Promotion assignment settings could not be encoded.', 0, $exception );
		}

		return array(
			'uuid'           => $assignment->uuid,
			'campaign_id'    => $assignment->campaign_id,
			'source'         => $assignment->source,
			'source_type'    => $assignment->source_type,
			'external_id'    => $assignment->external_id,
			'external_code'  => $assignment->external_code,
			'channel'        => $assignment->channel,
			'label'          => $assignment->label,
			'sort_order'     => $assignment->sort_order,
			'settings'       => $settings,
			'created_at_gmt' => $assignment->created_at_gmt->format( self::DATE_FORMAT ),
			'updated_at_gmt' => $assignment->updated_at_gmt->format( self::DATE_FORMAT ),
		);
	}

	/**
	 * Hydrate a stored assignment.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @throws RuntimeException When the stored settings are invalid.
	 */
	private function hydrate( array $row ): CampaignPromotion {
		try {
			$settings_object = json_decode( (string) $row['settings'], false, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is diagnostic context, never rendered.
			throw new RuntimeException( 'Stored promotion assignment settings are invalid.', 0, $exception );
		}

		if ( array() === $settings_object ) {
			// Early Phase 2 builds encoded empty assignment settings as [] instead of {}.
			$settings = array();
		} elseif ( is_object( $settings_object ) ) {
			$settings = get_object_vars( $settings_object );
		} else {
			throw new RuntimeException( 'Stored promotion assignment settings must be an object.' );
		}
		$gmt = new DateTimeZone( 'UTC' );

		return new CampaignPromotion(
			id: (int) $row['id'],
			uuid: (string) $row['uuid'],
			campaign_id: (int) $row['campaign_id'],
			source: (string) $row['source'],
			source_type: (string) $row['source_type'],
			external_id: (string) $row['external_id'],
			external_code: null === $row['external_code'] ? null : (string) $row['external_code'],
			channel: null === $row['channel'] ? null : (string) $row['channel'],
			label: null === $row['label'] ? null : (string) $row['label'],
			sort_order: (int) $row['sort_order'],
			settings: $settings,
			created_at_gmt: new DateTimeImmutable( (string) $row['created_at_gmt'], $gmt ),
			updated_at_gmt: new DateTimeImmutable( (string) $row['updated_at_gmt'], $gmt )
		);
	}

	/**
	 * Copy a newly inserted assignment with its generated primary key.
	 *
	 * @param CampaignPromotion $assignment Inserted assignment.
	 * @param int               $id         Generated primary key.
	 */
	private function copy_with_id( CampaignPromotion $assignment, int $id ): CampaignPromotion {
		return new CampaignPromotion(
			id: $id,
			uuid: $assignment->uuid,
			campaign_id: $assignment->campaign_id,
			source: $assignment->source,
			source_type: $assignment->source_type,
			external_id: $assignment->external_id,
			external_code: $assignment->external_code,
			channel: $assignment->channel,
			label: $assignment->label,
			sort_order: $assignment->sort_order,
			settings: $assignment->settings,
			created_at_gmt: $assignment->created_at_gmt,
			updated_at_gmt: $assignment->updated_at_gmt
		);
	}
}
