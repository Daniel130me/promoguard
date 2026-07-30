<?php
/**
 * Tests for checkout credit caps.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Credit;

use PHPUnit\Framework\TestCase;
use PromoGuard\Credit\CreditCheckoutCalculator;

/** Covers non-shipping and non-negative checkout amount policy. */
final class CreditCheckoutCalculatorTest extends TestCase {
	/** Available balance caps redemption. */
	public function test_available_balance_caps_redemption(): void {
		$calculator = new CreditCheckoutCalculator();
		self::assertSame( '25', $calculator->calculate( '25', '80', '16' ) );
	}

	/** Merchandise and its tax cap redemption without any shipping input. */
	public function test_eligible_order_value_caps_redemption(): void {
		$calculator = new CreditCheckoutCalculator();
		self::assertSame( '96', $calculator->calculate( '200', '80', '16' ) );
	}

	/** Empty merchandise never creates a negative payable total. */
	public function test_zero_eligible_value_applies_no_credit(): void {
		$calculator = new CreditCheckoutCalculator();
		self::assertSame( '0', $calculator->calculate( '50', '0', '0' ) );
	}
}
