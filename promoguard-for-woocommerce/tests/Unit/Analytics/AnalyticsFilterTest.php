<?php
/**
 * Analytics filter tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;
use PromoGuard\Analytics\AnalyticsFilter;

/** Covers bounded GMT analytics periods and campaign filters. */
final class AnalyticsFilterTest extends TestCase {
	/** A valid period exposes database-safe timestamps. */
	public function test_valid_period_is_exposed_as_half_open_database_range(): void {
		$filter = new AnalyticsFilter(
			new DateTimeImmutable( '2026-01-01 00:00:00', new DateTimeZone( 'UTC' ) ),
			new DateTimeImmutable( '2026-02-01 00:00:00', new DateTimeZone( 'UTC' ) ),
			42
		);

		self::assertSame( '2026-01-01 00:00:00', $filter->database_start() );
		self::assertSame( '2026-02-01 00:00:00', $filter->database_end() );
		self::assertSame( 42, $filter->campaign_id );
	}

	/** Invalid chronology, oversized periods, local times, and IDs fail early. */
	public function test_invalid_filters_are_rejected(): void {
		$gmt   = new DateTimeZone( 'UTC' );
		$cases = array(
			array( new DateTimeImmutable( '2026-02-01', $gmt ), new DateTimeImmutable( '2026-01-01', $gmt ), null ),
			array( new DateTimeImmutable( '2025-01-01', $gmt ), new DateTimeImmutable( '2026-01-03', $gmt ), null ),
			array( new DateTimeImmutable( '2026-01-01', new DateTimeZone( 'Africa/Lagos' ) ), new DateTimeImmutable( '2026-02-01', $gmt ), null ),
			array( new DateTimeImmutable( '2026-01-01', $gmt ), new DateTimeImmutable( '2026-02-01', $gmt ), 0 ),
		);

		foreach ( $cases as $case ) {
			try {
				new AnalyticsFilter( $case[0], $case[1], $case[2] );
				self::fail( 'Expected invalid analytics filters to be rejected.' );
			} catch ( DomainException $exception ) {
				self::assertNotSame( '', $exception->getMessage() );
			}
		}
	}
}
