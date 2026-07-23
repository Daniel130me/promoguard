<?php
/**
 * Historical indexing job persistence boundary.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Indexing;

/** Persists the single active or most recently completed indexing job. */
interface IndexingJobStore {
	/** Return the current job, when one has been started. */
	public function current(): ?IndexingJob;

	/**
	 * Persist the complete bounded job snapshot.
	 *
	 * @param IndexingJob $job Validated job state.
	 */
	public function save( IndexingJob $job ): void;
}
