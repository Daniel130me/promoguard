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
	// Scalar types and focused method summaries document the exact arithmetic helpers.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag

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

	/**
	 * Compare two non-negative exact decimal amounts.
	 *
	 * @return int Less than zero, zero, or greater than zero.
	 */
	public static function compare( string $left, string $right ): int {
		return strcmp( self::scaled( $left ), self::scaled( $right ) );
	}

	/** Add two non-negative exact decimal amounts. */
	public static function add( string $left, string $right ): string {
		$left  = self::scaled( $left );
		$right = self::scaled( $right );
		$carry = 0;
		$sum   = '';

		for ( $index = strlen( $left ) - 1; $index >= 0; --$index ) {
			$value = (int) $left[ $index ] + (int) $right[ $index ] + $carry;
			$sum   = (string) ( $value % 10 ) . $sum;
			$carry = intdiv( $value, 10 );
		}

		if ( $carry > 0 ) {
			$sum = (string) $carry . $sum;
		}

		return self::from_scaled( $sum );
	}

	/**
	 * Subtract one exact amount without permitting a negative result.
	 *
	 * @throws InvalidArgumentException When the result would be negative.
	 */
	public static function subtract( string $left, string $right ): string {
		$left  = self::scaled( $left );
		$right = self::scaled( $right );
		if ( strcmp( $left, $right ) < 0 ) {
			throw new InvalidArgumentException( 'Credit subtraction cannot produce a negative amount.' );
		}

		$borrow     = 0;
		$difference = '';
		for ( $index = strlen( $left ) - 1; $index >= 0; --$index ) {
			$value  = (int) $left[ $index ] - (int) $right[ $index ] - $borrow;
			$borrow = $value < 0 ? 1 : 0;
			$value  = $value < 0 ? $value + 10 : $value;

			$difference = (string) $value . $difference;
		}

		return self::from_scaled( $difference );
	}

	/** Return the smaller of two exact decimal amounts. */
	public static function minimum( string $left, string $right ): string {
		return self::compare( $left, $right ) <= 0 ? self::normalize( $left ) : self::normalize( $right );
	}

	/** Convert one validated amount to a fixed-width eight-decimal digit string. */
	private static function scaled( string $amount ): string {
		$parts    = explode( '.', self::normalize( $amount ), 2 );
		$fraction = str_pad( $parts[1] ?? '', self::MAX_SCALE, '0' );

		return str_pad( $parts[0] . $fraction, self::MAX_WHOLE_DIGITS + self::MAX_SCALE, '0', STR_PAD_LEFT );
	}

	/** Convert a scaled digit string back to the public normalized form. */
	private static function from_scaled( string $amount ): string {
		$amount   = str_pad( ltrim( $amount, '0' ), self::MAX_SCALE + 1, '0', STR_PAD_LEFT );
		$position = strlen( $amount ) - self::MAX_SCALE;
		$decimal  = substr( $amount, 0, $position ) . '.' . substr( $amount, $position );

		return self::normalize( $decimal );
	}
}
