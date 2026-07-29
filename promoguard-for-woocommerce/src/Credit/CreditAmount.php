<?php
/**
 * Exact decimal handling for store-credit amounts.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Credit;

use InvalidArgumentException;

/** Validates decimal strings without converting money through floating point. */
final class CreditAmount {
	private const MAX_WHOLE_DIGITS = 18;
	private const MAX_SCALE        = 8;

	/**
	 * Validate and normalize one non-negative credit amount.
	 *
	 * @param string $amount Decimal amount in the store currency.
	 * @throws InvalidArgumentException When the amount is not a supported decimal.
	 */
	public static function normalize( string $amount ): string {
		$amount  = trim( $amount );
		$pattern = '/^(?:0|[1-9][0-9]{0,' . ( self::MAX_WHOLE_DIGITS - 1 ) . '})(?:\.[0-9]{1,' . self::MAX_SCALE . '})?$/';

		if ( 1 !== preg_match( $pattern, $amount ) ) {
			throw new InvalidArgumentException( 'Credit amount must be a non-negative decimal with no more than eight decimal places.' );
		}

		$parts    = explode( '.', $amount, 2 );
		$fraction = isset( $parts[1] ) ? rtrim( $parts[1], '0' ) : '';

		return '' === $fraction ? $parts[0] : $parts[0] . '.' . $fraction;
	}

	/**
	 * Determine whether one validated amount can create a ledger entry.
	 *
	 * @param string $amount Candidate decimal amount.
	 */
	public static function is_positive( string $amount ): bool {
		return '0' !== self::normalize( $amount );
	}
}
