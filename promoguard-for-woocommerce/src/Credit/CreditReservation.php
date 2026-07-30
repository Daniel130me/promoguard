<?php
/**
 * Store-credit reservation state.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Credit;

use InvalidArgumentException;

/** Immutable snapshot of one order-scoped credit reservation. */
final class CreditReservation {
	public const RESERVED = 'reserved';
	public const CONSUMED = 'consumed';
	public const RELEASED = 'released';

	/**
	 * Capture validated persistence state.
	 *
	 * @param int    $id              Reservation ID.
	 * @param int    $user_id         WordPress user ID.
	 * @param int    $order_id        WooCommerce order ID.
	 * @param string $currency        Currency code.
	 * @param string $amount          Reserved amount.
	 * @param string $status          Reservation lifecycle status.
	 * @param string $consumed_amount Amount posted to the ledger.
	 * @param string $restored_amount Cumulative refund restoration.
	 * @throws InvalidArgumentException When lifecycle state is invalid.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $user_id,
		public readonly int $order_id,
		public readonly string $currency,
		public readonly string $amount,
		public readonly string $status,
		public readonly string $consumed_amount,
		public readonly string $restored_amount
	) {
		if (
			$id < 1 || $user_id < 1 || $order_id < 1
			|| ! in_array( $status, array( self::RESERVED, self::CONSUMED, self::RELEASED ), true )
		) {
			throw new InvalidArgumentException( 'Store-credit reservation state is invalid.' );
		}
	}
}
