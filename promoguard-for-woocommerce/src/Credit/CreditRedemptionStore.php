<?php
/**
 * Store-credit redemption persistence contract.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Credit;

/** Provides atomic order-scoped credit reservation and lifecycle transitions. */
	// Scalar signatures and lifecycle summaries form the complete persistence contract.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag
interface CreditRedemptionStore {
	/** Return spendable balance after active reservations for an exact currency. */
	public function available_balance( int $user_id, string $currency ): string;

	/**
	 * Atomically reserve up to a checkout maximum without overdrawing the account.
	 *
	 * Existing reserved or consumed orders return their original snapshot.
	 */
	public function reserve(
		int $user_id,
		int $order_id,
		string $maximum_amount,
		string $currency
	): ?CreditReservation;

	/** Post a reserved order debit to the ledger exactly once. */
	public function consume( int $order_id ): ?CreditReservation;

	/** Release a still-pending order reservation exactly once. */
	public function release( int $order_id ): ?CreditReservation;

	/** Restore a refund amount without exceeding the original consumed credit. */
	public function restore( int $order_id, int $refund_id, string $maximum_amount ): CreditRestoreResult;

	/** Return the current reservation for an order when one exists. */
	public function reservation( int $order_id ): ?CreditReservation;
}
