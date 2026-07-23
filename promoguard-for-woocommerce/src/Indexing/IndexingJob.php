<?php
/**
 * Historical indexing job state.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Indexing;

use DateTimeImmutable;
use InvalidArgumentException;

/** Immutable, serializable progress for one full or targeted indexing job. */
final class IndexingJob {
	public const DEFAULT_BATCH_SIZE = 50;
	public const MAXIMUM_BATCH_SIZE = 100;
	public const MAXIMUM_TARGETS    = 100;
	public const MAXIMUM_ERRORS     = 100;
	public const MAX_ERROR_LENGTH   = 500;

	/**
	 * Create a validated indexing job snapshot.
	 *
	 * @param string                                        $id               Stable job UUID.
	 * @param string                                        $status           Current state-machine status.
	 * @param int[]                                         $target_order_ids Empty for a full historical scan.
	 * @param int                                           $batch_size       Maximum orders per worker action.
	 * @param int                                           $page             Next WooCommerce result page.
	 * @param int                                           $processed        Orders inspected so far.
	 * @param int                                           $imported         New usage rows created.
	 * @param int                                           $skipped          Orders requiring no import.
	 * @param int                                           $failed           Orders that failed safely.
	 * @param array<int,array{order_id:int,message:string}> $errors           Bounded safe error history.
	 * @param DateTimeImmutable                             $created_at_gmt   Creation time.
	 * @param DateTimeImmutable                             $updated_at_gmt   Last transition time.
	 * @throws InvalidArgumentException When persisted job state is invalid.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $status,
		public readonly array $target_order_ids,
		public readonly int $batch_size,
		public readonly int $page,
		public readonly int $processed,
		public readonly int $imported,
		public readonly int $skipped,
		public readonly int $failed,
		public readonly array $errors,
		public readonly DateTimeImmutable $created_at_gmt,
		public readonly DateTimeImmutable $updated_at_gmt
	) {
		$this->validate();
	}

	/** Whether this job indexes an explicit bounded order set. */
	public function is_targeted(): bool {
		return array() !== $this->target_order_ids;
	}

	/**
	 * Validate persisted progress before it reaches a worker.
	 *
	 * @throws InvalidArgumentException When job state is inconsistent.
	 */
	private function validate(): void {
		IndexingStatus::assert_valid( $this->status );

		if (
			1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $this->id )
			|| $this->batch_size < 1
			|| $this->batch_size > self::MAXIMUM_BATCH_SIZE
			|| $this->page < 1
			|| $this->processed < 0
			|| $this->imported < 0
			|| $this->skipped < 0
			|| $this->failed < 0
			|| $this->processed !== $this->imported + $this->skipped + $this->failed
			|| count( $this->target_order_ids ) > self::MAXIMUM_TARGETS
			|| count( $this->errors ) > self::MAXIMUM_ERRORS
			|| 0 !== $this->created_at_gmt->getOffset()
			|| 0 !== $this->updated_at_gmt->getOffset()
			|| $this->updated_at_gmt < $this->created_at_gmt
		) {
			throw new InvalidArgumentException( 'Historical indexing job state is invalid.' );
		}

		$targets = array_values( array_unique( $this->target_order_ids ) );
		if ( $targets !== $this->target_order_ids ) {
			throw new InvalidArgumentException( 'Target order IDs must be unique and ordered.' );
		}

		foreach ( $targets as $order_id ) {
			if ( $order_id < 1 ) {
				throw new InvalidArgumentException( 'Target order IDs must be positive.' );
			}
		}

		foreach ( $this->errors as $error ) {
			if (
				$error['order_id'] < 0
				|| '' === trim( $error['message'] )
				|| strlen( $error['message'] ) > self::MAX_ERROR_LENGTH
			) {
				throw new InvalidArgumentException( 'Historical indexing job error is invalid.' );
			}
		}
	}
}
