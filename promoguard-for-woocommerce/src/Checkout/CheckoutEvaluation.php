<?php
/**
 * Checkout eligibility result.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Checkout;

use PromoGuard\Eligibility\EligibilityDecision;

/** Couples the protected coupon target with its policy decision. */
final class CheckoutEvaluation {
	/**
	 * Create a checkout evaluation.
	 *
	 * @param CheckoutTarget      $target   Resolved campaign target.
	 * @param EligibilityDecision $decision Shared policy decision.
	 */
	public function __construct(
		public readonly CheckoutTarget $target,
		public readonly EligibilityDecision $decision
	) {}
}
