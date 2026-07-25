<?php
/**
 * Minimal analytics order snapshot.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Analytics;

/** Carries only the WooCommerce order values needed by reporting. */
final class OrderSnapshot {
	/**
	 * Configure normalized order values.
	 *
	 * @param string $currency Order currency code.
	 * @param string $total    Order total.
	 */
	public function __construct(
		public readonly string $currency,
		public readonly string $total
	) {}
}
