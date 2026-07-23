<?php
/**
 * Historical WooCommerce order snapshot.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Indexing;

use DateTimeImmutable;
use InvalidArgumentException;

/** Carries only order facts required for safe historical usage reconstruction. */
final class HistoricalOrder {
	/**
	 * Create a validated historical order snapshot.
	 *
	 * @param int                $id             WooCommerce order ID.
	 * @param int|null           $wp_user_id     Authoritative user ID when present.
	 * @param string|null        $billing_email  Optional billing email, used transiently.
	 * @param string             $status         WooCommerce status without the wc- prefix.
	 * @param string             $currency       Three-letter order currency.
	 * @param DateTimeImmutable  $occurred_at_gmt Order creation time.
	 * @param HistoricalCoupon[] $coupons        Coupon item snapshots.
	 * @param bool               $is_full_refund Whether cumulative refunds cover the order.
	 * @throws InvalidArgumentException When order facts are malformed.
	 */
	public function __construct(
		public readonly int $id,
		public readonly ?int $wp_user_id,
		public readonly ?string $billing_email,
		public readonly string $status,
		public readonly string $currency,
		public readonly DateTimeImmutable $occurred_at_gmt,
		public readonly array $coupons,
		public readonly bool $is_full_refund
	) {
		if (
			$id < 1
			|| ( null !== $wp_user_id && $wp_user_id < 1 )
			|| '' === trim( $status )
			|| 1 !== preg_match( '/^[A-Z]{3}$/', $currency )
			|| 0 !== $occurred_at_gmt->getOffset()
		) {
			throw new InvalidArgumentException( 'Historical order facts are invalid.' );
		}
	}
}
