<?php
/**
 * Usage lifecycle transition.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

use DateTimeImmutable;
use InvalidArgumentException;
use PromoGuard\Campaign\CampaignConfiguration;

/** Immutable order facts required for an idempotent usage transition. */
final class UsageTransition {
	private const MAX_STATUS_LENGTH = 20;

	/**
	 * Create a validated usage transition.
	 *
	 * @param int               $campaign_id    Campaign ID.
	 * @param int               $order_id       WooCommerce order ID.
	 * @param string            $order_status   New WooCommerce order status.
	 * @param string            $discount_amount Campaign discount snapshot.
	 * @param string            $refund_behavior Full-refund policy snapshot.
	 * @param DateTimeImmutable $occurred_at_gmt Transition time.
	 * @throws InvalidArgumentException When transition facts are invalid.
	 */
	public function __construct(
		public readonly int $campaign_id,
		public readonly int $order_id,
		public readonly string $order_status,
		public readonly string $discount_amount,
		public readonly string $refund_behavior,
		public readonly DateTimeImmutable $occurred_at_gmt
	) {
		if ( $campaign_id < 1 || $order_id < 1 ) {
			throw new InvalidArgumentException( 'Usage transition identifiers must be positive.' );
		}

		if (
			1 !== preg_match( '/^[a-z0-9-]+$/', $order_status )
			|| strlen( $order_status ) > self::MAX_STATUS_LENGTH
			|| 1 !== preg_match( '/^\d+(?:\.\d{1,8})?$/', $discount_amount )
		) {
			throw new InvalidArgumentException( 'Usage transition status or amount is invalid.' );
		}

		if (
			! in_array(
				$refund_behavior,
				array(
					CampaignConfiguration::REFUND_RESTORE,
					CampaignConfiguration::REFUND_KEEP_CONSUMED,
					CampaignConfiguration::REFUND_MANUAL_REVIEW,
				),
				true
			)
		) {
			throw new InvalidArgumentException( 'Usage transition refund behavior is invalid.' );
		}

		if ( 0 !== $occurred_at_gmt->getOffset() ) {
			throw new InvalidArgumentException( 'Usage transition time must use GMT.' );
		}
	}
}
