<?php
/**
 * Atomic reservation persistence contract.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

/** Owns the short transaction that protects campaign usage counters. */
interface ReservationStore {
	/**
	 * Reserve one order/campaign usage atomically.
	 *
	 * Equivalent pending or consumed usages must be returned idempotently.
	 *
	 * @param ReservationRequest $request Validated reservation facts.
	 */
	public function reserve( ReservationRequest $request ): ReservationResult;
}
