<?php
/**
 * Promotion source result.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Promotion;

use InvalidArgumentException;

/** Identifies one promotion without exposing source-specific objects. */
final class Promotion {
	/**
	 * Create a source-neutral promotion result.
	 *
	 * @param string $source      Promotion source key.
	 * @param string $source_type Source-specific promotion type.
	 * @param string $external_id Stable source identifier.
	 * @param string $code        Display and application code.
	 * @param string $label       Administrative label.
	 * @param bool   $available   Whether the source object can currently be used.
	 * @throws InvalidArgumentException When a required identity value is empty.
	 */
	public function __construct(
		public readonly string $source,
		public readonly string $source_type,
		public readonly string $external_id,
		public readonly string $code,
		public readonly string $label,
		public readonly bool $available
	) {
		foreach ( array( $this->source, $this->source_type, $this->external_id, $this->code ) as $identity_value ) {
			if ( '' === trim( $identity_value ) ) {
				throw new InvalidArgumentException( 'Promotion identity values are required.' );
			}
		}
	}
}
