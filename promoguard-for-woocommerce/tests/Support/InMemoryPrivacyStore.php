<?php
/**
 * In-memory privacy store.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Support;

use PromoGuard\Privacy\PrivacyStore;

/** Records privacy-service interactions and returns representative fixtures. */
final class InMemoryPrivacyStore implements PrivacyStore {
	/**
	 * Last requested WordPress user ID.
	 *
	 * @var int|null
	 */
	public ?int $requested_user_id = null;

	/**
	 * Last requested email hash.
	 *
	 * @var string|null
	 */
	public ?string $requested_email_hash = null;

	/**
	 * Last requested per-category limit.
	 *
	 * @var int
	 */
	public int $requested_limit = 0;

	/**
	 * Last anonymized customer ID.
	 *
	 * @var int|null
	 */
	public ?int $anonymized_customer_id = null;

	/**
	 * Record identity lookup inputs and return the fixture customer.
	 *
	 * @param int|null $wp_user_id WordPress user ID.
	 * @param string   $email_hash Hashed email identifier.
	 */
	public function find_customer_id( ?int $wp_user_id, string $email_hash ): ?int {
		$this->requested_user_id    = $wp_user_id;
		$this->requested_email_hash = $email_hash;

		return 7;
	}

	/**
	 * Return one fixture from every export category.
	 *
	 * @param int $customer_id Internal customer ID.
	 * @param int $page        One-based page number.
	 * @param int $limit       Maximum rows per category.
	 */
	public function export_page( int $customer_id, int $page, int $limit ): array {
		$this->requested_limit = $limit;

		return array(
			'states'    => array(
				array(
					'id'             => 1,
					'campaign_name'  => 'Spring promotion',
					'campaign_id'    => 3,
					'consumed_count' => 1,
				),
			),
			'usages'    => array(
				array(
					'id'              => 2,
					'campaign_name'   => 'Spring promotion',
					'discount_amount' => '10.00',
					'currency'        => 'USD',
				),
			),
			'decisions' => array(
				array(
					'id'       => 3,
					'decision' => 'denied',
					'reason'   => 'limit_reached',
				),
			),
			'done'      => false,
		);
	}

	/**
	 * Record the customer chosen for anonymization.
	 *
	 * @param int $customer_id Internal customer ID.
	 */
	public function anonymize( int $customer_id ): bool {
		$this->anonymized_customer_id = $customer_id;

		return true;
	}
}
