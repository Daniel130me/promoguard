<?php
/**
 * Eligibility decision persistence contract.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Decision;

/** Persists privacy-safe eligibility denials. */
interface DecisionStore {
	/**
	 * Persist one denial record.
	 *
	 * @param DenialRecord $denial Validated denial.
	 */
	public function record( DenialRecord $denial ): void;
}
