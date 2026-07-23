<?php
/**
 * Reservation service tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Reservation;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use PromoGuard\Reservation\ReservationRequest;
use PromoGuard\Reservation\ReservationResult;
use PromoGuard\Reservation\ReservationService;
use PromoGuard\Reservation\RetryableReservationException;
use PromoGuard\Tests\Support\InMemoryReservationStore;
use RuntimeException;

/** Verifies bounded contention retries and fail-fast persistence behavior. */
final class ReservationServiceTest extends TestCase {
	/** Transactional contention is retried until the atomic store succeeds. */
	public function test_retryable_failures_are_bounded_and_retried(): void {
		$expected = new ReservationResult( 8, ReservationResult::PENDING, true );
		$store    = new InMemoryReservationStore(
			array(
				new RetryableReservationException( 'Deadlock.' ),
				new RetryableReservationException( 'Lock timeout.' ),
				$expected,
			)
		);

		$result = ( new ReservationService( $store ) )->reserve( self::request() );

		self::assertSame( $expected, $result );
		self::assertSame( 3, $store->calls );
	}

	/** Exhausted contention retries fail protected checkout safely. */
	public function test_retryable_failure_is_rethrown_after_three_attempts(): void {
		$store = new InMemoryReservationStore(
			array(
				new RetryableReservationException( 'Deadlock 1.' ),
				new RetryableReservationException( 'Deadlock 2.' ),
				new RetryableReservationException( 'Deadlock 3.' ),
			)
		);

		try {
			( new ReservationService( $store ) )->reserve( self::request() );
			self::fail( 'Expected retry exhaustion.' );
		} catch ( RetryableReservationException $exception ) {
			self::assertSame( 'Deadlock 3.', $exception->getMessage() );
			self::assertSame( 3, $store->calls );
		}
	}

	/** Non-contention failures are not repeated. */
	public function test_non_retryable_failure_fails_immediately(): void {
		$store   = new InMemoryReservationStore( array( new RuntimeException( 'Insert failed.' ) ) );
		$service = new ReservationService( $store );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Insert failed.' );

		try {
			$service->reserve( self::request() );
		} finally {
			self::assertSame( 1, $store->calls );
		}
	}

	/** Build a stable reservation request. */
	private static function request(): ReservationRequest {
		$gmt = new DateTimeZone( 'UTC' );

		return new ReservationRequest(
			uuid: '123e4567-e89b-42d3-a456-426614174000',
			campaign_id: 5,
			promotion_id: 9,
			customer_id: 12,
			order_id: 101,
			coupon_id: 77,
			coupon_code: 'WELCOME10',
			currency: 'USD',
			reservation_key: str_repeat( 'a', 64 ),
			maximum_uses: 1,
			reserved_at_gmt: new DateTimeImmutable( '2026-07-23 12:00:00', $gmt ),
			reserved_until_gmt: new DateTimeImmutable( '2026-07-23 12:30:00', $gmt )
		);
	}
}
