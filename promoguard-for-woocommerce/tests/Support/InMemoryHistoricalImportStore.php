<?php
/**
 * In-memory historical import store.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Support;

use PromoGuard\Indexing\HistoricalCampaign;
use PromoGuard\Indexing\HistoricalImportStore;
use PromoGuard\Indexing\HistoricalUsage;

/** Captures idempotent imports for processor unit tests. */
final class InMemoryHistoricalImportStore implements HistoricalImportStore {
	/**
	 * Campaign fixtures keyed by normalized code.
	 *
	 * @var array<string,HistoricalCampaign>
	 */
	public array $campaigns = array();

	/**
	 * Imported usage fixtures keyed by order and campaign.
	 *
	 * @var array<string,HistoricalUsage>
	 */
	public array $usages = array();

	/**
	 * Number of bulk campaign lookups.
	 *
	 * @var int
	 */
	public int $lookups = 0;

	/**
	 * Return configured campaigns for the requested codes.
	 *
	 * @param string[] $code_keys Normalized coupon codes.
	 * @return array<string,HistoricalCampaign>
	 */
	public function campaigns_for_codes( array $code_keys ): array {
		++$this->lookups;
		return array_intersect_key( $this->campaigns, array_flip( $code_keys ) );
	}

	/**
	 * Import a usage once.
	 *
	 * @param HistoricalUsage $usage Validated usage.
	 */
	public function import( HistoricalUsage $usage ): bool {
		$key = $usage->order_id . ':' . $usage->campaign_id;
		if ( isset( $this->usages[ $key ] ) ) {
			return false;
		}
		$this->usages[ $key ] = $usage;
		return true;
	}
}
