<?php
/**
 * Native coupon creation input.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Promotion;

use InvalidArgumentException;

/** Holds the small native coupon surface supported by Phase 2. */
final class CouponDraft {
	public const FIXED_CART    = 'fixed_cart';
	public const FIXED_PRODUCT = 'fixed_product';
	public const PERCENT       = 'percent';

	/**
	 * Create validated native coupon input.
	 *
	 * @param string $code          Normalized coupon code.
	 * @param string $discount_type Native WooCommerce discount type.
	 * @param string $amount        Non-negative decimal amount.
	 * @param string $description   Optional administrator description.
	 * @throws InvalidArgumentException When coupon input is unsupported.
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $discount_type,
		public readonly string $amount,
		public readonly string $description = ''
	) {
		if ( '' === trim( $this->code ) ) {
			throw new InvalidArgumentException( 'Coupon code is required.' );
		}

		if ( ! in_array( $this->discount_type, self::discount_types(), true ) ) {
			throw new InvalidArgumentException( 'Unsupported coupon discount type.' );
		}

		if ( ! is_numeric( $this->amount ) || (float) $this->amount <= 0 ) {
			throw new InvalidArgumentException( 'Coupon amount must be greater than zero.' );
		}

		if ( self::PERCENT === $this->discount_type && (float) $this->amount > 100 ) {
			throw new InvalidArgumentException( 'Percentage coupons cannot exceed 100.' );
		}
	}

	/**
	 * Return supported native coupon discount types.
	 *
	 * @return string[]
	 */
	public static function discount_types(): array {
		return array( self::FIXED_CART, self::FIXED_PRODUCT, self::PERCENT );
	}
}
