<?php
/**
 * WordPress signup-bonus rule persistence.
 *
 * @package PromoGuard
 */

namespace PromoGuard\SignupBonus;

use PromoGuard\Support\Options;

/** Loads signup campaign rules from their dedicated non-autoloaded option. */
final class WordPressSignupBonusRuleStore implements SignupBonusRuleStore {
	/** Return current validated rules, falling back to safe defaults. */
	public function current(): SignupBonusRules {
		$rules = get_option( Options::SIGNUP_BONUS_RULES, SignupBonusRules::defaults()->to_array() );

		return SignupBonusRules::from_array( is_array( $rules ) ? $rules : array() );
	}
}
