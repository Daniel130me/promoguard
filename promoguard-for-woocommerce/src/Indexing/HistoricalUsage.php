<?php
/**
 * Historical usage import request.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Indexing;

use DateTimeImmutable;
use InvalidArgumentException;
use PromoGuard\Campaign\CampaignConfiguration;

/** Immutable consumed-usage facts ready for idempotent persistence. */
final class HistoricalUsage {
	/**
	 * Create a validated consumed-usage request.
	 *
	 * @param int               $campaign_id     PromoGuard campaign ID.
	 * @param int               $promotion_id    PromoGuard assignment ID.
	 * @param int               $customer_id     Internal customer ID.
	 * @param int               $order_id        WooCommerce order ID.
	 * @param int               $order_item_id   WooCommerce coupon item ID.
	 * @param int|null          $coupon_id       Native coupon ID when available.
	 * @param string            $coupon_code     Coupon code snapshot.
	 * @param string            $order_status    Consuming order status.
	 * @param string            $discount_amount Decimal discount snapshot.
	 * @param string            $currency        Three-letter order currency.
	 * @param string            $refund_behavior Immutable full-refund policy.
	 * @param DateTimeImmutable $consumed_at_gmt Historical consumption time.
	 * @throws InvalidArgumentException When usage facts are malformed.
	 */
	public function __construct(
		public readonly int $campaign_id,
		public readonly int $promotion_id,
		public readonly int $customer_id,
		public readonly int $order_id,
		public readonly int $order_item_id,
		public readonly ?int $coupon_id,
		public readonly string $coupon_code,
		public readonly string $order_status,
		public readonly string $discount_amount,
		public readonly string $currency,
		public readonly string $refund_behavior,
		public readonly DateTimeImmutable $consumed_at_gmt
	) {
		if (
			$campaign_id < 1
			|| $promotion_id < 1
			|| $customer_id < 1
			|| $order_id < 1
			|| $order_item_id < 1
			|| ( null !== $coupon_id && $coupon_id < 1 )
			|| '' === trim( $coupon_code )
			|| strlen( $coupon_code ) > 255
			|| '' === trim( $order_status )
			|| ! is_numeric( $discount_amount )
			|| (float) $discount_amount < 0
			|| 1 !== preg_match( '/^[A-Z]{3}$/', $currency )
			|| ! CampaignConfiguration::supports_refund_behavior( $refund_behavior )
			|| 0 !== $consumed_at_gmt->getOffset()
		) {
			throw new InvalidArgumentException( 'Historical usage facts are invalid.' );
		}
	}
}
