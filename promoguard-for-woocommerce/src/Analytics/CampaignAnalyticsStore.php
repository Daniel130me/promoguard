<?php
/**
 * Campaign analytics page contract.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Analytics;

/** Reads bounded campaign-performance pages from plugin-owned facts. */
interface CampaignAnalyticsStore {
	/**
	 * Return one activity-ranked campaign page.
	 *
	 * @param AnalyticsFilter $filter   Validated report filters.
	 * @param int             $page     One-based page.
	 * @param int             $per_page Requested page size.
	 * @return array{
	 *   items: array<int,array{
	 *     campaign_id:int,campaign_name:string,redemptions:int,unique_customers:int,
	 *     campaign_orders:int,refunds:int,denials:int,
	 *     currencies:array<int,array{
	 *       currency:string,redemptions:int,refunds:int,discount_amount:string,
	 *       restored_discount_amount:string,average_discount_amount:string
	 *     }>
	 *   }>,
	 *   total:int,page:int,per_page:int
	 * }
	 */
	public function campaigns( AnalyticsFilter $filter, int $page, int $per_page ): array;
}
