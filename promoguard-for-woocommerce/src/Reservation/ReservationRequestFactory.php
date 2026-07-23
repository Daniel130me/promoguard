<?php
/**
 * Checkout reservation request factory.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use PromoGuard\Checkout\CheckoutEvaluation;

/** Converts a final allowed checkout evaluation into reservation facts. */
final class ReservationRequestFactory {
	private const DEFAULT_DURATION = 'PT30M';

	/**
	 * Reservation lifetime applied to new pending usages.
	 *
	 * @var DateInterval
	 */
	private DateInterval $duration;

	/**
	 * Configure the reservation lifetime.
	 *
	 * @param DateInterval|null $duration Optional lifetime override.
	 */
	public function __construct( ?DateInterval $duration = null ) {
		$this->duration = $duration ?? new DateInterval( self::DEFAULT_DURATION );
	}

	/**
	 * Build one deterministic order/campaign reservation request.
	 *
	 * @param CheckoutEvaluation $evaluation Final checkout evaluation.
	 * @param int                $order_id    WooCommerce order ID.
	 * @param string             $currency    Order currency.
	 * @param string             $uuid        New usage UUID.
	 * @param DateTimeImmutable  $now_gmt     Reservation time.
	 * @throws InvalidArgumentException When evaluation is not finally reservable.
	 */
	public function create(
		CheckoutEvaluation $evaluation,
		int $order_id,
		string $currency,
		string $uuid,
		DateTimeImmutable $now_gmt
	): ReservationRequest {
		if (
			! $evaluation->decision->allowed
			|| $evaluation->decision->provisional
			|| null === $evaluation->customer_id
		) {
			throw new InvalidArgumentException( 'Only a final allowed customer decision can be reserved.' );
		}

		$assignment = $evaluation->target->assignment;
		$usage      = $evaluation->target->campaign->configuration->usage_rules();
		$coupon_id  = ctype_digit( $assignment->external_id ) ? (int) $assignment->external_id : null;

		return new ReservationRequest(
			uuid: $uuid,
			campaign_id: $assignment->campaign_id,
			promotion_id: $assignment->id,
			customer_id: $evaluation->customer_id,
			order_id: $order_id,
			coupon_id: $coupon_id,
			coupon_code: $assignment->external_code,
			currency: strtoupper( $currency ),
			reservation_key: hash( 'sha256', $order_id . ':' . $assignment->campaign_id ),
			maximum_uses: (int) $usage['maximum_uses'],
			reserved_at_gmt: $now_gmt,
			reserved_until_gmt: $now_gmt->add( $this->duration )
		);
	}
}
