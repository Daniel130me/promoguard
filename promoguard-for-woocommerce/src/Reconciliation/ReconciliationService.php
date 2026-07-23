<?php
/**
 * Reconciliation application service.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reconciliation;

use DateTimeImmutable;
use InvalidArgumentException;

/** Runs bounded aggregate rebuilds outside checkout. */
final class ReconciliationService {
	public const DEFAULT_BATCH_SIZE = 50;
	public const MAXIMUM_BATCH_SIZE = 100;

	/**
	 * Configure reconciliation persistence.
	 *
	 * @param ReconciliationStore $store Aggregate rebuild persistence.
	 */
	public function __construct( private readonly ReconciliationStore $store ) {}

	/**
	 * Reconcile one bounded state page.
	 *
	 * @param DateTimeImmutable $as_of_gmt Reconciliation boundary.
	 * @param int               $after_id  Last state ID processed.
	 * @param int               $limit     Maximum states to inspect.
	 * @throws InvalidArgumentException When cursor or batch size is invalid.
	 */
	public function reconcile_batch(
		DateTimeImmutable $as_of_gmt,
		int $after_id = 0,
		int $limit = self::DEFAULT_BATCH_SIZE
	): ReconciliationBatch {
		if (
			0 !== $as_of_gmt->getOffset()
			|| $after_id < 0
			|| $limit < 1
			|| $limit > self::MAXIMUM_BATCH_SIZE
		) {
			throw new InvalidArgumentException( 'Reconciliation batch input is invalid.' );
		}

		return $this->store->reconcile_after( $as_of_gmt, $after_id, $limit );
	}
}
