<?php
/**
 * Reservation result.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

use InvalidArgumentException;

/** Identifies the authoritative usage row returned by an atomic reservation. */
final class ReservationResult {
	public const PENDING  = 'pending';
	public const CONSUMED = 'consumed';

	/**
	 * Create a reservation result.
	 *
	 * @param int    $usage_id Usage row ID.
	 * @param string $status   Pending or already-consumed status.
	 * @param bool   $created  Whether this attempt inserted the usage.
	 * @throws InvalidArgumentException When the result is inconsistent.
	 */
	public function __construct(
		public readonly int $usage_id,
		public readonly string $status,
		public readonly bool $created
	) {
		if ( $usage_id < 1 || ! in_array( $status, array( self::PENDING, self::CONSUMED ), true ) ) {
			throw new InvalidArgumentException( 'Reservation result is invalid.' );
		}

		if ( $created && self::PENDING !== $status ) {
			throw new InvalidArgumentException( 'A new reservation must be pending.' );
		}
	}
}
