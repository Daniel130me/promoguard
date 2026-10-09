<?php
/**
 * Static signup-bonus rule store for tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Support;

use PromoGuard\SignupBonus\SignupBonusRules;
use PromoGuard\SignupBonus\SignupBonusRuleStore;

/** Returns one configured rule set without WordPress options. */
final class StaticSignupBonusRuleStore implements SignupBonusRuleStore {
	/**
	 * Configure the fixed rules.
	 *
	 * @param SignupBonusRules $rules Rules returned by the store.
	 */
	public function __construct( private readonly SignupBonusRules $rules ) {}

	/** {@inheritDoc} */
	public function current(): SignupBonusRules {
		return $this->rules;
	}
}
