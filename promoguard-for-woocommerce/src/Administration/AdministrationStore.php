<?php
/**
 * Administration read-model persistence contract.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Administration;

/** Supplies bounded operational data without exposing persistence details. */
interface AdministrationStore {
	/**
	 * Return lightweight operational counts.
	 *
	 * @return array{
	 *     campaigns: array<string,int>,
	 *     usages: array<string,int>,
	 *     totals: array{campaigns:int,usages:int}
	 * }
	 */
	public function overview(): array;

	/**
	 * Return a bounded usage-history page.
	 *
	 * @param int         $page        One-based page number.
	 * @param int         $per_page    Requested page size.
	 * @param int|null    $campaign_id Optional campaign filter.
	 * @param int|null    $order_id    Optional WooCommerce order filter.
	 * @param string|null $status      Optional usage-status filter.
	 * @return array{items: array<int,array<string,mixed>>, total:int, page:int, per_page:int}
	 */
	public function usages(
		int $page,
		int $per_page,
		?int $campaign_id,
		?int $order_id,
		?string $status
	): array;

	/**
	 * Return a bounded eligibility-decision page.
	 *
	 * @param int         $page        One-based page number.
	 * @param int         $per_page    Requested page size.
	 * @param int|null    $campaign_id Optional campaign filter.
	 * @param int|null    $order_id    Optional WooCommerce order filter.
	 * @param string|null $reason      Optional exact reason filter.
	 * @return array{items: array<int,array<string,mixed>>, total:int, page:int, per_page:int}
	 */
	public function decisions(
		int $page,
		int $per_page,
		?int $campaign_id,
		?int $order_id,
		?string $reason
	): array;
}
