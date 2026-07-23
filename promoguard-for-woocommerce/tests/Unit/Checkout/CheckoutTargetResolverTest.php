<?php
/**
 * Checkout target resolver tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Checkout;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use PromoGuard\Campaign\Campaign;
use PromoGuard\Campaign\CampaignConfiguration;
use PromoGuard\Campaign\CampaignStatus;
use PromoGuard\Campaign\CampaignStore;
use PromoGuard\Checkout\CheckoutTargetResolver;
use PromoGuard\Promotion\CampaignPromotion;
use PromoGuard\Promotion\CampaignPromotionStore;
use PromoGuard\Promotion\WooCommerceCouponSource;

/** Verifies indexed coupon resolution and request-local caching. */
final class CheckoutTargetResolverTest extends TestCase {
	/** Unassigned coupons remain unrelated and cache the negative lookup. */
	public function test_unassigned_coupon_is_ignored_and_cached(): void {
		$assignments = $this->createMock( CampaignPromotionStore::class );
		$campaigns   = $this->createMock( CampaignStore::class );
		$assignments
			->expects( self::once() )
			->method( 'find_by_source' )
			->with( WooCommerceCouponSource::SOURCE, WooCommerceCouponSource::SOURCE_TYPE, '77', false )
			->willReturn( null );
		$campaigns->expects( self::never() )->method( 'find' );

		$resolver = new CheckoutTargetResolver( $assignments, $campaigns );

		self::assertNull( $resolver->resolve( 77 ) );
		self::assertNull( $resolver->resolve( 77 ) );
	}

	/** Assigned coupons resolve their campaign once per request. */
	public function test_assigned_coupon_and_campaign_are_cached(): void {
		$assignment  = self::assignment();
		$campaign    = self::campaign();
		$assignments = $this->createMock( CampaignPromotionStore::class );
		$campaigns   = $this->createMock( CampaignStore::class );
		$assignments->expects( self::once() )->method( 'find_by_source' )->willReturn( $assignment );
		$campaigns->expects( self::once() )->method( 'find' )->with( 5 )->willReturn( $campaign );

		$resolver = new CheckoutTargetResolver( $assignments, $campaigns );
		$first    = $resolver->resolve( 77 );
		$second   = $resolver->resolve( 77 );

		self::assertNotNull( $first );
		self::assertSame( $first, $second );
		self::assertSame( $assignment, $first->assignment );
		self::assertSame( $campaign, $first->campaign );
	}

	/** Missing campaign records fail closed and cache the result. */
	public function test_orphaned_assignment_fails_closed(): void {
		$assignments = $this->createMock( CampaignPromotionStore::class );
		$campaigns   = $this->createMock( CampaignStore::class );
		$assignments->expects( self::once() )->method( 'find_by_source' )->willReturn( self::assignment() );
		$campaigns->expects( self::once() )->method( 'find' )->willReturn( null );

		$resolver = new CheckoutTargetResolver( $assignments, $campaigns );

		self::assertNull( $resolver->resolve( 77 ) );
		self::assertNull( $resolver->resolve( 77 ) );
	}

	/** Build a persisted coupon assignment. */
	private static function assignment(): CampaignPromotion {
		$time = self::time();

		return new CampaignPromotion(
			id: 9,
			uuid: '123e4567-e89b-42d3-a456-426614174000',
			campaign_id: 5,
			source: WooCommerceCouponSource::SOURCE,
			source_type: WooCommerceCouponSource::SOURCE_TYPE,
			external_id: '77',
			external_code: 'WELCOME10',
			channel: null,
			label: null,
			sort_order: 0,
			settings: array(),
			created_at_gmt: $time,
			updated_at_gmt: $time
		);
	}

	/** Build the assigned active campaign. */
	private static function campaign(): Campaign {
		$time = self::time();

		return new Campaign(
			id: 5,
			uuid: '123e4567-e89b-42d3-a456-426614174001',
			name: 'Acquisition',
			slug: 'acquisition',
			description: '',
			goal: null,
			status: CampaignStatus::ACTIVE,
			priority: 0,
			starts_at_gmt: null,
			ends_at_gmt: null,
			configuration: CampaignConfiguration::defaults(),
			created_by: 1,
			created_at_gmt: $time,
			updated_at_gmt: $time
		);
	}

	/** Build a GMT timestamp. */
	private static function time(): DateTimeImmutable {
		return new DateTimeImmutable( '2026-07-23 12:00:00', new DateTimeZone( 'UTC' ) );
	}
}
