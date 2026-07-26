<?php
/**
 * Privacy retention store contract.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Privacy;

use DateTimeImmutable;

/** Deletes bounded batches of expired diagnostic records. */
interface RetentionStore {
	/**
	 * Delete a bounded batch of decisions older than the retention boundary.
	 *
	 * @param DateTimeImmutable $retained_after_gmt Exclusive GMT boundary.
	 * @param int               $limit              Maximum rows to delete.
	 */
	public function delete_decisions_before( DateTimeImmutable $retained_after_gmt, int $limit ): int;
}
