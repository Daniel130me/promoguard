<?php
/**
 * WordPress administration settings persistence.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Administration;

use PromoGuard\Support\Options;
use RuntimeException;

/** Reads health metadata and writes explicit uninstall-cleanup consent. */
final class WordPressAdministrationSettingsStore implements AdministrationSettingsStore {
	/** {@inheritDoc} */
	public function current(): array {
		$settings = get_option( Options::SETTINGS, Options::default_settings() );
		$settings = is_array( $settings ) ? $settings : Options::default_settings();
		$health   = $settings['storage_engine_supported'] ?? null;
		$checked  = $settings['storage_engine_checked_at_gmt'] ?? null;

		return array(
			'delete_data_on_uninstall'      => true === get_option( Options::DELETE_DATA_ON_UNINSTALL, false ),
			'storage_engine_supported'      => is_bool( $health ) ? $health : null,
			'storage_engine_checked_at_gmt' => is_string( $checked ) && '' !== $checked ? $checked : null,
		);
	}

	/**
	 * Persist explicit uninstall-cleanup consent.
	 *
	 * @param bool $delete_data_on_uninstall Whether uninstall may delete plugin-owned data.
	 * @throws RuntimeException When the option cannot be saved.
	 */
	public function save( bool $delete_data_on_uninstall ): void {
		$updated = update_option(
			Options::DELETE_DATA_ON_UNINSTALL,
			$delete_data_on_uninstall,
			false
		);

		$current = true === get_option( Options::DELETE_DATA_ON_UNINSTALL, false );
		if ( ! $updated && $current !== $delete_data_on_uninstall ) {
			throw new RuntimeException( 'Administration settings could not be saved.' );
		}
	}
}
