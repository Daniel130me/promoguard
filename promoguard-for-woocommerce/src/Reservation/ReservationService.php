<?php
/**
 * Reservation application service.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

/** Applies a small bounded retry policy around atomic reservation persistence. */
final class ReservationService {
	private const MAX_ATTEMPTS = 3;

	/**
	 * Configure reservation persistence.
	 *
	 * @param ReservationStore $store Atomic reservation persistence.
	 */
	public function __construct( private readonly ReservationStore $store ) {}

	/**
	 * Reserve one campaign usage, retrying only transactional contention.
	 *
	 * @param ReservationRequest $request Validated reservation facts.
	 * @throws RetryableReservationException When every bounded attempt is exhausted.
	 */
	public function reserve( ReservationRequest $request ): ReservationResult {
		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt ) {
			try {
				return $this->store->reserve( $request );
			} catch ( RetryableReservationException $exception ) {
				if ( self::MAX_ATTEMPTS === $attempt ) {
					throw $exception;
				}
			}
		}

		// The bounded loop always returns or throws; this guards future edits.
		throw new RetryableReservationException( 'Reservation retry policy was exhausted.' );
	}
}
