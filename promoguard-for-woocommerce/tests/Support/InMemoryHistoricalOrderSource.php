<?php
/**
 * In-memory historical order source.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Support;

use PromoGuard\Indexing\HistoricalOrderPage;
use PromoGuard\Indexing\HistoricalOrderSource;
use PromoGuard\Indexing\IndexingJob;

/** Returns one configured page for processor unit tests. */
final class InMemoryHistoricalOrderSource implements HistoricalOrderSource {
	/**
	 * Configure the page returned to tests.
	 *
	 * @param HistoricalOrderPage $result Source page.
	 */
	public function __construct( public HistoricalOrderPage $result ) {}

	/**
	 * Return the configured source page.
	 *
	 * @param IndexingJob $job Claimed test job.
	 */
	public function page( IndexingJob $job ): HistoricalOrderPage {
		return $this->result;
	}
}
