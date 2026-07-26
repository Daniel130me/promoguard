<?php
/**
 * In-memory privacy retention store.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Support;

use DateTimeImmutable;
use PromoGuard\Privacy\RetentionStore;

/** Records a retention cleanup request. */
final class InMemoryRetentionStore implements RetentionStore {
	/**
	 * Last requested boundary.
	 *
	 * @var DateTimeImmutable|null
	 */
	public ?DateTimeImmutable $boundary = null;

	/**
	 * Last requested limit.
	 *
	 * @var int
	 */
	public int $limit = 0;

	/**
	 * Fixture deletion count.
	 *
	 * @var int
	 */
	public int $deleted = 0;

	/**
	 * Record one bounded deletion.
	 *
	 * @param DateTimeImmutable $retained_after_gmt Exclusive GMT boundary.
	 * @param int               $limit              Maximum rows.
	 */
	public function delete_decisions_before( DateTimeImmutable $retained_after_gmt, int $limit ): int {
		$this->boundary = $retained_after_gmt;
		$this->limit    = $limit;

		return $this->deleted;
	}
}
