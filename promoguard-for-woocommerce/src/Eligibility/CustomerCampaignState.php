<?php
/**
 * Bounded customer campaign state.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Eligibility;

use DateTimeImmutable;
use InvalidArgumentException;

/** Immutable counter snapshot used by checkout eligibility. */
final class CustomerCampaignState {
	/**
	 * Create a validated counter snapshot.
	 *
	 * @param int                    $campaign_id          Campaign ID.
	 * @param int                    $customer_id          Customer ID.
	 * @param int                    $consumed_count       Counted usages.
	 * @param int                    $reserved_count       Active reservations.
	 * @param string                 $total_discount       Exact stored decimal.
	 * @param DateTimeImmutable|null $first_consumed_at_gmt First consumption time.
	 * @param DateTimeImmutable|null $last_consumed_at_gmt  Latest consumption time.
	 * @param int|null               $last_order_id        Latest counted order ID.
	 * @param int                    $lock_version         Optimistic lock version.
	 * @throws InvalidArgumentException When identifiers, counters, or chronology are invalid.
	 */
	public function __construct(
		public readonly int $campaign_id,
		public readonly int $customer_id,
		public readonly int $consumed_count,
		public readonly int $reserved_count,
		public readonly string $total_discount,
		public readonly ?DateTimeImmutable $first_consumed_at_gmt,
		public readonly ?DateTimeImmutable $last_consumed_at_gmt,
		public readonly ?int $last_order_id,
		public readonly int $lock_version
	) {
		if ( $campaign_id < 1 || $customer_id < 1 || ( null !== $last_order_id && $last_order_id < 1 ) ) {
			throw new InvalidArgumentException( 'Campaign-state identifiers must be positive.' );
		}

		if ( $consumed_count < 0 || $reserved_count < 0 || $lock_version < 0 ) {
			throw new InvalidArgumentException( 'Campaign-state counters cannot be negative.' );
		}

		foreach ( array_filter( array( $first_consumed_at_gmt, $last_consumed_at_gmt ) ) as $timestamp ) {
			if ( 0 !== $timestamp->getOffset() ) {
				throw new InvalidArgumentException( 'Campaign-state timestamps must use GMT.' );
			}
		}

		if (
			null !== $first_consumed_at_gmt
			&& null !== $last_consumed_at_gmt
			&& $last_consumed_at_gmt < $first_consumed_at_gmt
		) {
			throw new InvalidArgumentException( 'Last consumption cannot precede first consumption.' );
		}
	}

	/** Return the usage count that must be protected from oversubscription. */
	public function committed_count(): int {
		return $this->consumed_count + $this->reserved_count;
	}
}
