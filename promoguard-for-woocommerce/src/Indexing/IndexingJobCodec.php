<?php
/**
 * Historical indexing job serialization.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Indexing;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/** Converts validated job state to and from a WordPress-option-safe array. */
final class IndexingJobCodec {
	private const DATE_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Encode one validated job snapshot.
	 *
	 * @param IndexingJob $job Job to serialize.
	 * @return array<string,mixed>
	 */
	public function encode( IndexingJob $job ): array {
		return array(
			'id'               => $job->id,
			'status'           => $job->status,
			'target_order_ids' => $job->target_order_ids,
			'batch_size'       => $job->batch_size,
			'page'             => $job->page,
			'processed'        => $job->processed,
			'imported'         => $job->imported,
			'skipped'          => $job->skipped,
			'failed'           => $job->failed,
			'errors'           => $job->errors,
			'created_at_gmt'   => $job->created_at_gmt->format( self::DATE_FORMAT ),
			'updated_at_gmt'   => $job->updated_at_gmt->format( self::DATE_FORMAT ),
		);
	}

	/**
	 * Decode and revalidate persisted job state.
	 *
	 * @param array<string,mixed> $state Persisted option value.
	 * @throws InvalidArgumentException When state is missing or malformed.
	 */
	public function decode( array $state ): IndexingJob {
		$required = array(
			'id',
			'status',
			'target_order_ids',
			'batch_size',
			'page',
			'processed',
			'imported',
			'skipped',
			'failed',
			'errors',
			'created_at_gmt',
			'updated_at_gmt',
		);
		if ( array_diff( $required, array_keys( $state ) ) ) {
			throw new InvalidArgumentException( 'Stored historical indexing job is incomplete.' );
		}

		if (
			! is_string( $state['id'] )
			|| ! is_string( $state['status'] )
			|| ! is_array( $state['target_order_ids'] )
			|| ! is_int( $state['batch_size'] )
			|| ! is_int( $state['page'] )
			|| ! is_int( $state['processed'] )
			|| ! is_int( $state['imported'] )
			|| ! is_int( $state['skipped'] )
			|| ! is_int( $state['failed'] )
			|| ! is_array( $state['errors'] )
			|| ! is_string( $state['created_at_gmt'] )
			|| ! is_string( $state['updated_at_gmt'] )
		) {
			throw new InvalidArgumentException( 'Stored historical indexing job types are invalid.' );
		}

		$targets = $this->decode_targets( $state['target_order_ids'] );
		$errors  = $this->decode_errors( $state['errors'] );

		try {
			$gmt     = new DateTimeZone( 'UTC' );
			$created = new DateTimeImmutable( $state['created_at_gmt'], $gmt );
			$updated = new DateTimeImmutable( $state['updated_at_gmt'], $gmt );
		} catch ( Throwable $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is diagnostic context and is never rendered.
			throw new InvalidArgumentException( 'Stored historical indexing timestamps are invalid.', 0, $exception );
		}

		return new IndexingJob(
			$state['id'],
			$state['status'],
			$targets,
			$state['batch_size'],
			$state['page'],
			$state['processed'],
			$state['imported'],
			$state['skipped'],
			$state['failed'],
			$errors,
			$created,
			$updated
		);
	}

	/**
	 * Validate target IDs without silently casting corrupted option values.
	 *
	 * @param array<mixed> $targets Persisted targets.
	 * @return int[]
	 * @throws InvalidArgumentException When a target is not an integer.
	 */
	private function decode_targets( array $targets ): array {
		foreach ( $targets as $target ) {
			if ( ! is_int( $target ) ) {
				throw new InvalidArgumentException( 'Stored target order IDs are invalid.' );
			}
		}

		return array_values( $targets );
	}

	/**
	 * Validate safe error summaries.
	 *
	 * @param array<mixed> $errors Persisted errors.
	 * @return array<int,array{order_id:int,message:string}>
	 * @throws InvalidArgumentException When an error entry is malformed.
	 */
	private function decode_errors( array $errors ): array {
		$decoded = array();
		foreach ( $errors as $error ) {
			if (
				! is_array( $error )
				|| ! isset( $error['order_id'], $error['message'] )
				|| ! is_int( $error['order_id'] )
				|| ! is_string( $error['message'] )
			) {
				throw new InvalidArgumentException( 'Stored historical indexing errors are invalid.' );
			}
			$decoded[] = array(
				'order_id' => $error['order_id'],
				'message'  => $error['message'],
			);
		}

		return $decoded;
	}
}
