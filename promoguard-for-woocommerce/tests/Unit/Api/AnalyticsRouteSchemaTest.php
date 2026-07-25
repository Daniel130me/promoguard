<?php
/**
 * Analytics route-schema tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use PromoGuard\Api\AnalyticsRouteSchema;

/** Covers closed analytics date and campaign filters. */
final class AnalyticsRouteSchemaTest extends TestCase {
	/** Summary dates require date-time strings and campaign IDs are positive. */
	public function test_summary_filters_are_closed_and_validated(): void {
		$schema = AnalyticsRouteSchema::summary();

		self::assertSame( 'date-time', $schema['starts_at_gmt']['format'] );
		self::assertSame( 'date-time', $schema['ends_at_gmt']['format'] );
		self::assertSame( 1, $schema['campaign_id']['minimum'] );
		self::assertFalse( $schema['campaign_id']['required'] );
	}
}
