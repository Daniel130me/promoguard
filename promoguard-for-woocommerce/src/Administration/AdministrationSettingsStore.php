<?php
/**
 * Administration settings persistence contract.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Administration;

/** Provides the small set of implemented, administrator-only settings. */
interface AdministrationSettingsStore {
	/**
	 * Return implemented settings and read-only health state.
	 *
	 * @return array{
	 *     delete_data_on_uninstall:bool,
	 *     storage_engine_supported:bool|null,
	 *     storage_engine_checked_at_gmt:string|null
	 * }
	 */
	public function current(): array;

	/**
	 * Persist explicit uninstall-cleanup consent.
	 *
	 * @param bool $delete_data_on_uninstall Whether uninstall may delete plugin-owned data.
	 */
	public function save( bool $delete_data_on_uninstall ): void;
}
