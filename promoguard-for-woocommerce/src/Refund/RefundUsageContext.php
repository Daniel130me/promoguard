<?php
/**
 * Consumed usage refund context.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Refund;

use InvalidArgumentException;
use PromoGuard\Campaign\CampaignConfiguration;

/** Carries the immutable full-refund policy for one consumed usage. */
final class RefundUsageContext {
	/**
	 * Create a validated usage refund context.
	 *
	 * @param int    $campaign_id    Campaign ID.
	 * @param string $refund_behavior Persisted full-refund policy.
	 * @throws InvalidArgumentException When context facts are invalid.
	 */
	public function __construct(
		public readonly int $campaign_id,
		public readonly string $refund_behavior
	) {
		if (
			$campaign_id < 1
			|| ! CampaignConfiguration::supports_refund_behavior( $refund_behavior )
		) {
			throw new InvalidArgumentException( 'Refund usage context is invalid.' );
		}
	}
}
