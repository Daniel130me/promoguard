<?php
/**
 * Historical indexing batch result.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Indexing;

use InvalidArgumentException;

/** Immutable progress returned after one bounded order page. */
final class IndexingBatch {
	/**
	 * Create a validated batch result.
	 *
	 * @param int                                           $processed Number of orders inspected.
	 * @param int                                           $imported  Number of new usage rows.
	 * @param int                                           $skipped   Number of orders that required no import.
	 * @param array<int,array{order_id:int,message:string}> $errors    Safe per-order failures.
	 * @param bool                                          $has_more  Whether another page should run.
	 * @throws InvalidArgumentException When progress is inconsistent.
	 */
	public function __construct(
		public readonly int $processed,
		public readonly int $imported,
		public readonly int $skipped,
		public readonly array $errors,
		public readonly bool $has_more
	) {
		if ( $processed < 0 || $imported < 0 || $skipped < 0 || $processed !== $imported + $skipped + count( $errors ) ) {
			throw new InvalidArgumentException( 'Historical indexing batch progress is invalid.' );
		}

		foreach ( $errors as $error ) {
			if (
				$error['order_id'] < 1
				|| '' === trim( $error['message'] )
				|| strlen( $error['message'] ) > IndexingJob::MAX_ERROR_LENGTH
			) {
				throw new InvalidArgumentException( 'Historical indexing batch error is invalid.' );
			}
		}
	}
}
