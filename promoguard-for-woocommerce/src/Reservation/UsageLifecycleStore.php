<?php
/**
 * Usage lifecycle persistence contract.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

/** Owns atomic consumption and release transitions for existing usage rows. */
interface UsageLifecycleStore {
	/**
	 * Consume a pending order/campaign reservation once.
	 *
	 * @param UsageTransition $transition Validated transition facts.
	 */
	public function consume( UsageTransition $transition ): bool;

	/**
	 * Release a pending order/campaign reservation once.
	 *
	 * @param UsageTransition $transition Validated transition facts.
	 */
	public function release( UsageTransition $transition ): bool;
}
