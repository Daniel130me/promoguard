<?php
/**
 * Reservation request factory tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Reservation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PromoGuard\Campaign\Campaign;
use PromoGuard\Campaign\CampaignConfiguration;
use PromoGuard\Campaign\CampaignStatus;
use PromoGuard\Checkout\CheckoutEvaluation;
use PromoGuard\Checkout\CheckoutTarget;
use PromoGuard\Eligibility\EligibilityDecision;
use PromoGuard\Promotion\CampaignPromotion;
use PromoGuard\Promotion\WooCommerceCouponSource;
use PromoGuard\Reservation\ReservationRequestFactory;

/** Verifies deterministic, privacy-safe checkout reservation facts. */
final class ReservationRequestFactoryTest extends TestCase {
	/** Final approval produces a 30-minute idempotent request. */
	public function test_final_allowed_evaluation_builds_reservation(): void {
		$now     = self::time();
		$request = ( new ReservationRequestFactory() )->create(
			self::evaluation( true, false, 12 ),
			101,
			'usd',
			'123e4567-e89b-42d3-a456-426614174000',
			$now
		);

		self::assertSame( 5, $request->campaign_id );
		self::assertSame( 9, $request->promotion_id );
		self::assertSame( 12, $request->customer_id );
		self::assertSame( 101, $request->order_id );
		self::assertSame( 77, $request->coupon_id );
		self::assertSame( 'WELCOME10', $request->coupon_code );
		self::assertSame( 'USD', $request->currency );
		self::assertSame( 2, $request->maximum_uses );
		self::assertSame( hash( 'sha256', '101:5' ), $request->reservation_key );
		self::assertSame( '2026-07-23 12:30:00', $request->reserved_until_gmt->format( 'Y-m-d H:i:s' ) );
	}

	/** Provisional approval cannot consume a campaign place. */
	public function test_provisional_evaluation_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		( new ReservationRequestFactory() )->create(
			self::evaluation( true, true, null ),
			101,
			'USD',
			'123e4567-e89b-42d3-a456-426614174000',
			self::time()
		);
	}

	/**
	 * Build a checkout evaluation for factory tests.
	 *
	 * @param bool     $allowed     Whether policy allowed the coupon.
	 * @param bool     $provisional Whether identity remains provisional.
	 * @param int|null $customer_id Resolved internal customer ID.
	 */
	private static function evaluation( bool $allowed, bool $provisional, ?int $customer_id ): CheckoutEvaluation {
		$time       = self::time();
		$assignment = new CampaignPromotion(
			id: 9,
			uuid: '123e4567-e89b-42d3-a456-426614174002',
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
		$campaign   = new Campaign(
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
			configuration: CampaignConfiguration::from_arrays( array( 'maximum_uses' => 2 ), array(), array() ),
			created_by: 1,
			created_at_gmt: $time,
			updated_at_gmt: $time
		);
		$decision   = $provisional
			? new EligibilityDecision(
				true,
				true,
				EligibilityDecision::PROVISIONAL_IDENTITY_REQUIRED,
				'Enter billing details.',
				'Identity is deferred.'
			)
			: new EligibilityDecision(
				$allowed,
				false,
				$allowed ? EligibilityDecision::ALLOWED : EligibilityDecision::CUSTOMER_LIMIT_REACHED,
				$allowed ? '' : 'Limit reached.',
				$allowed ? 'Allowed.' : 'Limit reached.'
			);

		return new CheckoutEvaluation( new CheckoutTarget( $assignment, $campaign ), $decision, $customer_id );
	}

	/** Build a stable GMT timestamp. */
	private static function time(): DateTimeImmutable {
		return new DateTimeImmutable( '2026-07-23 12:00:00', new DateTimeZone( 'UTC' ) );
	}
}
