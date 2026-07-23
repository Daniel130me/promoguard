<?php
/**
 * PromoGuard option names and initialization.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Support;

/**
 * Owns the plugin's bounded set of WordPress options.
 */
final class Options {
	public const VERSION                  = 'promoguard_version';
	public const DB_VERSION               = 'promoguard_db_version';
	public const HASH_KEY                 = 'promoguard_hash_key';
	public const SETTINGS                 = 'promoguard_settings';
	public const INSTALLATION_ID          = 'promoguard_installation_id';
	public const ONBOARDING_COMPLETE      = 'promoguard_onboarding_complete';
	public const DELETE_DATA_ON_UNINSTALL = 'promoguard_delete_data_on_uninstall';
	public const HISTORICAL_INDEXING_JOB  = 'promoguard_historical_indexing_job';

	/**
	 * Return every option owned by PromoGuard.
	 *
	 * @return string[]
	 */
	public static function names(): array {
		return array(
			self::VERSION,
			self::DB_VERSION,
			self::HASH_KEY,
			self::SETTINGS,
			self::INSTALLATION_ID,
			self::ONBOARDING_COMPLETE,
			self::DELETE_DATA_ON_UNINSTALL,
			self::HISTORICAL_INDEXING_JOB,
		);
	}

	/**
	 * Initialize options without replacing persistent identifiers or consent.
	 */
	public static function initialize(): void {
		update_option( self::VERSION, PROMOGUARD_VERSION, false );
		add_option( self::DB_VERSION, '0', '', false );
		add_option( self::SETTINGS, self::default_settings(), '', false );
		add_option( self::ONBOARDING_COMPLETE, false, '', false );
		add_option( self::DELETE_DATA_ON_UNINSTALL, false, '', false );
		add_option( self::HISTORICAL_INDEXING_JOB, array(), '', false );

		if ( false === get_option( self::HASH_KEY, false ) ) {
			add_option( self::HASH_KEY, bin2hex( random_bytes( 32 ) ), '', false );
		}

		if ( false === get_option( self::INSTALLATION_ID, false ) ) {
			add_option( self::INSTALLATION_ID, wp_generate_uuid4(), '', false );
		}
	}

	/**
	 * Record whether transactional checkout storage is available.
	 *
	 * @param bool $supported Whether the required tables use InnoDB.
	 */
	public static function record_storage_status( bool $supported ): void {
		$settings = get_option( self::SETTINGS, self::default_settings() );
		$settings = is_array( $settings ) ? $settings : self::default_settings();

		$settings['storage_engine_supported']      = $supported;
		$settings['storage_engine_checked_at_gmt'] = current_time( 'mysql', true );

		update_option( self::SETTINGS, $settings, false );
	}

	/**
	 * Determine whether the most recent storage verification passed.
	 */
	public static function storage_is_supported(): bool {
		$settings = get_option( self::SETTINGS, self::default_settings() );

		return is_array( $settings ) && true === ( $settings['storage_engine_supported'] ?? null );
	}

	/**
	 * Default internal health metadata.
	 *
	 * @return array{storage_engine_supported: null, storage_engine_checked_at_gmt: null}
	 */
	public static function default_settings(): array {
		return array(
			'storage_engine_supported'      => null,
			'storage_engine_checked_at_gmt' => null,
		);
	}
}
