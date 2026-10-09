<?php
/**
 * Store-credit persistence contract.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Credit;

/** Provides atomic, idempotent credits and bounded balance reads. */
interface CreditStore {
	/**
	 * Create one credit entry unless its source reference already exists.
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param string $amount      Positive decimal amount.
	 * @param string $currency    ISO-style three-letter currency code.
	 * @param string $type        Stable transaction type.
	 * @param string $source      Stable subsystem source.
	 * @param string $reference   Source-scoped idempotency reference.
	 * @param string $description Administrator-facing ledger description.
	 */
	public function grant(
		int $user_id,
		string $amount,
		string $currency,
		string $type,
		string $source,
		string $reference,
		string $description
	): CreditGrantResult;

	/**
	 * Return one user's balance for an exact currency.
	 *
	 * @param int    $user_id  WordPress user ID.
	 * @param string $currency ISO-style three-letter currency code.
	 */
	public function balance( int $user_id, string $currency ): string;
}
