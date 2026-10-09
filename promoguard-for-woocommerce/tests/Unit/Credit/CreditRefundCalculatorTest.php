<?php
/**
 * Credit refund policy tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Credit;

use PHPUnit\Framework\TestCase;
use PromoGuard\Credit\CreditRefundCalculator;

/** Verifies non-shipping refund eligibility without floating-point money math. */
final class CreditRefundCalculatorTest extends TestCase {
	// Descriptive test names are the test documentation.
	// phpcs:disable Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FunctionComment.Missing

	private CreditRefundCalculator $calculator;

	protected function setUp(): void {
		$this->calculator = new CreditRefundCalculator();
	}

	public function test_itemized_refund_uses_only_product_lines_and_tax(): void {
		$this->assertSame( '32.5', $this->calculator->calculate( array( '22', '10.5' ), '40', '7.5', '3' ) );
	}

	public function test_amount_only_refund_excludes_shipping_and_fees(): void {
		$this->assertSame( '25', $this->calculator->calculate( array(), '40', '10', '5' ) );
	}

	public function test_shipping_only_refund_restores_nothing(): void {
		$this->assertSame( '0', $this->calculator->calculate( array(), '12', '12', '0' ) );
	}

	public function test_exclusions_cannot_make_eligible_refund_negative(): void {
		$this->assertSame( '0', $this->calculator->calculate( array(), '5', '6', '1' ) );
	}
}
