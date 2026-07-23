<?php
/**
 * Expired reservation persistence boundary.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

use DateTimeImmutable;

/** Releases a bounded batch of expired pending reservations. */
interface ExpirationStore {
	/**
	 * Release expired reservations from at most the requested candidate rows.
	 *
	 * @param DateTimeImmutable $expired_before_gmt Inclusive GMT expiry boundary.
	 * @param int               $candidate_limit    Maximum candidate rows to inspect.
	 */
	public function release_expired( DateTimeImmutable $expired_before_gmt, int $candidate_limit ): int;
}
