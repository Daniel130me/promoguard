<?php
/**
 * Campaign persistence mapping tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Campaign;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use PromoGuard\Campaign\Campaign;
use PromoGuard\Campaign\CampaignConfiguration;
use PromoGuard\Campaign\CampaignHydrator;
use PromoGuard\Campaign\CampaignStatus;
use RuntimeException;

/** Covers strict campaign database mapping. */
final class CampaignHydratorTest extends TestCase {
	/** Valid campaign data survives a persistence round trip. */
	public function test_campaign_round_trip_preserves_values(): void {
		$hydrator  = new CampaignHydrator();
		$row       = $hydrator->to_row( self::campaign() );
		$row['id'] = 42;

		$campaign = $hydrator->from_row( $row );

		self::assertSame( 42, $campaign->id );
		self::assertSame( 'customer-acquisition', $campaign->slug );
		self::assertSame( 2, $campaign->configuration->usage_rules()['maximum_uses'] );
		self::assertSame( '2026-08-01 00:00:00', $campaign->starts_at_gmt?->format( 'Y-m-d H:i:s' ) );
	}

	/** Corrupt stored JSON fails explicitly. */
	public function test_invalid_json_is_rejected(): void {
		$hydrator           = new CampaignHydrator();
		$row                = $hydrator->to_row( self::campaign() );
		$row['id']          = 42;
		$row['usage_rules'] = '{invalid';

		$this->expectException( RuntimeException::class );

		$hydrator->from_row( $row );
	}

	/** JSON lists cannot masquerade as keyed configuration objects. */
	public function test_json_list_is_rejected(): void {
		$hydrator              = new CampaignHydrator();
		$row                   = $hydrator->to_row( self::campaign() );
		$row['id']             = 42;
		$row['conflict_rules'] = '[]';

		$this->expectException( RuntimeException::class );

		$hydrator->from_row( $row );
	}

	/** Build a complete validated campaign. */
	private static function campaign(): Campaign {
		$gmt = new DateTimeZone( 'UTC' );

		return new Campaign(
			id: null,
			uuid: '123e4567-e89b-42d3-a456-426614174000',
			name: 'Customer Acquisition',
			slug: 'customer-acquisition',
			description: 'Acquisition offers',
			goal: 'First order',
			status: CampaignStatus::ACTIVE,
			priority: 10,
			starts_at_gmt: new DateTimeImmutable( '2026-08-01 00:00:00', $gmt ),
			ends_at_gmt: null,
			configuration: CampaignConfiguration::from_arrays( array( 'maximum_uses' => 2 ), array(), array() ),
			created_by: 1,
			created_at_gmt: new DateTimeImmutable( '2026-07-22 00:00:00', $gmt ),
			updated_at_gmt: new DateTimeImmutable( '2026-07-22 00:00:00', $gmt )
		);
	}
}
