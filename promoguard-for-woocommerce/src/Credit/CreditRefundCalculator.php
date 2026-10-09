<?php
/**
 * Store-credit refund eligibility policy.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Credit;

/** Excludes shipping and fees from the amount eligible for credit restoration. */
final class CreditRefundCalculator {
	/**
	 * Calculate the merchandise-and-tax portion of a refund.
	 *
	 * Itemized refunds use their product lines directly. For amount-only refunds,
	 * explicitly refunded shipping and fees are removed from the refund total.
	 *
	 * @param array<int,string> $line_amounts Product line totals including product tax.
	 * @param string            $refund_total Absolute refund total.
	 * @param string            $shipping     Absolute refunded shipping and shipping tax.
	 * @param string            $fees         Absolute refunded fee totals and fee tax.
	 */
	public function calculate(
		array $line_amounts,
		string $refund_total,
		string $shipping,
		string $fees
	): string {
		if ( array() !== $line_amounts ) {
			$total = '0';
			foreach ( $line_amounts as $amount ) {
				$total = CreditAmount::add( $total, $amount );
			}
			return $total;
		}

		$excluded = CreditAmount::add( $shipping, $fees );
		if ( CreditAmount::compare( $refund_total, $excluded ) <= 0 ) {
			return '0';
		}

		return CreditAmount::subtract( $refund_total, $excluded );
	}
}
