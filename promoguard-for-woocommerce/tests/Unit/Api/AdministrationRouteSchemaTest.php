<?php
/**
 * Administration route-schema tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use PromoGuard\Api\AdministrationRouteSchema;
use PromoGuard\Reservation\UsageStatus;

/** Covers bounded operational-history request schemas. */
final class AdministrationRouteSchemaTest extends TestCase {
	/** History endpoints cap pages and require positive exact identifiers. */
	public function test_history_pagination_and_identifiers_are_bounded(): void {
		$schema = AdministrationRouteSchema::usages();

		self::assertSame( 1, $schema['page']['minimum'] );
		self::assertSame( 20, $schema['per_page']['default'] );
		self::assertSame( 100, $schema['per_page']['maximum'] );
		self::assertSame( 1, $schema['campaign_id']['minimum'] );
		self::assertSame( 1, $schema['order_id']['minimum'] );
	}

	/** Usage history accepts only persisted lifecycle states. */
	public function test_usage_status_filter_is_closed(): void {
		$schema = AdministrationRouteSchema::usages();

		self::assertSame(
			array(
				UsageStatus::PENDING,
				UsageStatus::CONSUMED,
				UsageStatus::RELEASED,
				UsageStatus::RESTORED,
			),
			$schema['status']['enum']
		);
	}

	/** Decision reasons are exact, sanitized, and capped to the schema column. */
	public function test_decision_reason_filter_is_sanitized_and_capped(): void {
		$schema = AdministrationRouteSchema::decisions();

		self::assertSame( 'sanitize_key', $schema['reason']['sanitize_callback'] );
		self::assertSame( 64, $schema['reason']['maxLength'] );
	}
}
