<?php
/**
 * Reconciliation batch result.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reconciliation;

use InvalidArgumentException;

/** Immutable progress returned by one bounded reconciliation batch. */
final class ReconciliationBatch {
	/**
	 * Create a validated batch result.
	 *
	 * @param int      $processed   State rows inspected.
	 * @param int      $changed     State rows corrected.
	 * @param int|null $next_cursor Last processed state ID, or null when complete.
	 * @throws InvalidArgumentException When progress facts are inconsistent.
	 */
	public function __construct(
		public readonly int $processed,
		public readonly int $changed,
		public readonly ?int $next_cursor
	) {
		if (
			$processed < 0
			|| $changed < 0
			|| $changed > $processed
			|| ( null !== $next_cursor && $next_cursor < 1 )
		) {
			throw new InvalidArgumentException( 'Reconciliation batch progress is invalid.' );
		}
	}
}
