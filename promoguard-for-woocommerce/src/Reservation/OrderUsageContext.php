<?php
/**
 * Pending order usage lifecycle context.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

use InvalidArgumentException;

/** Carries persisted campaign facts needed after coupon assignment changes. */
final class OrderUsageContext {
	/**
	 * Create one validated pending usage context.
	 *
	 * @param int                 $campaign_id Campaign ID.
	 * @param string|null         $coupon_code Coupon code snapshot.
	 * @param array<string,mixed> $usage_rules Validated campaign usage rules.
	 * @throws InvalidArgumentException When persisted context is malformed.
	 */
	public function __construct(
		public readonly int $campaign_id,
		public readonly ?string $coupon_code,
		public readonly array $usage_rules
	) {
		if ( $campaign_id < 1 || ( null !== $coupon_code && strlen( $coupon_code ) > 255 ) ) {
			throw new InvalidArgumentException( 'Order usage context identifiers are invalid.' );
		}
	}
}
