<?php
/**
 * Signup-bonus rule persistence contract.
 *
 * @package PromoGuard
 */

namespace PromoGuard\SignupBonus;

/** Loads the standalone signup campaign rules used by award orchestration. */
interface SignupBonusRuleStore {
	/** Return current validated rules. */
	public function current(): SignupBonusRules;
}
