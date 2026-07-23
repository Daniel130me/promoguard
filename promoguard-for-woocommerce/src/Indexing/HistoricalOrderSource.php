<?php
/**
 * Historical WooCommerce order source boundary.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Indexing;

/** Loads one bounded page without exposing WooCommerce objects to the core. */
interface HistoricalOrderSource {
	/**
	 * Load the next full or targeted page.
	 *
	 * @param IndexingJob $job Claimed job and next-page cursor.
	 */
	public function page( IndexingJob $job ): HistoricalOrderPage;
}
