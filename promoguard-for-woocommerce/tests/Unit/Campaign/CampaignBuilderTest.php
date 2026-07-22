<?php
/**
 * Campaign builder tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Campaign;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PromoGuard\Campaign\Campaign;
use PromoGuard\Campaign\CampaignBuilder;
use PromoGuard\Campaign\CampaignStatus;

/** Covers transport-neutral campaign creation and partial updates. */
final class CampaignBuilderTest extends TestCase {
	private const UUID = '123e4567-e89b-42d3-a456-426614174000';

	/** Creation applies supported defaults and normalizes timestamps to GMT. */
	public function test_create_builds_valid_campaign_with_defaults(): void {
		$campaign = ( new CampaignBuilder() )->create(
			array(
				'name'          => '  Customer Acquisition  ',
				'slug'          => 'customer-acquisition',
				'starts_at_gmt' => '2026-08-01T01:00:00+01:00',
			),
			self::UUID,
			7,
			self::gmt( '2026-07-22 10:00:00' )
		);

		self::assertSame( 'Customer Acquisition', $campaign->name );
		self::assertSame( CampaignStatus::DRAFT, $campaign->status );
		self::assertSame( '2026-08-01 00:00:00', $campaign->starts_at_gmt?->format( 'Y-m-d H:i:s' ) );
		self::assertSame( 1, $campaign->configuration->usage_rules()['maximum_uses'] );
		self::assertSame( 7, $campaign->created_by );
	}

	/** Partial updates preserve omitted fields and merge supported rule keys. */
	public function test_update_preserves_omitted_fields_and_merges_rules(): void {
		$builder  = new CampaignBuilder();
		$existing = self::campaign();
		$updated  = $builder->update(
			$existing,
			array(
				'description' => 'Updated description',
				'usage_rules' => array( 'maximum_uses' => 3 ),
				'settings'    => array( 'login_required' => true ),
			),
			self::gmt( '2026-07-23 12:00:00' )
		);

		self::assertSame( $existing->name, $updated->name );
		self::assertSame( 'Updated description', $updated->description );
		self::assertSame( 3, $updated->configuration->usage_rules()['maximum_uses'] );
		self::assertSame( array( 'processing', 'completed' ), $updated->configuration->usage_rules()['counted_statuses'] );
		self::assertTrue( $updated->configuration->settings()['login_required'] );
		self::assertSame( $existing->created_at_gmt, $updated->created_at_gmt );
	}

	/** Missing required creation fields are rejected before persistence. */
	public function test_create_rejects_missing_required_field(): void {
		$this->expectException( InvalidArgumentException::class );

		( new CampaignBuilder() )->create(
			array( 'name' => 'Customer Acquisition' ),
			self::UUID,
			1,
			self::gmt( '2026-07-22 10:00:00' )
		);
	}

	/** Invalid transport types are rejected rather than silently coerced. */
	public function test_update_rejects_invalid_configuration_group_type(): void {
		$this->expectException( InvalidArgumentException::class );

		( new CampaignBuilder() )->update(
			self::campaign(),
			array( 'usage_rules' => 'maximum_uses=2' ),
			self::gmt( '2026-07-23 12:00:00' )
		);
	}

	/** Build a representative existing campaign. */
	private static function campaign(): Campaign {
		return ( new CampaignBuilder() )->create(
			array(
				'name'        => 'Customer Acquisition',
				'slug'        => 'customer-acquisition',
				'description' => 'Original description',
				'status'      => CampaignStatus::ACTIVE,
			),
			self::UUID,
			1,
			self::gmt( '2026-07-22 10:00:00' )
		);
	}

	/**
	 * Build a GMT timestamp.
	 *
	 * @param string $value Timestamp value.
	 */
	private static function gmt( string $value ): DateTimeImmutable {
		return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
	}
}
