<?php
/**
 * In-memory reconciliation test double.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Support;

use DateTimeImmutable;
use PromoGuard\Reconciliation\ReconciliationBatch;
use PromoGuard\Reconciliation\ReconciliationStore;

/** Records bounded reconciliation requests without database persistence. */
final class InMemoryReconciliationStore implements ReconciliationStore {
	/**
	 * Recorded reconciliation inputs.
	 *
	 * @var array<int,array{after_id:int,limit:int}>
	 */
	public array $requests = array();

	/**
	 * Result returned by the next request.
	 *
	 * @var ReconciliationBatch
	 */
	public ReconciliationBatch $result;

	/** Configure a stable empty result. */
	public function __construct() {
		$this->result = new ReconciliationBatch( 0, 0, null );
	}

	/**
	 * Record and answer one reconciliation request.
	 *
	 * @param DateTimeImmutable $as_of_gmt Reconciliation boundary.
	 * @param int               $after_id  Last state ID processed.
	 * @param int               $limit     Maximum states to inspect.
	 */
	public function reconcile_after( DateTimeImmutable $as_of_gmt, int $after_id, int $limit ): ReconciliationBatch {
		unset( $as_of_gmt );
		$this->requests[] = compact( 'after_id', 'limit' );
		return $this->result;
	}
}
