<?php
/**
 * In-memory customer identity store.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use PromoGuard\Customer\Customer;
use PromoGuard\Customer\CustomerIdentityStore;

/** Exposes resolver behavior to unit tests without database coupling. */
final class InMemoryCustomerIdentityStore implements CustomerIdentityStore {
	/**
	 * Persisted customers indexed by ID.
	 *
	 * @var array<int,Customer>
	 */
	public array $customers = array();

	/**
	 * Guest-to-canonical customer mappings.
	 *
	 * @var array<int,int>
	 */
	public array $merged = array();

	/**
	 * Identifier ownership indexed by type and hash.
	 *
	 * @var array<string,int>
	 */
	private array $identifiers = array();

	/**
	 * Execute one operation immediately.
	 *
	 * @template T
	 * @param callable():T $operation Identity operation.
	 * @return T
	 */
	public function transaction( callable $operation ): mixed {
		return $operation();
	}

	/**
	 * Find a customer by WordPress user ID.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 */
	public function find_by_user_id( int $wp_user_id ): ?Customer {
		foreach ( $this->customers as $customer ) {
			if ( $customer->wp_user_id === $wp_user_id ) {
				return $customer;
			}
		}
		return null;
	}

	/**
	 * Find the canonical customer for an identifier.
	 *
	 * @param string $type Identifier type.
	 * @param string $hash Hashed identifier value.
	 */
	public function find_by_identifier( string $type, string $hash ): ?Customer {
		$customer_id = $this->identifiers[ $type . ':' . $hash ] ?? null;
		if ( null === $customer_id ) {
			return null;
		}
		$customer_id = $this->merged[ $customer_id ] ?? $customer_id;
		return $this->customers[ $customer_id ] ?? null;
	}

	/**
	 * Create a persisted customer.
	 *
	 * @param int|null $wp_user_id WordPress user ID for authenticated customers.
	 */
	public function create( ?int $wp_user_id ): Customer {
		$id       = count( $this->customers ) + 1;
		$now      = new DateTimeImmutable( '2026-07-23 00:00:00', new DateTimeZone( 'UTC' ) );
		$customer = new Customer( $id, $wp_user_id, null, $now, $now );

		$this->customers[ $id ] = $customer;
		return $customer;
	}

	/**
	 * Attach an identifier.
	 *
	 * @param int    $customer_id Customer ID.
	 * @param string $type        Identifier type.
	 * @param string $hash        Hashed identifier value.
	 * @param bool   $is_primary  Whether this is the primary identifier.
	 */
	public function attach_identifier( int $customer_id, string $type, string $hash, bool $is_primary ): void {
		$this->identifiers[ $type . ':' . $hash ] = $customer_id;
	}

	/**
	 * Observe an existing identifier.
	 *
	 * @param int    $customer_id Customer ID.
	 * @param string $type        Identifier type.
	 * @param string $hash        Hashed identifier value.
	 */
	public function touch_identifier( int $customer_id, string $type, string $hash ): void {}

	/**
	 * Merge a guest into an authenticated customer.
	 *
	 * @param int $guest_customer_id         Guest customer ID.
	 * @param int $authenticated_customer_id Authenticated customer ID.
	 */
	public function merge_guest( int $guest_customer_id, int $authenticated_customer_id ): void {
		$this->merged[ $guest_customer_id ] = $authenticated_customer_id;
		foreach ( $this->identifiers as $key => $customer_id ) {
			if ( $guest_customer_id === $customer_id ) {
				$this->identifiers[ $key ] = $authenticated_customer_id;
			}
		}
	}
}
