<?php
/**
 * Store-credit checkout amount policy.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Credit;

/** Caps redemption to available credit and non-shipping order value. */
final class CreditCheckoutCalculator {
	/**
	 * Return the maximum safe credit for merchandise and tax.
	 *
	 * Shipping is intentionally absent from this contract, so it can never
	 * increase the amount of credit applied to an order.
	 *
	 * @param string $available   Spendable currency-scoped balance.
	 * @param string $merchandise Discounted merchandise total excluding tax.
	 * @param string $tax         Merchandise tax total.
	 */
	public function calculate( string $available, string $merchandise, string $tax ): string {
		$eligible = CreditAmount::add( $merchandise, $tax );
		return CreditAmount::minimum( $available, $eligible );
	}
}
