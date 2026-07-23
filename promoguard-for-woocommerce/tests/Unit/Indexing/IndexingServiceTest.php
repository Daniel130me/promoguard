<?php
/**
 * Historical indexing lifecycle tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Indexing;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;
use PromoGuard\Indexing\IndexingBatch;
use PromoGuard\Indexing\IndexingService;
use PromoGuard\Indexing\IndexingStatus;
use PromoGuard\Tests\Support\InMemoryIndexingJobStore;

/** Verifies resumable, idempotent job-state transitions. */
final class IndexingServiceTest extends TestCase {
	private const JOB_ID = '123e4567-e89b-42d3-a456-426614174000';

	/** A full job advances pages and aggregates exact progress. */
	public function test_full_job_records_progress_and_completes(): void {
		$store   = new InMemoryIndexingJobStore();
		$service = new IndexingService( $store );
		$started = $service->start( self::JOB_ID, $this->time( '10:00:00' ) );

		self::assertSame( IndexingStatus::QUEUED, $started->status );
		self::assertFalse( $started->is_targeted() );

		$service->claim( self::JOB_ID, $this->time( '10:01:00' ) );
		$queued = $service->record_batch(
			self::JOB_ID,
			new IndexingBatch( 2, 1, 1, array(), true ),
			$this->time( '10:02:00' )
		);
		self::assertSame( IndexingStatus::QUEUED, $queued->status );
		self::assertSame( 2, $queued->page );

		$service->claim( self::JOB_ID, $this->time( '10:03:00' ) );
		$complete = $service->record_batch(
			self::JOB_ID,
			new IndexingBatch(
				1,
				0,
				0,
				array(
					array(
						'order_id' => 91,
						'message'  => 'Order could not be loaded.',
					),
				),
				false
			),
			$this->time( '10:04:00' )
		);

		self::assertSame( IndexingStatus::COMPLETED, $complete->status );
		self::assertSame( 3, $complete->processed );
		self::assertSame( 1, $complete->imported );
		self::assertSame( 1, $complete->skipped );
		self::assertSame( 1, $complete->failed );
	}

	/** Targeted jobs normalize duplicate and unordered IDs. */
	public function test_target_ids_are_normalized_and_bounded(): void {
		$service = new IndexingService( new InMemoryIndexingJobStore() );
		$job     = $service->start( self::JOB_ID, $this->time( '10:00:00' ), array( 9, 3, 9 ) );

		self::assertTrue( $job->is_targeted() );
		self::assertSame( array( 3, 9 ), $job->target_order_ids );
	}

	/** Operational controls preserve or reset progress intentionally. */
	public function test_pause_resume_failure_retry_and_restart_preserve_safe_progress(): void {
		$service = new IndexingService( new InMemoryIndexingJobStore() );
		$service->start( self::JOB_ID, $this->time( '10:00:00' ) );
		$paused = $service->pause( $this->time( '10:01:00' ) );
		self::assertSame( IndexingStatus::PAUSED, $paused->status );

		$resumed = $service->resume( $this->time( '10:02:00' ) );
		self::assertSame( IndexingStatus::QUEUED, $resumed->status );

		$service->claim( self::JOB_ID, $this->time( '10:03:00' ) );
		$failed = $service->fail( self::JOB_ID, 'Temporary worker failure.', $this->time( '10:04:00' ) );
		self::assertSame( IndexingStatus::FAILED, $failed->status );

		$retry = $service->retry( $this->time( '10:05:00' ) );
		self::assertSame( IndexingStatus::QUEUED, $retry->status );

		$service->pause( $this->time( '10:06:00' ) );
		$restart = $service->restart( $this->time( '10:07:00' ) );
		self::assertSame( 0, $restart->processed );
		self::assertSame( 1, $restart->page );
		self::assertSame( array(), $restart->errors );
	}

	/** A pause during processing keeps the finished batch and stops continuation. */
	public function test_in_flight_batch_records_progress_without_overriding_pause(): void {
		$service = new IndexingService( new InMemoryIndexingJobStore() );
		$service->start( self::JOB_ID, $this->time( '10:00:00' ) );
		$service->claim( self::JOB_ID, $this->time( '10:01:00' ) );
		$service->pause( $this->time( '10:02:00' ) );

		$paused = $service->record_batch(
			self::JOB_ID,
			new IndexingBatch( 1, 1, 0, array(), true ),
			$this->time( '10:03:00' )
		);

		self::assertSame( IndexingStatus::PAUSED, $paused->status );
		self::assertSame( 1, $paused->processed );
		self::assertSame( 2, $paused->page );
	}
	/** Completed order failures retry as one normalized targeted job. */
	public function test_completed_order_errors_retry_only_failed_orders(): void {
		$service = new IndexingService( new InMemoryIndexingJobStore() );
		$service->start( self::JOB_ID, $this->time( '10:00:00' ) );
		$service->claim( self::JOB_ID, $this->time( '10:01:00' ) );
		$service->record_batch(
			self::JOB_ID,
			new IndexingBatch(
				2,
				0,
				0,
				array(
					array(
						'order_id' => 90,
						'message'  => 'Order failed.',
					),
					array(
						'order_id' => 80,
						'message'  => 'Order failed.',
					),
				),
				false
			),
			$this->time( '10:02:00' )
		);

		$retry = $service->retry( $this->time( '10:03:00' ) );

		self::assertSame( array( 80, 90 ), $retry->target_order_ids );
		self::assertSame( 0, $retry->processed );
		self::assertSame( array(), $retry->errors );
	}
	/** Concurrent active job creation is rejected. */
	public function test_second_active_job_is_rejected(): void {
		$service = new IndexingService( new InMemoryIndexingJobStore() );
		$service->start( self::JOB_ID, $this->time( '10:00:00' ) );

		$this->expectException( DomainException::class );
		$service->start( '123e4567-e89b-42d3-a456-426614174001', $this->time( '10:01:00' ) );
	}

	/** Superseded scheduler actions are harmless no-ops. */
	public function test_stale_job_cannot_be_claimed(): void {
		$service = new IndexingService( new InMemoryIndexingJobStore() );
		$service->start( self::JOB_ID, $this->time( '10:00:00' ) );

		self::assertNull(
			$service->claim( '123e4567-e89b-42d3-a456-426614174001', $this->time( '10:01:00' ) )
		);
	}
	/**
	 * Build one deterministic GMT timestamp.
	 *
	 * @param string $time Time of day.
	 */
	private function time( string $time ): DateTimeImmutable {
		return new DateTimeImmutable( '2026-07-23 ' . $time, new DateTimeZone( 'UTC' ) );
	}
}
