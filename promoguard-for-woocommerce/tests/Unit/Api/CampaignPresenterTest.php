<?php
/**
 * Campaign REST presenter tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Api;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use PromoGuard\Api\CampaignPresenter;
use PromoGuard\Campaign\Campaign;
use PromoGuard\Campaign\CampaignConfiguration;
use PromoGuard\Campaign\CampaignStatus;
use PromoGuard\Promotion\CampaignPromotion;
use PromoGuard\Promotion\Promotion;

/** Covers stable REST response shapes without WordPress runtime dependencies. */
final class CampaignPresenterTest extends TestCase {
	/** Campaign responses include effective state and normalized GMT values. */
	public function test_campaign_presents_effective_status_and_configuration(): void {
		$campaign = self::campaign();
		$result   = ( new CampaignPresenter() )->campaign( $campaign, self::gmt( '2026-07-22 10:00:00' ) );

		self::assertSame( 9, $result['id'] );
		self::assertSame( CampaignStatus::SCHEDULED, $result['effective_status'] );
		self::assertSame( '2026-08-01T00:00:00Z', $result['starts_at_gmt'] );
		self::assertSame( 1, $result['usage_rules']['maximum_uses'] );
		self::assertSame( false, $result['settings']['login_required'] );
	}

	/** Campaign pages preserve bounded pagination metadata. */
	public function test_campaign_page_preserves_pagination_metadata(): void {
		$result = ( new CampaignPresenter() )->campaign_page(
			array(
				'items'    => array( self::campaign() ),
				'total'    => 11,
				'page'     => 2,
				'per_page' => 5,
			),
			self::gmt( '2026-07-22 10:00:00' )
		);

		self::assertCount( 1, $result['items'] );
		self::assertSame( 11, $result['total'] );
		self::assertSame( 2, $result['page'] );
		self::assertSame( 5, $result['per_page'] );
	}

	/** Promotion responses expose source-neutral identifiers only. */
	public function test_promotion_presents_source_neutral_fields(): void {
		$result = ( new CampaignPresenter() )->promotion(
			new Promotion( 'woocommerce', 'coupon', '81', 'WELCOME10', 'Welcome', true )
		);

		self::assertSame(
			array(
				'source'      => 'woocommerce',
				'source_type' => 'coupon',
				'external_id' => '81',
				'code'        => 'WELCOME10',
				'label'       => 'Welcome',
				'available'   => true,
			),
			$result
		);
	}

	/** Assignment responses retain historical source snapshots. */
	public function test_assignment_presents_snapshots_and_gmt_dates(): void {
		$assignment = new CampaignPromotion(
			id: 5,
			uuid: '987e6543-e21b-42d3-a456-426614174999',
			campaign_id: 9,
			source: 'woocommerce',
			source_type: 'coupon',
			external_id: '81',
			external_code: 'WELCOME10',
			channel: null,
			label: 'Welcome',
			sort_order: 0,
			settings: array(),
			created_at_gmt: self::gmt( '2026-07-22 10:00:00' ),
			updated_at_gmt: self::gmt( '2026-07-23 11:30:00' )
		);

		$result = ( new CampaignPresenter() )->assignment( $assignment );

		self::assertSame( 'WELCOME10', $result['external_code'] );
		self::assertSame( '2026-07-23T11:30:00Z', $result['updated_at_gmt'] );
		self::assertSame( array(), $result['settings'] );
	}

	/** Build a scheduled campaign fixture. */
	private static function campaign(): Campaign {
		return new Campaign(
			id: 9,
			uuid: '123e4567-e89b-42d3-a456-426614174000',
			name: 'Customer Acquisition',
			slug: 'customer-acquisition',
			description: '',
			goal: null,
			status: CampaignStatus::ACTIVE,
			priority: 0,
			starts_at_gmt: self::gmt( '2026-08-01 00:00:00' ),
			ends_at_gmt: null,
			configuration: CampaignConfiguration::defaults(),
			created_by: 7,
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
