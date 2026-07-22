<?php
/**
 * Transactional storage verification.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Activation;

use PromoGuard\Support\TableNames;

/**
 * Verifies the two lock-sensitive tables in one bounded metadata query.
 */
final class StorageEngineVerifier {
	/**
	 * Check that all lock-sensitive tables exist and use InnoDB.
	 */
	public function is_supported(): bool {
		global $wpdb;

		$required_tables = TableNames::from_wordpress()->transactional();
		$placeholders    = implode( ', ', array_fill( 0, count( $required_tables ), '%s' ) );
		$parameters      = array_merge( array( $wpdb->dbname ), $required_tables );

		// Table identifiers come only from TableNames; values remain prepared.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamically sized placeholder list contains only literal %s tokens.
		$query = $wpdb->prepare(
			"SELECT TABLE_NAME, ENGINE
			FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = %s
			AND TABLE_NAME IN ({$placeholders})",
			$parameters
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared installation-time metadata check, bounded to two tables.
		$rows = $wpdb->get_results( $query, ARRAY_A );

		if ( count( $rows ) !== count( $required_tables ) ) {
			return false;
		}

		foreach ( $rows as $row ) {
			if ( 'InnoDB' !== ( $row['ENGINE'] ?? null ) ) {
				return false;
			}
		}

		return true;
	}
}
