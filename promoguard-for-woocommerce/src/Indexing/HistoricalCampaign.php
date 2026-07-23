<?php
/**
 * Historical campaign assignment snapshot.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Indexing;

use InvalidArgumentException;
use PromoGuard\Campaign\CampaignConfiguration;

/** Carries the current assignment and lifecycle policy for one coupon code. */
final class HistoricalCampaign {
	/**
	 * Create a validated historical campaign snapshot.
	 *
	 * @param int      $campaign_id      PromoGuard campaign ID.
	 * @param int      $promotion_id     PromoGuard assignment ID.
	 * @param int|null $coupon_id        Native coupon ID when still available.
	 * @param string[] $counted_statuses Statuses that represent consumption.
	 * @param string   $refund_behavior  Snapshotted full-refund behavior.
	 * @throws InvalidArgumentException When campaign facts are invalid.
	 */
	public function __construct(
		public readonly int $campaign_id,
		public readonly int $promotion_id,
		public readonly ?int $coupon_id,
		public readonly array $counted_statuses,
		public readonly string $refund_behavior
	) {
		if (
			$campaign_id < 1
			|| $promotion_id < 1
			|| ( null !== $coupon_id && $coupon_id < 1 )
			|| array() === $counted_statuses
			|| ! CampaignConfiguration::supports_refund_behavior( $refund_behavior )
		) {
			throw new InvalidArgumentException( 'Historical campaign facts are invalid.' );
		}

		foreach ( $counted_statuses as $status ) {
			if ( '' === trim( $status ) ) {
				throw new InvalidArgumentException( 'Historical campaign statuses are invalid.' );
			}
		}
	}

	/**
	 * Whether the historical order status consumes this campaign.
	 *
	 * @param string $status WooCommerce order status.
	 */
	public function counts_status( string $status ): bool {
		return in_array( $status, $this->counted_statuses, true );
	}
}
