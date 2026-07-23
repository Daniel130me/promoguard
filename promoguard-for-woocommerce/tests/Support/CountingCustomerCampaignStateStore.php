<?php
/**
 * Counting customer campaign state store.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Support;

use PromoGuard\Eligibility\CustomerCampaignState;
use PromoGuard\Eligibility\CustomerCampaignStateStore;

/** Captures bounded state reads made by the eligibility engine. */
final class CountingCustomerCampaignStateStore implements CustomerCampaignStateStore {
	/**
	 * Number of bounded lookups performed.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * Configure the state returned by every lookup.
	 *
	 * @param CustomerCampaignState|null $state Optional counter snapshot.
	 */
	public function __construct( private readonly ?CustomerCampaignState $state = null ) {}

	/**
	 * Count and satisfy one state lookup.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @param int $customer_id Customer ID.
	 */
	public function find( int $campaign_id, int $customer_id ): ?CustomerCampaignState {
		++$this->calls;
		return $this->state;
	}
}
