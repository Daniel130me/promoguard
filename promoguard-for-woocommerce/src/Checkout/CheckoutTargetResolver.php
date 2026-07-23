<?php
/**
 * Checkout campaign target resolver.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Checkout;

use PromoGuard\Campaign\CampaignStore;
use PromoGuard\Promotion\CampaignPromotionStore;
use PromoGuard\Promotion\WooCommerceCouponSource;

/** Resolves assigned coupons through indexed lookups with request-local caching. */
final class CheckoutTargetResolver {
	/**
	 * Resolved targets, including null for unrelated coupons.
	 *
	 * @var array<int,CheckoutTarget|null>
	 */
	private array $cache = array();

	/**
	 * Configure checkout lookup dependencies.
	 *
	 * @param CampaignPromotionStore $assignments Assignment persistence.
	 * @param CampaignStore          $campaigns   Campaign persistence.
	 */
	public function __construct(
		private readonly CampaignPromotionStore $assignments,
		private readonly CampaignStore $campaigns
	) {}

	/**
	 * Resolve one native coupon ID to its campaign.
	 *
	 * Unassigned coupons return null and remain entirely under WooCommerce.
	 *
	 * @param int $coupon_id Native WooCommerce coupon ID.
	 */
	public function resolve( int $coupon_id ): ?CheckoutTarget {
		if ( $coupon_id < 1 ) {
			return null;
		}

		if ( array_key_exists( $coupon_id, $this->cache ) ) {
			return $this->cache[ $coupon_id ];
		}

		$assignment = $this->assignments->find_by_source(
			WooCommerceCouponSource::SOURCE,
			WooCommerceCouponSource::SOURCE_TYPE,
			(string) $coupon_id
		);

		if ( null === $assignment ) {
			$this->cache[ $coupon_id ] = null;
			return null;
		}

		$campaign = $this->campaigns->find( $assignment->campaign_id );
		$target   = null === $campaign ? null : new CheckoutTarget( $assignment, $campaign );

		$this->cache[ $coupon_id ] = $target;
		return $target;
	}
}
