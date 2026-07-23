<?php
/**
 * In-memory expiration test double.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Support;

use DateTimeImmutable;
use PromoGuard\Reservation\ExpirationStore;

/** Records bounded expiration requests without database persistence. */
final class InMemoryExpirationStore implements ExpirationStore {
	/**
	 * Number of rows the fake reports as released.
	 *
	 * @var int
	 */
	public int $released = 0;

	/**
	 * Last requested GMT expiration boundary.
	 *
	 * @var DateTimeImmutable|null
	 */
	public ?DateTimeImmutable $expired_before_gmt = null;

	/**
	 * Last requested candidate limit.
	 *
	 * @var int
	 */
	public int $candidate_limit = 0;

	/**
	 * Record and answer one expiration batch.
	 *
	 * @param DateTimeImmutable $expired_before_gmt Inclusive GMT expiry boundary.
	 * @param int               $candidate_limit    Maximum candidate rows.
	 */
	public function release_expired( DateTimeImmutable $expired_before_gmt, int $candidate_limit ): int {
		$this->expired_before_gmt = $expired_before_gmt;
		$this->candidate_limit    = $candidate_limit;

		return $this->released;
	}
}
