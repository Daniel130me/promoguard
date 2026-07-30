<?php
/**
 * Store-credit refund restoration result.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Credit;

/** Reports an idempotent, capped refund restoration attempt. */
final class CreditRestoreResult {
	/**
	 * Capture one restoration outcome.
	 *
	 * @param bool   $created Whether a new ledger entry was written.
	 * @param string $amount  Amount restored by this refund.
	 * @param string $balance Exact balance after the attempt.
	 */
	public function __construct(
		public readonly bool $created,
		public readonly string $amount,
		public readonly string $balance
	) {}
}
