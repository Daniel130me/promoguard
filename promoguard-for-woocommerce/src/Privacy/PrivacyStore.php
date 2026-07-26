<?php
/**
 * Privacy data store contract.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Privacy;

/** Provides bounded personal-data reads and identifier anonymization. */
interface PrivacyStore {
	/**
	 * Find the canonical customer linked to a WordPress user or email hash.
	 *
	 * @param int|null $wp_user_id WordPress user ID when the email belongs to a user.
	 * @param string   $email_hash Domain-separated email hash.
	 */
	public function find_customer_id( ?int $wp_user_id, string $email_hash ): ?int;

	/**
	 * Return one bounded page from each personal-data category.
	 *
	 * @param int $customer_id Internal customer ID.
	 * @param int $page        One-based page number.
	 * @param int $limit       Maximum rows per category.
	 * @return array{
	 *   states:list<array<string,int|string|null>>,
	 *   usages:list<array<string,int|string|null>>,
	 *   decisions:list<array<string,int|string|null>>,
	 *   done:bool
	 * }
	 */
	public function export_page( int $customer_id, int $page, int $limit ): array;

	/**
	 * Remove direct identifiers while retaining anonymous accounting records.
	 *
	 * @param int $customer_id Internal customer ID.
	 */
	public function anonymize( int $customer_id ): bool;
}
