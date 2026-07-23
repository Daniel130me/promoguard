<?php
/**
 * Customer identity resolution result.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Customer;

use InvalidArgumentException;

/** Immutable result that never exposes a raw customer identifier. */
final class IdentityResolution {
	public const OUTCOME_CREATED  = 'created';
	public const OUTCOME_MATCHED  = 'matched';
	public const OUTCOME_MERGED   = 'merged';
	public const OUTCOME_CONFLICT = 'conflict';
	public const OUTCOME_MISSING  = 'missing';

	/**
	 * Create a validated resolution result.
	 *
	 * @param Customer|null $customer Resolved authoritative customer.
	 * @param string        $outcome  Resolution outcome.
	 * @throws InvalidArgumentException When result state is inconsistent.
	 */
	public function __construct(
		public readonly ?Customer $customer,
		public readonly string $outcome
	) {
		if ( ! in_array( $outcome, self::outcomes(), true ) ) {
			throw new InvalidArgumentException( 'Identity resolution outcome is invalid.' );
		}

		if ( self::OUTCOME_MISSING === $outcome && null !== $customer ) {
			throw new InvalidArgumentException( 'Missing identity cannot include a customer.' );
		}

		if ( self::OUTCOME_MISSING !== $outcome && null === $customer ) {
			throw new InvalidArgumentException( 'Resolved identity requires a customer.' );
		}
	}

	/** Whether the supplied identifiers conflict with another authenticated user. */
	public function has_conflict(): bool {
		return self::OUTCOME_CONFLICT === $this->outcome;
	}

	/**
	 * Return all supported resolution outcomes.
	 *
	 * @return string[]
	 */
	private static function outcomes(): array {
		return array(
			self::OUTCOME_CREATED,
			self::OUTCOME_MATCHED,
			self::OUTCOME_MERGED,
			self::OUTCOME_CONFLICT,
			self::OUTCOME_MISSING,
		);
	}
}
