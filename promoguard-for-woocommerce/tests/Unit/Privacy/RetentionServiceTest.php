<?php
/**
 * Privacy retention service tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Privacy;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PromoGuard\Privacy\RetentionService;
use PromoGuard\Tests\Support\InMemoryRetentionStore;

/** Covers the bounded decision-log retention policy. */
final class RetentionServiceTest extends TestCase {
	/** Verify the cleanup boundary, batch limit, and result. */
	public function test_it_deletes_one_bounded_batch_after_the_retention_period(): void {
		$store          = new InMemoryRetentionStore();
		$store->deleted = 12;
		$now            = new DateTimeImmutable( '2026-07-25T12:00:00+00:00' );

		$deleted = ( new RetentionService( $store ) )->cleanup( $now );

		self::assertSame( 12, $deleted );
		self::assertSame( '2025-07-25T12:00:00+00:00', $store->boundary?->format( DATE_ATOM ) );
		self::assertSame( 250, $store->limit );
	}
}
