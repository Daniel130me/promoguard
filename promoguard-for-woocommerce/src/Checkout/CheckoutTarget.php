<?php
/**
 * Checkout campaign target.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Checkout;

use InvalidArgumentException;
use PromoGuard\Campaign\Campaign;
use PromoGuard\Promotion\CampaignPromotion;

/** Pairs one active coupon assignment with its persisted campaign. */
final class CheckoutTarget {
	/**
	 * Create a consistent checkout target.
	 *
	 * @param CampaignPromotion $assignment Coupon assignment.
	 * @param Campaign          $campaign   Owning persisted campaign.
	 * @throws InvalidArgumentException When the records do not share a campaign ID.
	 */
	public function __construct(
		public readonly CampaignPromotion $assignment,
		public readonly Campaign $campaign
	) {
		if ( null === $campaign->id || $assignment->campaign_id !== $campaign->id ) {
			throw new InvalidArgumentException( 'Checkout assignment and campaign must match.' );
		}
	}
}
