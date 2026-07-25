<?php
/**
 * Analytics read-model contract.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Analytics;

/** Reads bounded promotion-performance facts from the PromoGuard ledger. */
interface AnalyticsStore {
	/**
	 * Return aggregate ledger and denial metrics for one validated period.
	 *
	 * @param AnalyticsFilter $filter Validated report filters.
	 * @return array{
	 *   totals: array{redemptions:int,unique_customers:int,campaign_orders:int,global_orders:int,refunds:int,denials:int},
	 *   currencies: array<int,array{currency:string,discount_amount:string,restored_discount_amount:string}>,
	 *   denial_reasons: array<int,array{reason:string,count:int}>
	 * }
	 */
	public function summary( AnalyticsFilter $filter ): array;
}
