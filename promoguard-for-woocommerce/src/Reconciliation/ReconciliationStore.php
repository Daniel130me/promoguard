<?php
/**
 * Reconciliation persistence boundary.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reconciliation;

use DateTimeImmutable;

/** Rebuilds aggregate state from the authoritative usage ledger. */
interface ReconciliationStore {
	/**
	 * Reconcile state rows after a stable primary-key cursor.
	 *
	 * @param DateTimeImmutable $as_of_gmt Inclusive reconciliation boundary.
	 * @param int               $after_id  Last state ID processed.
	 * @param int               $limit     Maximum state rows to inspect.
	 */
	public function reconcile_after( DateTimeImmutable $as_of_gmt, int $after_id, int $limit ): ReconciliationBatch;
}
