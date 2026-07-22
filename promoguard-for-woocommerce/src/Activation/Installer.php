<?php
/**
 * PromoGuard installation coordinator.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Activation;

use PromoGuard\Support\Capabilities;
use PromoGuard\Support\Options;

/**
 * Coordinates idempotent options, capabilities, migrations, and health checks.
 */
final class Installer {
	/** Run a complete idempotent install or upgrade. */
	public static function install(): void {
		Options::initialize();
		Capabilities::install();

		( new Migrator() )->migrate();

		Options::record_storage_status( ( new StorageEngineVerifier() )->is_supported() );
	}

	/** Run installation only when the stored plugin or schema version is stale. */
	public static function maybe_upgrade(): void {
		$plugin_version = (string) get_option( Options::VERSION, '0' );
		$db_version     = (string) get_option( Options::DB_VERSION, '0' );

		if (
			version_compare( $plugin_version, PROMOGUARD_VERSION, '>=' )
			&& version_compare( $db_version, Migrator::CURRENT_VERSION, '>=' )
		) {
			return;
		}

		self::install();
	}
}
