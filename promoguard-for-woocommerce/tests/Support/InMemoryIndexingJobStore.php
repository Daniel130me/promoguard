<?php
/**
 * In-memory historical indexing job store.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Support;

use PromoGuard\Indexing\IndexingJob;
use PromoGuard\Indexing\IndexingJobStore;

/** Provides deterministic job persistence for unit tests. */
final class InMemoryIndexingJobStore implements IndexingJobStore {
	/**
	 * Current in-memory job.
	 *
	 * @var IndexingJob|null
	 */
	public ?IndexingJob $job = null;

	/** Return the current test job. */
	public function current(): ?IndexingJob {
		return $this->job;
	}

	/**
	 * Save the current test job.
	 *
	 * @param IndexingJob $job Validated job state.
	 */
	public function save( IndexingJob $job ): void {
		$this->job = $job;
	}
}
