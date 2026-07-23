<?php
/**
 * Campaign eligibility evaluator contract.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Application;

use DateTimeImmutable;
use PromoGuard\Campaign\Campaign;

/** Defines the application boundary used by checkout transports. */
interface EligibilityEvaluator {
	/**
	 * Resolve identity and evaluate one campaign.
	 *
	 * @param Campaign          $campaign                      Persisted campaign.
	 * @param int|null          $wp_user_id                    Authenticated WordPress user ID.
	 * @param string|null       $billing_email                 Raw billing email, never persisted.
	 * @param int               $applied_campaign_coupon_count Other campaign coupons already applied.
	 * @param DateTimeImmutable $now_gmt                       Current GMT time.
	 * @param bool              $allow_provisional_identity    Whether early validation may defer identity.
	 */
	public function evaluate(
		Campaign $campaign,
		?int $wp_user_id,
		?string $billing_email,
		int $applied_campaign_coupon_count,
		DateTimeImmutable $now_gmt,
		bool $allow_provisional_identity = false
	): EligibilityResult;
}
