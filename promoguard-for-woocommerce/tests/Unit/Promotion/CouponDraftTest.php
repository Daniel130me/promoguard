<?php
/**
 * Native coupon draft tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Promotion;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PromoGuard\Promotion\CouponDraft;

/** Covers the supported native coupon creation boundary. */
final class CouponDraftTest extends TestCase {
	/** Supported coupon input remains available to the adapter. */
	public function test_supported_coupon_is_retained(): void {
		$draft = new CouponDraft( 'WELCOME10', CouponDraft::PERCENT, '10', 'Welcome offer' );

		self::assertSame( 'WELCOME10', $draft->code );
		self::assertSame( '10', $draft->amount );
	}

	/**
	 * Invalid coupon creation input is rejected.
	 *
	 * @param array<int, string> $arguments Constructor arguments.
	 */
	#[DataProvider( 'invalid_drafts' )]
	public function test_invalid_coupon_is_rejected( array $arguments ): void {
		$this->expectException( InvalidArgumentException::class );

		new CouponDraft( ...$arguments );
	}

	/**
	 * Provide unsupported coupon input.
	 *
	 * @return array<string, array{array<int, string>}>
	 */
	public static function invalid_drafts(): array {
		return array(
			'empty code'        => array( array( '', CouponDraft::PERCENT, '10' ) ),
			'unknown type'      => array( array( 'SAVE', 'bogo', '10' ) ),
			'zero amount'       => array( array( 'SAVE', CouponDraft::FIXED_CART, '0' ) ),
			'negative amount'   => array( array( 'SAVE', CouponDraft::FIXED_CART, '-1' ) ),
			'excess percentage' => array( array( 'SAVE', CouponDraft::PERCENT, '101' ) ),
		);
	}
}
