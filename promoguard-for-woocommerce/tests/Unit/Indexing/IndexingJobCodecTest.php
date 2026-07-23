<?php
/**
 * Historical indexing serialization tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Indexing;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PromoGuard\Indexing\IndexingJob;
use PromoGuard\Indexing\IndexingJobCodec;
use PromoGuard\Indexing\IndexingStatus;

/** Verifies persisted progress round trips without accepting malformed state. */
final class IndexingJobCodecTest extends TestCase {
	/** Valid job state round trips exactly. */
	public function test_round_trip_preserves_job_state(): void {
		$codec = new IndexingJobCodec();
		$job   = new IndexingJob(
			'123e4567-e89b-42d3-a456-426614174000',
			IndexingStatus::PAUSED,
			array( 7, 9 ),
			25,
			3,
			4,
			2,
			1,
			1,
			array(
				array(
					'order_id' => 8,
					'message'  => 'Order could not be loaded.',
				),
			),
			$this->time( '10:00:00' ),
			$this->time( '10:05:00' )
		);

		self::assertEquals( $job, $codec->decode( $codec->encode( $job ) ) );
	}

	/** Corrupt option types fail closed before reaching a worker. */
	public function test_invalid_persisted_types_are_rejected(): void {
		$codec         = new IndexingJobCodec();
		$state         = $codec->encode(
			new IndexingJob(
				'123e4567-e89b-42d3-a456-426614174000',
				IndexingStatus::QUEUED,
				array(),
				50,
				1,
				0,
				0,
				0,
				0,
				array(),
				$this->time( '10:00:00' ),
				$this->time( '10:00:00' )
			)
		);
		$state['page'] = '1';

		$this->expectException( InvalidArgumentException::class );
		$codec->decode( $state );
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
