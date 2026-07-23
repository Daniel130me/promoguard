<?php
/**
 * Reservation expiration service tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Reservation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PromoGuard\Reservation\ExpirationService;
use PromoGuard\Tests\Support\InMemoryExpirationStore;

/** Verifies bounded GMT expiration delegation. */
final class ExpirationServiceTest extends TestCase {
	/** A valid batch preserves its boundary and reports the changed count. */
	public function test_releases_one_bounded_batch(): void {
		$store           = new InMemoryExpirationStore();
		$store->released = 7;
		$boundary        = new DateTimeImmutable( '2026-07-23 14:00:00', new DateTimeZone( 'UTC' ) );

		$result = ( new ExpirationService( $store ) )->release_batch( $boundary, 25 );

		self::assertSame( 7, $result );
		self::assertSame( $boundary, $store->expired_before_gmt );
		self::assertSame( 25, $store->candidate_limit );
	}

	/**
	 * Unsafe batch sizes fail before persistence.
	 *
	 * @param int $batch_size Invalid batch size.
	 */
	#[DataProvider( 'invalid_batch_sizes' )]
	public function test_rejects_unsafe_batch_sizes( int $batch_size ): void {
		$store = new InMemoryExpirationStore();

		$this->expectException( InvalidArgumentException::class );
		( new ExpirationService( $store ) )->release_batch(
			new DateTimeImmutable( '2026-07-23 14:00:00', new DateTimeZone( 'UTC' ) ),
			$batch_size
		);
	}

	/**
	 * Return invalid batch sizes.
	 *
	 * @return array<string,array{int}>
	 */
	public static function invalid_batch_sizes(): array {
		return array(
			'empty'    => array( 0 ),
			'negative' => array( -1 ),
			'too-high' => array( ExpirationService::MAX_BATCH_SIZE + 1 ),
		);
	}

	/** Non-GMT boundaries fail before persistence. */
	public function test_rejects_non_gmt_boundary(): void {
		$store = new InMemoryExpirationStore();

		$this->expectException( InvalidArgumentException::class );
		( new ExpirationService( $store ) )->release_batch(
			new DateTimeImmutable( '2026-07-23 15:00:00', new DateTimeZone( 'Africa/Lagos' ) )
		);
	}
}
