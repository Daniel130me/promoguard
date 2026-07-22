<?php
/**
 * Campaign application service tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;
use PromoGuard\Application\CampaignService;
use PromoGuard\Campaign\Campaign;
use PromoGuard\Campaign\CampaignConfiguration;
use PromoGuard\Campaign\CampaignStatus;
use PromoGuard\Campaign\CampaignStore;
use PromoGuard\Promotion\CampaignPromotion;
use PromoGuard\Promotion\CampaignPromotionStore;
use PromoGuard\Promotion\Promotion;
use PromoGuard\Promotion\PromotionSource;

/** Covers campaign use-case orchestration without WordPress or a database. */
final class CampaignServiceTest extends TestCase {
	private const CAMPAIGN_UUID   = '123e4567-e89b-42d3-a456-426614174000';
	private const ASSIGNMENT_UUID = '987e6543-e21b-42d3-a456-426614174999';

	/** Creation returns a persisted representation without rereading storage. */
	public function test_create_campaign_returns_generated_identifier_without_read(): void {
		$campaigns = $this->createMock( CampaignStore::class );
		$campaigns->expects( self::once() )
			->method( 'create' )
			->with(
				self::callback(
					static function ( Campaign $campaign ): bool {
						self::assertNull( $campaign->id );
						self::assertSame( 'Customer Acquisition', $campaign->name );

						return true;
					}
				)
			)
			->willReturn( 42 );
		$campaigns->expects( self::never() )->method( 'find' );

		$service = new CampaignService(
			$campaigns,
			$this->createMock( CampaignPromotionStore::class ),
			$this->createMock( PromotionSource::class )
		);

		$created = $service->create_campaign(
			array(
				'name' => 'Customer Acquisition',
				'slug' => 'customer-acquisition',
			),
			self::CAMPAIGN_UUID,
			7,
			self::gmt( '2026-07-22 10:00:00' )
		);

		self::assertSame( 42, $created->id );
		self::assertSame( 7, $created->created_by );
	}

	/** Update reuses the one loaded record instead of triggering a second read. */
	public function test_update_campaign_passes_loaded_record_to_store(): void {
		$current   = self::campaign();
		$campaigns = $this->createMock( CampaignStore::class );
		$campaigns->expects( self::once() )
			->method( 'find' )
			->with( 9 )
			->willReturn( $current );
		$campaigns->expects( self::once() )
			->method( 'update' )
			->with(
				self::callback(
					static function ( Campaign $campaign ): bool {
						self::assertSame( 'Updated', $campaign->description );
						self::assertSame( 3, $campaign->configuration->usage_rules()['maximum_uses'] );

						return true;
					}
				),
				$current
			)
			->willReturn( true );

		$service = new CampaignService(
			$campaigns,
			$this->createMock( CampaignPromotionStore::class ),
			$this->createMock( PromotionSource::class )
		);

		$updated = $service->update_campaign(
			9,
			array(
				'description' => 'Updated',
				'usage_rules' => array( 'maximum_uses' => 3 ),
			),
			self::gmt( '2026-07-23 10:00:00' )
		);

		self::assertNotNull( $updated );
		self::assertSame( 9, $updated->id );
	}

	/** Resolved source promotions are passed to the atomic assignment store. */
	public function test_assign_promotion_delegates_explicit_reassignment(): void {
		$promotion  = new Promotion( 'woocommerce', 'coupon', '81', 'WELCOME10', 'Welcome', true );
		$assignment = self::assignment();
		$source     = $this->createMock( PromotionSource::class );
		$source->expects( self::once() )
			->method( 'resolve' )
			->with( '81' )
			->willReturn( $promotion );

		$assignments = $this->createMock( CampaignPromotionStore::class );
		$assignments->expects( self::once() )
			->method( 'assign' )
			->with( 9, $promotion, true, 'social', 'Acquisition', 2, array( 'featured' => true ) )
			->willReturn( $assignment );

		$service = new CampaignService(
			$this->createMock( CampaignStore::class ),
			$assignments,
			$source
		);

		$result = $service->assign_promotion(
			9,
			'81',
			true,
			'social',
			'Acquisition',
			2,
			array( 'featured' => true )
		);

		self::assertSame( $assignment, $result );
	}

	/** Missing source promotions fail before assignment persistence starts. */
	public function test_assign_promotion_rejects_missing_source_record(): void {
		$source = $this->createMock( PromotionSource::class );
		$source->method( 'resolve' )->with( '404' )->willReturn( null );

		$assignments = $this->createMock( CampaignPromotionStore::class );
		$assignments->expects( self::never() )->method( 'assign' );

		$service = new CampaignService(
			$this->createMock( CampaignStore::class ),
			$assignments,
			$source
		);

		$this->expectException( DomainException::class );

		$service->assign_promotion( 9, '404' );
	}

	/** Build one representative persisted campaign. */
	private static function campaign(): Campaign {
		return new Campaign(
			id: 9,
			uuid: self::CAMPAIGN_UUID,
			name: 'Customer Acquisition',
			slug: 'customer-acquisition',
			description: '',
			goal: null,
			status: CampaignStatus::ACTIVE,
			priority: 0,
			starts_at_gmt: null,
			ends_at_gmt: null,
			configuration: CampaignConfiguration::defaults(),
			created_by: 7,
			created_at_gmt: self::gmt( '2026-07-22 10:00:00' ),
			updated_at_gmt: self::gmt( '2026-07-22 10:00:00' )
		);
	}

	/** Build one representative assignment. */
	private static function assignment(): CampaignPromotion {
		return new CampaignPromotion(
			id: 5,
			uuid: self::ASSIGNMENT_UUID,
			campaign_id: 9,
			source: 'woocommerce',
			source_type: 'coupon',
			external_id: '81',
			external_code: 'WELCOME10',
			channel: 'social',
			label: 'Acquisition',
			sort_order: 2,
			settings: array( 'featured' => true ),
			created_at_gmt: self::gmt( '2026-07-22 10:00:00' ),
			updated_at_gmt: self::gmt( '2026-07-22 10:00:00' )
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
