<?php
/**
 * Administration settings persistence contract.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Administration;

use PromoGuard\Credit\StoreCreditSettings;
use PromoGuard\SignupBonus\SignupBonusRules;

/** Provides the small set of implemented, administrator-only settings. */
interface AdministrationSettingsStore {
	/**
	 * Return implemented settings and read-only health state.
	 *
	 * @return array{
	 *     delete_data_on_uninstall:bool,
	 *     storage_engine_supported:bool|null,
	 *     storage_engine_checked_at_gmt:string|null,
	 *     store_credit:array{redemption_enabled:bool},
	 *     signup_bonus:array{customer_event:string,customer_amount:string,vendor_event:string,vendor_amount:string}
	 * }
	 */
	public function current(): array;

	/**
	 * Persist explicit uninstall consent and standalone signup campaign rules.
	 *
	 * @param bool                $delete_data_on_uninstall Whether uninstall may delete plugin-owned data.
	 * @param SignupBonusRules    $signup_bonus             Validated signup campaign rules.
	 * @param StoreCreditSettings $store_credit          Validated redemption settings.
	 */
	public function save( bool $delete_data_on_uninstall, SignupBonusRules $signup_bonus, StoreCreditSettings $store_credit ): void;
}
