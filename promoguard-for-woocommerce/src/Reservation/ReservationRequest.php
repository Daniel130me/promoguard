<?php
/**
 * Reservation request.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

use DateTimeImmutable;
use InvalidArgumentException;

/** Immutable facts required for one atomic campaign usage reservation. */
final class ReservationRequest {
	private const MAX_COUPON_CODE_LENGTH = 255;

	/**
	 * Create a validated reservation request.
	 *
	 * @param string            $uuid               Usage UUID.
	 * @param int               $campaign_id        Campaign ID.
	 * @param int|null          $promotion_id       Assignment ID.
	 * @param int               $customer_id        Internal customer ID.
	 * @param int               $order_id           WooCommerce order ID.
	 * @param int|null          $coupon_id          Native coupon ID.
	 * @param string|null       $coupon_code        Coupon code snapshot.
	 * @param string            $currency           ISO-style currency code.
	 * @param string            $reservation_key    Idempotency key.
	 * @param int               $maximum_uses       Customer campaign limit.
	 * @param DateTimeImmutable $reserved_at_gmt    Reservation creation time.
	 * @param DateTimeImmutable $reserved_until_gmt Reservation expiry time.
	 * @throws InvalidArgumentException When reservation facts are invalid.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly int $campaign_id,
		public readonly ?int $promotion_id,
		public readonly int $customer_id,
		public readonly int $order_id,
		public readonly ?int $coupon_id,
		public readonly ?string $coupon_code,
		public readonly string $currency,
		public readonly string $reservation_key,
		public readonly int $maximum_uses,
		public readonly DateTimeImmutable $reserved_at_gmt,
		public readonly DateTimeImmutable $reserved_until_gmt
	) {
		$this->validate();
	}

	/**
	 * Validate persistence and lifecycle invariants.
	 *
	 * @throws InvalidArgumentException When reservation facts are invalid.
	 */
	private function validate(): void {
		foreach (
			array(
				$this->campaign_id,
				$this->promotion_id,
				$this->customer_id,
				$this->order_id,
				$this->coupon_id,
				$this->maximum_uses,
			) as $identifier
		) {
			if ( null !== $identifier && $identifier < 1 ) {
				throw new InvalidArgumentException( 'Reservation identifiers and limits must be positive.' );
			}
		}

		if (
			1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $this->uuid )
			|| 1 !== preg_match( '/^[0-9a-f]{64}$/', $this->reservation_key )
			|| 1 !== preg_match( '/^[A-Z]{3}$/', $this->currency )
			|| ( null !== $this->coupon_code && strlen( $this->coupon_code ) > self::MAX_COUPON_CODE_LENGTH )
		) {
			throw new InvalidArgumentException( 'Reservation text fields are invalid.' );
		}

		if (
			0 !== $this->reserved_at_gmt->getOffset()
			|| 0 !== $this->reserved_until_gmt->getOffset()
			|| $this->reserved_until_gmt <= $this->reserved_at_gmt
		) {
			throw new InvalidArgumentException( 'Reservation timestamps must define a future GMT expiry.' );
		}
	}
}
