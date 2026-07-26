<?php
/**
 * Privacy retention database repository.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Privacy;

use DateTimeImmutable;
use PromoGuard\Support\TableNames;
use RuntimeException;

/** Deletes expired diagnostics through indexed, bounded queries. */
final class RetentionRepository implements RetentionStore {
	/**
	 * Configure site-scoped retention persistence.
	 *
	 * @param TableNames $tables Plugin table names.
	 */
	public function __construct( private readonly TableNames $tables ) {}

	/**
	 * Delete a bounded batch of decisions older than the retention boundary.
	 *
	 * IDs are selected first so the delete cannot expand beyond the requested
	 * batch if new rows are inserted while cleanup is running.
	 *
	 * @param DateTimeImmutable $retained_after_gmt Exclusive GMT boundary.
	 * @param int               $limit              Maximum rows to delete.
	 * @throws RuntimeException When selection, preparation, or deletion fails.
	 */
	public function delete_decisions_before( DateTimeImmutable $retained_after_gmt, int $limit ): int {
		global $wpdb;

		$limit = max( 1, $limit );
		$sql   = $wpdb->prepare(
			'SELECT id FROM %i
			 WHERE created_at_gmt < %s
			 ORDER BY created_at_gmt ASC, id ASC
			 LIMIT %d',
			$this->tables->decisions(),
			$retained_after_gmt->format( 'Y-m-d H:i:s' ),
			$limit
		);

		if ( null === $sql ) {
			throw new RuntimeException( 'Could not prepare the decision retention lookup.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded indexed background lookup.
		$ids = $wpdb->get_col( $sql );
		if ( ! is_array( $ids ) ) {
			throw new RuntimeException( 'Could not read expired decision records.' );
		}

		$ids = array_values(
			array_filter(
				array_map( 'intval', $ids ),
				static fn ( int $id ): bool => $id > 0
			)
		);
		if ( array() === $ids ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The bounded placeholder list is generated internally and every selected integer ID is passed to prepare.
		$delete_sql = $wpdb->prepare(
			"DELETE FROM %i WHERE id IN ({$placeholders})",
			$this->tables->decisions(),
			...$ids
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		if ( null === $delete_sql ) {
			throw new RuntimeException( 'Could not prepare expired decision deletion.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Prepared delete is limited to the previously bounded primary-key list.
		$deleted = $wpdb->query( $delete_sql );
		if ( false === $deleted ) {
			throw new RuntimeException( 'Could not delete expired decision records.' );
		}

		return true === $deleted ? 0 : $deleted;
	}
}
