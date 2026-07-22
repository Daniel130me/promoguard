<?php
/**
 * Campaign persistence contract.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Campaign;

/** Defines the persistence operations required by campaign administration. */
interface CampaignStore {
	/**
	 * Persist a new campaign.
	 *
	 * @param Campaign $campaign Validated unsaved campaign.
	 */
	public function create( Campaign $campaign ): int;

	/**
	 * Persist a campaign update.
	 *
	 * @param Campaign      $campaign Validated updated campaign.
	 * @param Campaign|null $current  Previously loaded campaign, when available.
	 */
	public function update( Campaign $campaign, ?Campaign $current = null ): bool;

	/**
	 * Find one campaign.
	 *
	 * @param int $campaign_id Campaign primary key.
	 */
	public function find( int $campaign_id ): ?Campaign;

	/**
	 * Return a bounded campaign page.
	 *
	 * @param int         $page     One-based page number.
	 * @param int         $per_page Requested page size.
	 * @param string|null $status   Optional stored status.
	 * @return array{items: Campaign[], total: int, page: int, per_page: int}
	 */
	public function page( int $page, int $per_page, ?string $status = null ): array;

	/**
	 * Archive one campaign.
	 *
	 * @param int $campaign_id Campaign primary key.
	 */
	public function archive( int $campaign_id ): bool;

	/**
	 * Delete one unused Draft campaign.
	 *
	 * @param int $campaign_id Campaign primary key.
	 */
	public function delete_unused_draft( int $campaign_id ): bool;
}
