<?php
/**
 * Eligibility evaluation context.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Eligibility;

use DateTimeImmutable;
use InvalidArgumentException;
use PromoGuard\Campaign\Campaign;
use PromoGuard\Customer\IdentityResolution;

/** Holds request facts shared by every eligibility entry point. */
final class EligibilityContext {
	/**
	 * Create a validated policy context.
	 *
	 * @param Campaign           $campaign                      Campaign being evaluated.
	 * @param IdentityResolution $identity                      Resolved customer identity.
	 * @param bool               $is_authenticated              Whether the request has a logged-in user.
	 * @param int                $applied_campaign_coupon_count Number of campaign coupons already applied.
	 * @param DateTimeImmutable  $now_gmt                       Current GMT time.
	 * @param bool               $allow_provisional_identity    Whether this early context may defer identity.
	 * @throws InvalidArgumentException When counts or time are invalid.
	 */
	public function __construct(
		public readonly Campaign $campaign,
		public readonly IdentityResolution $identity,
		public readonly bool $is_authenticated,
		public readonly int $applied_campaign_coupon_count,
		public readonly DateTimeImmutable $now_gmt,
		public readonly bool $allow_provisional_identity = false
	) {
		if ( $applied_campaign_coupon_count < 0 ) {
			throw new InvalidArgumentException( 'Applied campaign coupon count cannot be negative.' );
		}

		if ( 0 !== $now_gmt->getOffset() ) {
			throw new InvalidArgumentException( 'Eligibility time must use GMT.' );
		}
	}
}
