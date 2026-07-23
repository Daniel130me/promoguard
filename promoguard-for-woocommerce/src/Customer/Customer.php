<?php
/**
 * Internal customer identity.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Customer;

use DateTimeImmutable;
use InvalidArgumentException;

/** Represents one internal customer or a merged identity tombstone. */
final class Customer {
	/**
	 * Create a validated customer snapshot.
	 *
	 * @param int               $id                      Persisted customer ID.
	 * @param int|null          $wp_user_id              Authoritative WordPress user ID.
	 * @param int|null          $merged_into_customer_id Merge target for a guest tombstone.
	 * @param DateTimeImmutable $created_at_gmt          Creation timestamp.
	 * @param DateTimeImmutable $updated_at_gmt          Update timestamp.
	 * @throws InvalidArgumentException When customer state is inconsistent.
	 */
	public function __construct(
		public readonly int $id,
		public readonly ?int $wp_user_id,
		public readonly ?int $merged_into_customer_id,
		public readonly DateTimeImmutable $created_at_gmt,
		public readonly DateTimeImmutable $updated_at_gmt
	) {
		$this->validate();
	}

	/** Whether this identity has no authoritative WordPress user. */
	public function is_guest(): bool {
		return null === $this->wp_user_id;
	}

	/** Whether this row redirects to another internal customer. */
	public function is_merged(): bool {
		return null !== $this->merged_into_customer_id;
	}

	/**
	 * Validate persistence identifiers and GMT chronology.
	 *
	 * @throws InvalidArgumentException When customer state is inconsistent.
	 */
	private function validate(): void {
		foreach ( array( $this->id, $this->wp_user_id, $this->merged_into_customer_id ) as $identifier ) {
			if ( null !== $identifier && $identifier < 1 ) {
				throw new InvalidArgumentException( 'Customer identifiers must be positive.' );
			}
		}

		if ( $this->id === $this->merged_into_customer_id ) {
			throw new InvalidArgumentException( 'A customer cannot merge into itself.' );
		}

		if ( null !== $this->wp_user_id && null !== $this->merged_into_customer_id ) {
			throw new InvalidArgumentException( 'An authenticated customer cannot be a merge tombstone.' );
		}

		if ( 0 !== $this->created_at_gmt->getOffset() || 0 !== $this->updated_at_gmt->getOffset() ) {
			throw new InvalidArgumentException( 'Customer timestamps must use GMT.' );
		}

		if ( $this->updated_at_gmt < $this->created_at_gmt ) {
			throw new InvalidArgumentException( 'Customer update cannot precede creation.' );
		}
	}
}
