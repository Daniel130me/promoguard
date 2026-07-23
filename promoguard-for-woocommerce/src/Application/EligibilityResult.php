<?php
/**
 * Eligibility application result.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Application;

use PromoGuard\Customer\IdentityResolution;
use PromoGuard\Eligibility\EligibilityDecision;

/** Couples the policy decision with its authoritative identity resolution. */
final class EligibilityResult {
	/**
	 * Create an application-level eligibility result.
	 *
	 * @param IdentityResolution  $identity Resolved customer identity.
	 * @param EligibilityDecision $decision Shared policy decision.
	 */
	public function __construct(
		public readonly IdentityResolution $identity,
		public readonly EligibilityDecision $decision
	) {}

	/** Return the internal customer ID when identity resolution succeeded. */
	public function customer_id(): ?int {
		return $this->identity->customer?->id;
	}
}
