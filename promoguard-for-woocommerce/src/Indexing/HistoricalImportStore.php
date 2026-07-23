<?php
/**
 * Historical usage persistence boundary.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Indexing;

/** Resolves campaign assignments in bulk and imports consumed usage once. */
interface HistoricalImportStore {
	/**
	 * Resolve distinct normalized coupon codes in one bounded lookup.
	 *
	 * @param string[] $code_keys WooCommerce-normalized coupon codes.
	 * @return array<string,HistoricalCampaign>
	 */
	public function campaigns_for_codes( array $code_keys ): array;

	/**
	 * Insert one order/campaign usage unless it already exists.
	 *
	 * @param HistoricalUsage $usage Validated consumed usage.
	 * @return bool True only when a new usage row was created.
	 */
	public function import( HistoricalUsage $usage ): bool;
}
