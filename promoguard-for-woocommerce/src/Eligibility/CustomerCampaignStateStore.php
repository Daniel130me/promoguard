<?php
/**
 * Customer campaign state persistence contract.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Eligibility;

/** Provides the single bounded state lookup required by eligibility. */
interface CustomerCampaignStateStore {
	/**
	 * Find one campaign/customer counter snapshot.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @param int $customer_id Customer ID.
	 */
	public function find( int $campaign_id, int $customer_id ): ?CustomerCampaignState;
}
