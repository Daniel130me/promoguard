<?php
/**
 * Opt-in PromoGuard data removal.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Activation;

use PromoGuard\Support\Capabilities;
use PromoGuard\Support\Options;
use PromoGuard\Support\TableNames;

/** Removes only PromoGuard-owned data after explicit consent. */
final class Uninstaller {
	/** Remove plugin data when the uninstall setting is explicitly enabled. */
	public static function uninstall(): void {
		if ( 1 !== (int) get_option( Options::DELETE_DATA_ON_UNINSTALL, 0 ) ) {
			return;
		}

		global $wpdb;

		// Child-like records are removed first even though the schema has no FKs.
		foreach ( array_reverse( TableNames::from_wordpress()->all() ) as $table_name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Controlled plugin table identifier during opted-in uninstall.
			$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
		}

		Capabilities::remove();

		foreach ( Options::names() as $option_name ) {
			delete_option( $option_name );
		}
	}
}
