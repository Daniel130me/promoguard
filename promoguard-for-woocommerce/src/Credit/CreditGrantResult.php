<?php
/**
 * Store-credit grant result.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Credit;

/** Reports whether an idempotent grant was created and its resulting balance. */
final class CreditGrantResult {
	/**
	 * Capture one grant attempt.
	 *
	 * @param bool     $created        Whether a new ledger transaction was created.
	 * @param string   $balance        Resulting exact decimal balance.
	 * @param int|null $transaction_id Existing or newly created transaction ID.
	 */
	public function __construct(
		public readonly bool $created,
		public readonly string $balance,
		public readonly ?int $transaction_id
	) {}
}
