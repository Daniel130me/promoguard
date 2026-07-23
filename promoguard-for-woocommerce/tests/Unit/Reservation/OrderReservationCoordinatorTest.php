<?php
/**
 * Shared order reservation coordinator tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Reservation;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use PromoGuard\Application\EligibilityEvaluator;
use PromoGuard\Application\EligibilityResult;
use PromoGuard\Campaign\Campaign;
use PromoGuard\Campaign\CampaignConfiguration;
use PromoGuard\Campaign\CampaignStatus;
use PromoGuard\Campaign\CampaignStore;
use PromoGuard\Checkout\CheckoutTargetResolver;
use PromoGuard\Checkout\CheckoutValidator;
use PromoGuard\Customer\Customer;
use PromoGuard\Customer\IdentityResolution;
use PromoGuard\Decision\DenialLogger;
use PromoGuard\Eligibility\EligibilityDecision;
use PromoGuard\Promotion\CampaignPromotion;
use PromoGuard\Promotion\CampaignPromotionStore;
use PromoGuard\Promotion\WooCommerceCouponSource;
use PromoGuard\Reservation\OrderReservationCoordinator;
use PromoGuard\Reservation\ReservationRequestFactory;
use PromoGuard\Reservation\ReservationResult;
use PromoGuard\Reservation\ReservationService;
use PromoGuard\Tests\Support\InMemoryDecisionStore;
use PromoGuard\Tests\Support\InMemoryOrderUsageStore;
use PromoGuard\Tests\Support\InMemoryReservationStore;

/** Verifies shared final validation, idempotency, and denial persistence. */
final class OrderReservationCoordinatorTest extends TestCase {
	/** A final allowed campaign creates one atomic reservation. */
	public function test_allowed_campaign_is_reserved(): void {
		$reservations = new InMemoryReservationStore(
			array( new ReservationResult( 31, ReservationResult::PENDING, true ) )
		);
		$decisions    = new InMemoryDecisionStore();
		$coordinator  = $this->coordinator(
			self::decision( true ),
			$reservations,
			$decisions,
			new InMemoryOrderUsageStore()
		);

		$denied = $this->reserve( $coordinator );

		self::assertSame( array(), $denied );
		self::assertSame( 1, $reservations->calls );
		self::assertSame( array(), $decisions->records );
	}

	/** Existing active order usage bypasses policy and reservation work. */
	public function test_existing_active_usage_is_idempotent(): void {
		$reservations = new InMemoryReservationStore( array() );
		$decisions    = new InMemoryDecisionStore();
		$coordinator  = $this->coordinator(
			self::decision( false ),
			$reservations,
			$decisions,
			new InMemoryOrderUsageStore( array( 5 ) ),
			false
		);

		$denied = $this->reserve( $coordinator );

		self::assertSame( array(), $denied );
		self::assertSame( 0, $reservations->calls );
		self::assertSame( array(), $decisions->records );
	}

	/** A final denial is logged and never creates a reservation. */
	public function test_denied_campaign_is_logged_without_reservation(): void {
		$reservations = new InMemoryReservationStore( array() );
		$decisions    = new InMemoryDecisionStore();
		$coordinator  = $this->coordinator(
			self::decision( false ),
			$reservations,
			$decisions,
			new InMemoryOrderUsageStore()
		);

		$denied = $this->reserve( $coordinator );

		self::assertArrayHasKey( 5, $denied );
		self::assertSame( 0, $reservations->calls );
		self::assertCount( 1, $decisions->records );
		self::assertSame( 'order_validation', $decisions->records[0]->context );
		self::assertSame( 101, $decisions->records[0]->order_id );
	}

	/**
	 * Build a coordinator around one campaign decision.
	 *
	 * @param EligibilityDecision      $decision       Eligibility decision.
	 * @param InMemoryReservationStore $reservations   Reservation persistence.
	 * @param InMemoryDecisionStore    $decisions      Decision persistence.
	 * @param InMemoryOrderUsageStore  $usages         Active usage lookup.
	 * @param bool                     $expect_policy  Whether policy should run.
	 */
	private function coordinator(
		EligibilityDecision $decision,
		InMemoryReservationStore $reservations,
		InMemoryDecisionStore $decisions,
		InMemoryOrderUsageStore $usages,
		bool $expect_policy = true
	): OrderReservationCoordinator {
		$assignment_store = $this->createMock( CampaignPromotionStore::class );
		$campaign_store   = $this->createMock( CampaignStore::class );
		$eligibility      = $this->createMock( EligibilityEvaluator::class );
		$assignment_store->method( 'find_by_source' )->willReturn( self::assignment() );
		$campaign_store->method( 'find' )->willReturn( self::campaign() );

		$expectation = $expect_policy ? self::once() : self::never();
		$eligibility
			->expects( $expectation )
			->method( 'evaluate' )
			->willReturn( self::eligibility_result( $decision ) );

		return new OrderReservationCoordinator(
			new CheckoutValidator(
				new CheckoutTargetResolver( $assignment_store, $campaign_store ),
				$eligibility
			),
			new DenialLogger( $decisions ),
			new ReservationService( $reservations ),
			new ReservationRequestFactory(),
			$usages,
			static fn(): string => '123e4567-e89b-42d3-a456-426614174099',
			'123e4567-e89b-42d3-a456-426614174098'
		);
	}

	/**
	 * Run one stable order reservation.
	 *
	 * @param OrderReservationCoordinator $coordinator Shared order coordinator.
	 * @return array<int,\PromoGuard\Checkout\CheckoutEvaluation>
	 */
	private function reserve( OrderReservationCoordinator $coordinator ): array {
		return $coordinator->reserve(
			array( 77 ),
			42,
			'customer@example.com',
			101,
			'USD',
			self::time(),
			'order_validation'
		);
	}

	/** Build the assigned campaign coupon. */
	private static function assignment(): CampaignPromotion {
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
			created_at_gmt: self::time(),
			updated_at_gmt: self::time()
		);
	}

	/** Build the active campaign. */
	private static function campaign(): Campaign {
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
			created_at_gmt: self::time(),
			updated_at_gmt: self::time()
		);
	}

	/**
	 * Build an allowed or customer-limit decision.
	 *
	 * @param bool $allowed Whether the decision should allow usage.
	 */
	private static function decision( bool $allowed ): EligibilityDecision {
		return $allowed
			? new EligibilityDecision( true, false, EligibilityDecision::ALLOWED, '', 'Allowed.' )
			: new EligibilityDecision(
				false,
				false,
				EligibilityDecision::CUSTOMER_LIMIT_REACHED,
				'Maximum reached.',
				'Customer campaign limit reached.'
			);
	}

	/**
	 * Couple the configured decision to one authoritative customer.
	 *
	 * @param EligibilityDecision $decision Configured policy decision.
	 */
	private static function eligibility_result( EligibilityDecision $decision ): EligibilityResult {
		return new EligibilityResult(
			new IdentityResolution(
				new Customer( 12, 42, null, self::time(), self::time() ),
				IdentityResolution::OUTCOME_MATCHED
			),
			$decision
		);
	}

	/** Build a stable GMT timestamp. */
	private static function time(): DateTimeImmutable {
		return new DateTimeImmutable( '2026-07-23 12:00:00', new DateTimeZone( 'UTC' ) );
	}
}
