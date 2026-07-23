<?php
/**
 * Cumulative refund event.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Refund;

use DateTimeImmutable;
use InvalidArgumentException;

/** Immutable WooCommerce refund facts used by the application service. */
final class RefundEvent {
	/**
	 * Create a validated cumulative refund event.
	 *
	 * @param int               $order_id          WooCommerce order ID.
	 * @param bool              $is_full_refund    Whether cumulative refunds cover the order.
	 * @param DateTimeImmutable $occurred_at_gmt   Refund observation time.
	 * @throws InvalidArgumentException When event facts are invalid.
	 */
	public function __construct(
		public readonly int $order_id,
		public readonly bool $is_full_refund,
		public readonly DateTimeImmutable $occurred_at_gmt
	) {
		if ( $order_id < 1 ) {
			throw new InvalidArgumentException( 'Refund order ID must be positive.' );
		}

		if ( 0 !== $occurred_at_gmt->getOffset() ) {
			throw new InvalidArgumentException( 'Refund event time must use GMT.' );
		}
	}
}
