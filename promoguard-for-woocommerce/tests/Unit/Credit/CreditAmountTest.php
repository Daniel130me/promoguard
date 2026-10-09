<?php
/**
 * Tests for exact store-credit amount validation.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Credit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PromoGuard\Credit\CreditAmount;

/** Covers decimal normalization without floating-point coercion. */
final class CreditAmountTest extends TestCase {
	/**
	 * Valid amounts retain exact precision and remove insignificant zeroes.
	 *
	 * @param string $input    Candidate amount.
	 * @param string $expected Normalized amount.
	 */
	#[DataProvider( 'valid_amounts' )]
	public function test_valid_amounts_are_normalized( string $input, string $expected ): void {
		self::assertSame( $expected, CreditAmount::normalize( $input ) );
	}

	/**
	 * Provide valid amount examples.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function valid_amounts(): array {
		return array(
			'zero'      => array( '0.00', '0' ),
			'integer'   => array( '10', '10' ),
			'fraction'  => array( '10.50000000', '10.5' ),
			'max scale' => array( '0.12345678', '0.12345678' ),
		);
	}

	/** Exact arithmetic supports reservation and refund caps without floats. */
	public function test_exact_arithmetic_and_comparison(): void {
		self::assertSame( '10.25', CreditAmount::add( '7.1', '3.15' ) );
		self::assertSame( '7.1', CreditAmount::subtract( '10.25', '3.15' ) );
		self::assertSame( '3.15', CreditAmount::minimum( '10.25', '3.15' ) );
		self::assertSame( 0, CreditAmount::compare( '1.0', '1' ) );
		self::assertGreaterThan( 0, CreditAmount::compare( '100000000000000000', '99999999999999999.99999999' ) );
	}

	/** Subtraction refuses to create a negative payable balance. */
	public function test_subtraction_rejects_negative_results(): void {
		$this->expectException( InvalidArgumentException::class );
		CreditAmount::subtract( '1', '1.01' );
	}

	/**
	 * Invalid or unsafe decimal representations are rejected.
	 *
	 * @param string $amount Candidate amount.
	 */
	#[DataProvider( 'invalid_amounts' )]
	public function test_invalid_amounts_are_rejected( string $amount ): void {
		$this->expectException( InvalidArgumentException::class );
		CreditAmount::normalize( $amount );
	}

	/**
	 * Provide invalid amount examples.
	 *
	 * @return array<string,array{string}>
	 */
	public static function invalid_amounts(): array {
		return array(
			'negative'       => array( '-1' ),
			'exponent'       => array( '1e3' ),
			'too precise'    => array( '0.123456789' ),
			'leading zeroes' => array( '010.00' ),
			'non numeric'    => array( 'ten' ),
		);
	}
}
