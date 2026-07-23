<?php
/**
 * Historical indexing job lifecycle.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Indexing;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

/** Applies validated, transport-neutral transitions to one persisted job. */
final class IndexingService {
	/**
	 * Configure job lifecycle persistence.
	 *
	 * @param IndexingJobStore $store Job-state persistence.
	 */
	public function __construct( private readonly IndexingJobStore $store ) {}

	/** Return the current or most recently completed job. */
	public function current(): ?IndexingJob {
		return $this->store->current();
	}

	/**
	 * Start a full scan or a bounded targeted rebuild.
	 *
	 * @param string            $job_id          Generated version 4 UUID.
	 * @param DateTimeImmutable $now_gmt         Transition time.
	 * @param int[]             $target_order_ids Optional explicit order IDs.
	 * @param int               $batch_size      Maximum orders per action.
	 * @throws DomainException When another job is active.
	 */
	public function start(
		string $job_id,
		DateTimeImmutable $now_gmt,
		array $target_order_ids = array(),
		int $batch_size = IndexingJob::DEFAULT_BATCH_SIZE
	): IndexingJob {
		$current = $this->store->current();
		if ( null !== $current && in_array( $current->status, array( IndexingStatus::QUEUED, IndexingStatus::RUNNING, IndexingStatus::PAUSED ), true ) ) {
			throw new DomainException( 'An historical indexing job is already active.' );
		}

		$targets = array_values( array_unique( array_map( 'intval', $target_order_ids ) ) );
		sort( $targets, SORT_NUMERIC );
		$job = new IndexingJob(
			$job_id,
			IndexingStatus::QUEUED,
			$targets,
			$batch_size,
			1,
			0,
			0,
			0,
			0,
			array(),
			$now_gmt,
			$now_gmt
		);
		$this->store->save( $job );
		return $job;
	}

	/**
	 * Claim a queued job immediately before processing one batch.
	 *
	 * @param string            $job_id  Expected current job UUID.
	 * @param DateTimeImmutable $now_gmt Transition time.
	 * @throws DomainException When the requested job is missing or stale.
	 */
	public function claim( string $job_id, DateTimeImmutable $now_gmt ): ?IndexingJob {
		$job = $this->require_job( $job_id );
		if ( IndexingStatus::QUEUED !== $job->status ) {
			return null;
		}

		return $this->save_copy( $job, IndexingStatus::RUNNING, $now_gmt );
	}

	/**
	 * Pause a queued or running job; an in-flight batch may finish first.
	 *
	 * @param DateTimeImmutable $now_gmt Transition time.
	 * @throws DomainException When no active job exists.
	 */
	public function pause( DateTimeImmutable $now_gmt ): IndexingJob {
		$job = $this->require_current();
		if ( ! in_array( $job->status, array( IndexingStatus::QUEUED, IndexingStatus::RUNNING ), true ) ) {
			throw new DomainException( 'Only an active indexing job can be paused.' );
		}

		return $this->save_copy( $job, IndexingStatus::PAUSED, $now_gmt );
	}

	/**
	 * Resume a paused job at its next unprocessed page.
	 *
	 * @param DateTimeImmutable $now_gmt Transition time.
	 * @throws DomainException When no paused job exists.
	 */
	public function resume( DateTimeImmutable $now_gmt ): IndexingJob {
		$job = $this->require_current();
		if ( IndexingStatus::PAUSED !== $job->status ) {
			throw new DomainException( 'Only a paused indexing job can be resumed.' );
		}

		return $this->save_copy( $job, IndexingStatus::QUEUED, $now_gmt );
	}

	/**
	 * Retry a failed job without discarding completed progress.
	 *
	 * @param DateTimeImmutable $now_gmt Transition time.
	 * @throws DomainException When no failed job exists.
	 */
	public function retry( DateTimeImmutable $now_gmt ): IndexingJob {
		$job = $this->require_current();
		if ( IndexingStatus::FAILED === $job->status ) {
			return $this->save_copy( $job, IndexingStatus::QUEUED, $now_gmt );
		}

		if ( IndexingStatus::COMPLETED !== $job->status || 0 === $job->failed ) {
			throw new DomainException( 'Only a failed job or completed job with order errors can be retried.' );
		}

		$targets = array_values(
			array_unique(
				array_filter(
					array_column( $job->errors, 'order_id' ),
					static fn( int $order_id ): bool => $order_id > 0
				)
			)
		);
		sort( $targets, SORT_NUMERIC );
		if ( array() === $targets ) {
			throw new DomainException( 'The indexing failure has no retryable order IDs.' );
		}

		$retry = new IndexingJob(
			$job->id,
			IndexingStatus::QUEUED,
			$targets,
			$job->batch_size,
			1,
			0,
			0,
			0,
			0,
			array(),
			$job->created_at_gmt,
			$now_gmt
		);
		$this->store->save( $retry );
		return $retry;
	}
	/**
	 * Restart a terminal or paused job from its first page with empty progress.
	 *
	 * @param DateTimeImmutable $now_gmt Transition time.
	 * @throws DomainException When a job is active or missing.
	 */
	public function restart( DateTimeImmutable $now_gmt ): IndexingJob {
		$job = $this->require_current();
		if ( IndexingStatus::RUNNING === $job->status || IndexingStatus::QUEUED === $job->status ) {
			throw new DomainException( 'An active indexing job cannot be restarted.' );
		}

		$restarted = new IndexingJob(
			$job->id,
			IndexingStatus::QUEUED,
			$job->target_order_ids,
			$job->batch_size,
			1,
			0,
			0,
			0,
			0,
			array(),
			$job->created_at_gmt,
			$now_gmt
		);
		$this->store->save( $restarted );
		return $restarted;
	}

	/**
	 * Persist one successful worker result and queue or complete the job.
	 *
	 * @param string            $job_id  Expected current job UUID.
	 * @param IndexingBatch     $batch   Completed batch progress.
	 * @param DateTimeImmutable $now_gmt Transition time.
	 * @throws DomainException When the requested job is not running.
	 */
	public function record_batch( string $job_id, IndexingBatch $batch, DateTimeImmutable $now_gmt ): IndexingJob {
		$job = $this->require_job( $job_id );
		if ( ! in_array( $job->status, array( IndexingStatus::RUNNING, IndexingStatus::PAUSED ), true ) ) {
			throw new DomainException( 'Only a running indexing job can record progress.' );
		}

		$paused = IndexingStatus::PAUSED === $job->status;
		$errors = array_slice(
			array_merge( $job->errors, $batch->errors ),
			-IndexingJob::MAXIMUM_ERRORS
		);
		$next   = new IndexingJob(
			$job->id,
			$batch->has_more && $paused ? IndexingStatus::PAUSED : ( $batch->has_more ? IndexingStatus::QUEUED : IndexingStatus::COMPLETED ),
			$job->target_order_ids,
			$job->batch_size,
			$batch->has_more ? $job->page + 1 : $job->page,
			$job->processed + $batch->processed,
			$job->imported + $batch->imported,
			$job->skipped + $batch->skipped,
			$job->failed + count( $batch->errors ),
			$errors,
			$job->created_at_gmt,
			$now_gmt
		);
		$this->store->save( $next );
		return $next;
	}

	/**
	 * Mark a claimed job failed with one safe operational error.
	 *
	 * @param string            $job_id  Expected current job UUID.
	 * @param string            $message Safe failure summary.
	 * @param DateTimeImmutable $now_gmt Transition time.
	 * @throws DomainException When the requested job is not running.
	 * @throws InvalidArgumentException When the message is empty.
	 */
	public function fail( string $job_id, string $message, DateTimeImmutable $now_gmt ): IndexingJob {
		$job = $this->require_job( $job_id );
		if ( ! in_array( $job->status, array( IndexingStatus::RUNNING, IndexingStatus::PAUSED ), true ) ) {
			throw new DomainException( 'Only a running indexing job can fail.' );
		}

		$message = trim( $message );
		if ( '' === $message ) {
			throw new InvalidArgumentException( 'Indexing failure message cannot be empty.' );
		}
		$message = substr( $message, 0, IndexingJob::MAX_ERROR_LENGTH );

		$errors = array_slice(
			array_merge(
				$job->errors,
				array(
					array(
						'order_id' => 0,
						'message'  => $message,
					),
				)
			),
			-IndexingJob::MAXIMUM_ERRORS
		);
		$failed = new IndexingJob(
			$job->id,
			IndexingStatus::FAILED,
			$job->target_order_ids,
			$job->batch_size,
			$job->page,
			$job->processed,
			$job->imported,
			$job->skipped,
			$job->failed,
			$errors,
			$job->created_at_gmt,
			$now_gmt
		);
		$this->store->save( $failed );
		return $failed;
	}

	/**
	 * Require the current persisted job.
	 *
	 * @throws DomainException When no job exists.
	 */
	private function require_current(): IndexingJob {
		$job = $this->store->current();
		if ( null === $job ) {
			throw new DomainException( 'No historical indexing job exists.' );
		}
		return $job;
	}

	/**
	 * Require the expected current job.
	 *
	 * @param string $job_id Expected job UUID.
	 * @throws DomainException When the current job is missing or stale.
	 */
	private function require_job( string $job_id ): IndexingJob {
		$job = $this->require_current();
		if ( $job->id !== $job_id ) {
			throw new DomainException( 'Historical indexing job is stale.' );
		}
		return $job;
	}

	/**
	 * Persist a status-only job transition.
	 *
	 * @param IndexingJob       $job     Current job.
	 * @param string            $status  Destination status.
	 * @param DateTimeImmutable $now_gmt Transition time.
	 */
	private function save_copy( IndexingJob $job, string $status, DateTimeImmutable $now_gmt ): IndexingJob {
		$copy = new IndexingJob(
			$job->id,
			$status,
			$job->target_order_ids,
			$job->batch_size,
			$job->page,
			$job->processed,
			$job->imported,
			$job->skipped,
			$job->failed,
			$job->errors,
			$job->created_at_gmt,
			$now_gmt
		);
		$this->store->save( $copy );
		return $copy;
	}
}
