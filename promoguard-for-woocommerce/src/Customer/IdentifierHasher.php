<?php
/**
 * Customer identifier hashing.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Customer;

use InvalidArgumentException;

/** Creates domain-separated HMAC-SHA256 customer identifiers. */
final class IdentifierHasher {
	private const KEY_HEX_LENGTH = 64;

	/**
	 * Raw binary HMAC key.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Initialize from the persistent hexadecimal plugin key.
	 *
	 * @param string $hex_key Persistent hexadecimal HMAC key.
	 * @throws InvalidArgumentException When the key is not 32 hexadecimal bytes.
	 */
	public function __construct( string $hex_key ) {
		if ( self::KEY_HEX_LENGTH !== strlen( $hex_key ) || 1 !== preg_match( '/^[0-9a-f]+$/i', $hex_key ) ) {
			throw new InvalidArgumentException( 'Identifier hash key must be 32 hexadecimal bytes.' );
		}

		$key = hex2bin( $hex_key );
		if ( false === $key ) {
			throw new InvalidArgumentException( 'Identifier hash key is invalid.' );
		}

		$this->key = $key;
	}

	/**
	 * Hash a normalized identifier with its type as a domain separator.
	 *
	 * @param string $type             Supported identifier type.
	 * @param string $normalized_value Validated, normalized identifier value.
	 * @throws InvalidArgumentException When the identifier input is invalid.
	 */
	public function hash( string $type, string $normalized_value ): string {
		if ( ! CustomerIdentifier::supports( $type ) || '' === $normalized_value ) {
			throw new InvalidArgumentException( 'A supported identifier type and normalized value are required.' );
		}

		return hash_hmac( 'sha256', $type . ':' . $normalized_value, $this->key );
	}
}
