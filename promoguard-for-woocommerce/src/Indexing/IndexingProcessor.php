<?php
/**
 * Historical indexing processor boundary.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Indexing;

/** Processes one claimed bounded job page. */
interface IndexingProcessor {
	/**
	 * Process one page and return exact order-level progress.
	 *
	 * @param IndexingJob $job Claimed job.
	 */
	public function process( IndexingJob $job ): IndexingBatch;
}
