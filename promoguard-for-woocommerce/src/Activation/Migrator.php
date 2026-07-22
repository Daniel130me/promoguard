<?php
/**
 * Versioned PromoGuard database migrations.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Activation;

use PromoGuard\Support\Options;
use PromoGuard\Support\TableNames;

/**
 * Applies idempotent dbDelta schema migrations.
 */
final class Migrator {
	public const CURRENT_VERSION = '1.0.0';

	/**
	 * Apply the current schema only when the stored version is older.
	 *
	 * @return bool Whether a migration was applied.
	 */
	public function migrate(): bool {
		$installed_version = (string) get_option( Options::DB_VERSION, '0' );

		if ( version_compare( $installed_version, self::CURRENT_VERSION, '>=' ) ) {
			return false;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$schema = new Schema();
		$tables = TableNames::from_wordpress();

		foreach ( $schema->statements( $tables, $wpdb->get_charset_collate() ) as $statement ) {
			dbDelta( $statement );
		}

		update_option( Options::DB_VERSION, self::CURRENT_VERSION, false );

		return true;
	}
}
