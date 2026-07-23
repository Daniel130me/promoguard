<?php
/**
 * Campaign promotion persistence contract.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Promotion;

/** Defines the assignment operations required by campaign administration. */
interface CampaignPromotionStore {
	/**
	 * Find one assignment by its unique source identity.
	 *
	 * @param string $source      Source key.
	 * @param string $source_type Source promotion type.
	 * @param string $external_id Stable source identifier.
	 * @param bool   $for_update  Whether to lock the matching row.
	 */
	public function find_by_source(
		string $source,
		string $source_type,
		string $external_id,
		bool $for_update = false
	): ?CampaignPromotion;
	/**
	 * Attach or explicitly reassign one promotion.
	 *
	 * @param int                 $campaign_id       Destination campaign ID.
	 * @param Promotion           $promotion         Resolved source promotion.
	 * @param bool                $allow_reassignment Whether ownership may change.
	 * @param string|null         $channel           Optional channel.
	 * @param string|null         $label             Optional label.
	 * @param int                 $sort_order        Administrative order.
	 * @param array<string,mixed> $settings          Assignment settings.
	 */
	public function assign(
		int $campaign_id,
		Promotion $promotion,
		bool $allow_reassignment = false,
		?string $channel = null,
		?string $label = null,
		int $sort_order = 0,
		array $settings = array()
	): CampaignPromotion;

	/**
	 * Return a bounded campaign assignment list.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @param int $limit       Requested result cap.
	 * @return CampaignPromotion[]
	 */
	public function for_campaign( int $campaign_id, int $limit = 100 ): array;

	/**
	 * Detach one assignment without changing its source promotion.
	 *
	 * @param int $assignment_id Assignment primary key.
	 * @param int $campaign_id   Owning campaign ID.
	 */
	public function detach( int $assignment_id, int $campaign_id ): bool;
}
