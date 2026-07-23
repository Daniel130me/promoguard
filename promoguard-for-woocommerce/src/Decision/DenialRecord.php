<?php
/**
 * Eligibility denial record.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Decision;

use DateTimeImmutable;
use InvalidArgumentException;

/** Immutable, privacy-safe denial snapshot for diagnostics and reporting. */
final class DenialRecord {
	private const MAX_REQUEST_ID_LENGTH = 64;
	private const MAX_CONTEXT_LENGTH    = 32;
	private const MAX_REASON_LENGTH     = 64;
	private const MAX_COUPON_LENGTH     = 255;

	/**
	 * Create a validated denial snapshot.
	 *
	 * @param string              $request_id       Request correlation ID.
	 * @param int                 $campaign_id      Campaign ID.
	 * @param int|null            $promotion_id     Assignment ID.
	 * @param int|null            $customer_id      Resolved internal customer ID.
	 * @param int|null            $order_id         Checkout order ID.
	 * @param int|null            $coupon_id        Native coupon ID.
	 * @param string|null         $coupon_code      Coupon code snapshot.
	 * @param string              $context          Validation context.
	 * @param string              $reason           Stable eligibility reason.
	 * @param string              $customer_message Safe customer message.
	 * @param string              $admin_explanation Administrative explanation.
	 * @param array<string,mixed> $metadata       Non-sensitive diagnostic metadata.
	 * @param DateTimeImmutable   $created_at_gmt   Creation time.
	 * @throws InvalidArgumentException When identifiers, lengths, or time are invalid.
	 */
	public function __construct(
		public readonly string $request_id,
		public readonly int $campaign_id,
		public readonly ?int $promotion_id,
		public readonly ?int $customer_id,
		public readonly ?int $order_id,
		public readonly ?int $coupon_id,
		public readonly ?string $coupon_code,
		public readonly string $context,
		public readonly string $reason,
		public readonly string $customer_message,
		public readonly string $admin_explanation,
		public readonly array $metadata,
		public readonly DateTimeImmutable $created_at_gmt
	) {
		$this->validate();
	}

	/**
	 * Validate persistence-safe denial values.
	 *
	 * @throws InvalidArgumentException When identifiers, lengths, or time are invalid.
	 */
	private function validate(): void {
		if (
			'' === trim( $this->request_id )
			|| strlen( $this->request_id ) > self::MAX_REQUEST_ID_LENGTH
			|| '' === trim( $this->context )
			|| strlen( $this->context ) > self::MAX_CONTEXT_LENGTH
			|| '' === trim( $this->reason )
			|| strlen( $this->reason ) > self::MAX_REASON_LENGTH
			|| ( null !== $this->coupon_code && strlen( $this->coupon_code ) > self::MAX_COUPON_LENGTH )
		) {
			throw new InvalidArgumentException( 'Decision text identifiers are invalid.' );
		}

		foreach (
			array(
				$this->campaign_id,
				$this->promotion_id,
				$this->customer_id,
				$this->order_id,
				$this->coupon_id,
			) as $identifier
		) {
			if ( null !== $identifier && $identifier < 1 ) {
				throw new InvalidArgumentException( 'Decision identifiers must be positive.' );
			}
		}

		if ( 0 !== $this->created_at_gmt->getOffset() ) {
			throw new InvalidArgumentException( 'Decision timestamps must use GMT.' );
		}
	}
}
