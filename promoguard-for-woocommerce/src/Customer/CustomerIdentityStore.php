<?php
/**
 * Customer identity persistence contract.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Customer;

/**
 * Provides the atomic primitives required by user-first identity resolution.
 */
interface CustomerIdentityStore {
	/**
	 * Execute one identity operation transactionally.
	 *
	 * @template T
	 * @param callable():T $operation Identity operation.
	 * @return T
	 */
	public function transaction( callable $operation ): mixed;

	/**
	 * Find the canonical customer for an authoritative WordPress user ID.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 */
	public function find_by_user_id( int $wp_user_id ): ?Customer;

	/**
	 * Find the canonical customer that owns a hashed identifier.
	 *
	 * @param string $type Identifier type.
	 * @param string $hash Hashed identifier value.
	 */
	public function find_by_identifier( string $type, string $hash ): ?Customer;

	/**
	 * Create and return a persisted guest or authenticated customer.
	 *
	 * @param int|null $wp_user_id WordPress user ID for authenticated customers.
	 */
	public function create( ?int $wp_user_id ): Customer;

	/**
	 * Attach a new identifier to a customer.
	 *
	 * @param int    $customer_id Customer ID.
	 * @param string $type        Identifier type.
	 * @param string $hash        Hashed identifier value.
	 * @param bool   $is_primary  Whether this is the primary identifier.
	 */
	public function attach_identifier( int $customer_id, string $type, string $hash, bool $is_primary ): void;

	/**
	 * Record another observation of an identifier already owned by a customer.
	 *
	 * @param int    $customer_id Customer ID.
	 * @param string $type        Identifier type.
	 * @param string $hash        Hashed identifier value.
	 */
	public function touch_identifier( int $customer_id, string $type, string $hash ): void;

	/**
	 * Merge one guest into an authenticated customer.
	 *
	 * Implementations must move identifiers and campaign state idempotently.
	 *
	 * @param int $guest_customer_id         Guest customer ID.
	 * @param int $authenticated_customer_id Authenticated customer ID.
	 */
	public function merge_guest( int $guest_customer_id, int $authenticated_customer_id ): void;
}
