<?php
/**
 * Hashed customer identifier.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Customer;

use DateTimeImmutable;
use InvalidArgumentException;

/** Represents one non-reversible customer identifier. */
final class CustomerIdentifier {
	public const TYPE_USER  = 'user';
	public const TYPE_EMAIL = 'email';

	/**
	 * Create a validated identifier snapshot.
	 *
	 * @param int|null          $id                Persisted identifier ID.
	 * @param int               $customer_id       Owning internal customer ID.
	 * @param string            $identifier_type   Supported identifier type.
	 * @param string            $identifier_hash   Lowercase HMAC-SHA256 digest.
	 * @param bool              $is_primary        Whether this is the primary identifier of its type.
	 * @param DateTimeImmutable $first_seen_at_gmt First observation timestamp.
	 * @param DateTimeImmutable $last_seen_at_gmt  Latest observation timestamp.
	 * @throws InvalidArgumentException When identifier state is inconsistent.
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly int $customer_id,
		public readonly string $identifier_type,
		public readonly string $identifier_hash,
		public readonly bool $is_primary,
		public readonly DateTimeImmutable $first_seen_at_gmt,
		public readonly DateTimeImmutable $last_seen_at_gmt
	) {
		$this->validate();
	}

	/**
	 * Whether an identifier type is part of the MVP boundary.
	 *
	 * @param string $type Candidate identifier type.
	 */
	public static function supports( string $type ): bool {
		return in_array( $type, array( self::TYPE_USER, self::TYPE_EMAIL ), true );
	}

	/**
	 * Validate identifiers, digest shape, and observation chronology.
	 *
	 * @throws InvalidArgumentException When identifier state is inconsistent.
	 */
	private function validate(): void {
		if ( null !== $this->id && $this->id < 1 ) {
			throw new InvalidArgumentException( 'Customer identifier ID must be positive.' );
		}

		if ( $this->customer_id < 1 || ! self::supports( $this->identifier_type ) ) {
			throw new InvalidArgumentException( 'Customer identifier ownership or type is invalid.' );
		}

		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $this->identifier_hash ) ) {
			throw new InvalidArgumentException( 'Customer identifier hash must be lowercase HMAC-SHA256.' );
		}

		if ( 0 !== $this->first_seen_at_gmt->getOffset() || 0 !== $this->last_seen_at_gmt->getOffset() ) {
			throw new InvalidArgumentException( 'Customer identifier timestamps must use GMT.' );
		}

		if ( $this->last_seen_at_gmt < $this->first_seen_at_gmt ) {
			throw new InvalidArgumentException( 'Last-seen time cannot precede first-seen time.' );
		}
	}
}
