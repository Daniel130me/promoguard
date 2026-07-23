<?php
/**
 * Checkout validator tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Checkout;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use PromoGuard\Application\EligibilityEvaluator;
use PromoGuard\Campaign\Campaign;
use PromoGuard\Campaign\CampaignConfiguration;
use PromoGuard\Campaign\CampaignStatus;
use PromoGuard\Campaign\CampaignStore;
use PromoGuard\Checkout\CheckoutTargetResolver;
use PromoGuard\Checkout\CheckoutValidator;
use PromoGuard\Eligibility\EligibilityDecision;
use PromoGuard\Promotion\CampaignPromotion;
use PromoGuard\Promotion\CampaignPromotionStore;
use PromoGuard\Promotion\WooCommerceCouponSource;

/** Verifies campaign conflict counting and request decision caching. */
final class CheckoutValidatorTest extends TestCase {
	/** Unassigned coupons bypass PromoGuard policy evaluation. */
	public function test_unassigned_coupon_is_untouched(): void {
		$assignments = $this->createMock( CampaignPromotionStore::class );
		$campaigns   = $this->createMock( CampaignStore::class );
		$eligibility = $this->createMock( EligibilityEvaluator::class );
		$assignments->method( 'find_by_source' )->willReturn( null );
		$eligibility->expects( self::never() )->method( 'evaluate' );
		$validator = new CheckoutValidator(
			new CheckoutTargetResolver( $assignments, $campaigns ),
			$eligibility
		);

		self::assertNull( $validator->evaluate( 77, array(), null, null, self::time(), true ) );
	}

	/** Other coupons in the same campaign are counted once and decisions are cached. */
	public function test_same_campaign_count_and_decision_are_request_cached(): void {
		$campaign    = self::campaign();
		$assignments = $this->createMock( CampaignPromotionStore::class );
		$campaigns   = $this->createMock( CampaignStore::class );
		$eligibility = $this->createMock( EligibilityEvaluator::class );
		$assignments
			->expects( self::exactly( 2 ) )
			->method( 'find_by_source' )
			->willReturnCallback(
				static fn( string $source, string $source_type, string $external_id ): CampaignPromotion => self::assignment( (int) $external_id )
			);
		$campaigns->expects( self::exactly( 2 ) )->method( 'find' )->with( 5 )->willReturn( $campaign );
		$eligibility
			->expects( self::once() )
			->method( 'evaluate' )
			->with( $campaign, 42, 'customer@example.com', 1, self::time(), false )
			->willReturn( self::allowed() );
		$validator = new CheckoutValidator(
			new CheckoutTargetResolver( $assignments, $campaigns ),
			$eligibility
		);

		$first  = $validator->evaluate( 77, array( 77, 88, 88 ), 42, 'customer@example.com', self::time(), false );
		$second = $validator->evaluate( 77, array( 77, 88, 88 ), 42, 'customer@example.com', self::time(), false );

		self::assertNotNull( $first );
		self::assertSame( $first, $second );
		self::assertSame( EligibilityDecision::ALLOWED, $first->decision->reason );
	}

	/**
	 * Build a persisted coupon assignment.
	 *
	 * @param int $coupon_id Native coupon ID.
	 */
	private static function assignment( int $coupon_id ): CampaignPromotion {
		$time = self::time();

		return new CampaignPromotion(
			id: $coupon_id,
			uuid: 77 === $coupon_id
				? '123e4567-e89b-42d3-a456-426614174000'
				: '123e4567-e89b-42d3-a456-426614174002',
			campaign_id: 5,
			source: WooCommerceCouponSource::SOURCE,
			source_type: WooCommerceCouponSource::SOURCE_TYPE,
			external_id: (string) $coupon_id,
			external_code: 'CODE' . $coupon_id,
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

	/** Build an allowed decision. */
	private static function allowed(): EligibilityDecision {
		return new EligibilityDecision( true, false, EligibilityDecision::ALLOWED, '', 'Allowed.' );
	}

	/** Build a stable GMT timestamp. */
	private static function time(): DateTimeImmutable {
		return new DateTimeImmutable( '2026-07-23 12:00:00', new DateTimeZone( 'UTC' ) );
	}
}
