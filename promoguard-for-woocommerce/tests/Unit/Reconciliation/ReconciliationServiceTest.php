<?php
/**
 * Reconciliation service tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Reconciliation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PromoGuard\Reconciliation\ReconciliationBatch;
use PromoGuard\Reconciliation\ReconciliationService;
use PromoGuard\Tests\Support\InMemoryReconciliationStore;

/** Verifies bounded reconciliation progress and input validation. */
final class ReconciliationServiceTest extends TestCase {
	/** Default batch size and cursor are forwarded to persistence. */
	public function test_default_batch_is_bounded_and_returns_progress(): void {
		$store         = new InMemoryReconciliationStore();
		$store->result = new ReconciliationBatch( 50, 3, 75 );
		$service       = new ReconciliationService( $store );

		self::assertSame( $store->result, $service->reconcile_batch( self::now() ) );
		self::assertSame(
			array(
				array(
					'after_id' => 0,
					'limit'    => ReconciliationService::DEFAULT_BATCH_SIZE,
				),
			),
			$store->requests
		);
	}

	/** Explicit safe cursor and limit values are forwarded unchanged. */
	public function test_explicit_cursor_and_limit_are_supported(): void {
		$store = new InMemoryReconciliationStore();

		( new ReconciliationService( $store ) )->reconcile_batch( self::now(), 75, 25 );

		self::assertSame(
			array(
				array(
					'after_id' => 75,
					'limit'    => 25,
				),
			),
			$store->requests
		);
	}

	/**
	 * Invalid boundaries and sizes fail before persistence.
	 *
	 * @param DateTimeImmutable $time     Candidate reconciliation time.
	 * @param int               $after_id Candidate cursor.
	 * @param int               $limit    Candidate batch size.
	 */
	#[DataProvider( 'invalid_inputs' )]
	public function test_invalid_batch_input_is_rejected(
		DateTimeImmutable $time,
		int $after_id,
		int $limit
	): void {
		$store = new InMemoryReconciliationStore();
		$this->expectException( InvalidArgumentException::class );

		( new ReconciliationService( $store ) )->reconcile_batch( $time, $after_id, $limit );
		self::assertSame( array(), $store->requests );
	}

	/**
	 * Provide invalid reconciliation inputs.
	 *
	 * @return array<string,array{DateTimeImmutable,int,int}>
	 */
	public static function invalid_inputs(): array {
		return array(
			'non-GMT time'    => array(
				new DateTimeImmutable( '2026-07-23 12:00:00', new DateTimeZone( 'Africa/Lagos' ) ),
				0,
				50,
			),
			'negative cursor' => array( self::now(), -1, 50 ),
			'zero limit'      => array( self::now(), 0, 0 ),
			'oversized limit' => array( self::now(), 0, ReconciliationService::MAXIMUM_BATCH_SIZE + 1 ),
		);
	}

	/** Return one stable GMT boundary. */
	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable( '2026-07-23 12:00:00', new DateTimeZone( 'UTC' ) );
	}
}
