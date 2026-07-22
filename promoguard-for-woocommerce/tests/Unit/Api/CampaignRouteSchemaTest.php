<?php
/**
 * Campaign route-schema tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use PromoGuard\Api\CampaignRouteSchema;
use PromoGuard\Campaign\CampaignStatus;
use PromoGuard\Promotion\CouponDraft;

/** Covers REST validation constraints without requiring a WordPress server. */
final class CampaignRouteSchemaTest extends TestCase {
	/** Creation requires identifiers while partial updates do not. */
	public function test_campaign_mutation_required_fields_follow_operation(): void {
		$create = CampaignRouteSchema::campaign_mutation( true );
		$update = CampaignRouteSchema::campaign_mutation( false );

		self::assertTrue( $create['name']['required'] );
		self::assertTrue( $create['slug']['required'] );
		self::assertFalse( $update['name']['required'] );
		self::assertFalse( $update['slug']['required'] );
		self::assertSame( CampaignStatus::stored(), $create['status']['enum'] );
	}

	/** Unsupported campaign configuration keys are rejected by route schemas. */
	public function test_campaign_configuration_objects_are_closed(): void {
		$schema = CampaignRouteSchema::campaign_mutation( true );

		self::assertFalse( $schema['usage_rules']['additionalProperties'] );
		self::assertFalse( $schema['conflict_rules']['additionalProperties'] );
		self::assertFalse( $schema['settings']['additionalProperties'] );
		self::assertContains( 'on-hold', $schema['usage_rules']['properties']['counted_statuses']['items']['enum'] );
	}

	/** List endpoints are positive and capped at the repository boundary. */
	public function test_pagination_is_bounded(): void {
		$schema = CampaignRouteSchema::per_page();

		self::assertSame( 1, $schema['minimum'] );
		self::assertSame( 20, $schema['default'] );
		self::assertSame( 100, $schema['maximum'] );
	}

	/** Assignment and coupon mutations require stable source inputs. */
	public function test_promotion_mutations_require_supported_identifiers(): void {
		$assignment = CampaignRouteSchema::assignment();
		$coupon     = CampaignRouteSchema::coupon();

		self::assertTrue( $assignment['external_id']['required'] );
		self::assertFalse( $assignment['allow_reassignment']['default'] );
		self::assertTrue( $assignment['settings']['additionalProperties'] );
		self::assertSame( CouponDraft::discount_types(), $coupon['discount_type']['enum'] );
	}
}
