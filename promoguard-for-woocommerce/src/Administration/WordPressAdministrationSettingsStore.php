<?php
/**
 * WordPress administration settings persistence.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Administration;

use PromoGuard\Credit\StoreCreditSettings;
use PromoGuard\Support\Options;
use PromoGuard\SignupBonus\SignupBonusRules;
use RuntimeException;

/** Reads health metadata and writes explicit uninstall-cleanup consent. */
final class WordPressAdministrationSettingsStore implements AdministrationSettingsStore {
	/** {@inheritDoc} */
	public function current(): array {
		$settings     = get_option( Options::SETTINGS, Options::default_settings() );
		$settings     = is_array( $settings ) ? $settings : Options::default_settings();
		$health       = $settings['storage_engine_supported'] ?? null;
		$checked      = $settings['storage_engine_checked_at_gmt'] ?? null;
		$signup_bonus = get_option( Options::SIGNUP_BONUS_RULES, SignupBonusRules::defaults()->to_array() );
		$signup_bonus = SignupBonusRules::from_array( is_array( $signup_bonus ) ? $signup_bonus : array() );
		$store_credit = get_option( Options::STORE_CREDIT_SETTINGS, StoreCreditSettings::defaults()->to_array() );
		$store_credit = StoreCreditSettings::from_array( is_array( $store_credit ) ? $store_credit : array() );

		return array(
			'delete_data_on_uninstall'      => true === get_option( Options::DELETE_DATA_ON_UNINSTALL, false ),
			'storage_engine_supported'      => is_bool( $health ) ? $health : null,
			'storage_engine_checked_at_gmt' => is_string( $checked ) && '' !== $checked ? $checked : null,
			'signup_bonus'                  => $signup_bonus->to_array(),
			'store_credit'                  => $store_credit->to_array(),
		);
	}

	/**
	 * Persist explicit uninstall consent and standalone signup campaign rules.
	 *
	 * @param bool                $delete_data_on_uninstall Whether uninstall may delete plugin-owned data.
	 * @param SignupBonusRules    $signup_bonus             Validated signup campaign rules.
	 * @throws RuntimeException When an option cannot be saved.
	 * @param StoreCreditSettings $store_credit          Validated redemption settings.
	 */
	public function save( bool $delete_data_on_uninstall, SignupBonusRules $signup_bonus, StoreCreditSettings $store_credit ): void {
		$updated = update_option(
			Options::DELETE_DATA_ON_UNINSTALL,
			$delete_data_on_uninstall,
			false
		);

		$current = true === get_option( Options::DELETE_DATA_ON_UNINSTALL, false );
		if ( ! $updated && $current !== $delete_data_on_uninstall ) {
			throw new RuntimeException( 'Administration settings could not be saved.' );
		}

		$rules         = $signup_bonus->to_array();
		$updated       = update_option( Options::SIGNUP_BONUS_RULES, $rules, false );
		$current_rules = get_option( Options::SIGNUP_BONUS_RULES, array() );
		if ( ! $updated && $current_rules !== $rules ) {
			throw new RuntimeException( 'Signup-bonus settings could not be saved.' );
		}

		$redemption         = $store_credit->to_array();
		$updated            = update_option( Options::STORE_CREDIT_SETTINGS, $redemption, false );
		$current_redemption = get_option( Options::STORE_CREDIT_SETTINGS, array() );
		if ( ! $updated && $current_redemption !== $redemption ) {
			throw new RuntimeException( 'Store-credit settings could not be saved.' );
		}
	}
}
