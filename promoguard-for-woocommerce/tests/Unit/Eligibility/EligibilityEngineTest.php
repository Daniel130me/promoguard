<?php
/**
 * Eligibility engine tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Eligibility;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use PromoGuard\Campaign\Campaign;
use PromoGuard\Campaign\CampaignConfiguration;
use PromoGuard\Campaign\CampaignStatus;
use PromoGuard\Customer\Customer;
use PromoGuard\Customer\IdentityResolution;
use PromoGuard\Eligibility\CustomerCampaignState;
use PromoGuard\Eligibility\EligibilityContext;
use PromoGuard\Eligibility\EligibilityDecision;
use PromoGuard\Eligibility\EligibilityEngine;
use PromoGuard\Tests\Support\CountingCustomerCampaignStateStore;

/** Verifies ordered, deterministic policy evaluation and bounded reads. */
final class EligibilityEngineTest extends TestCase {
	/** Cheap campaign status denials do not query customer state. */
	public function test_status_denial_short_circuits_state_lookup(): void {
		$store    = new CountingCustomerCampaignStateStore();
		$decision = ( new EligibilityEngine( $store ) )->evaluate(
			self::context( campaign: self::campaign( CampaignStatus::DRAFT ) )
		);

		self::assertSame( EligibilityDecision::CAMPAIGN_DRAFT, $decision->reason );
		self::assertFalse( $decision->allowed );
		self::assertSame( 0, $store->calls );
	}

	/** Login policy runs before customer state access. */
	public function test_login_requirement_short_circuits_state_lookup(): void {
		$store    = new CountingCustomerCampaignStateStore();
		$campaign = self::campaign(
			configuration: CampaignConfiguration::from_arrays( array(), array(), array( 'login_required' => true ) )
		);
		$decision = ( new EligibilityEngine( $store ) )->evaluate(
			self::context( campaign: $campaign, is_authenticated: false )
		);

		self::assertSame( EligibilityDecision::LOGIN_REQUIRED, $decision->reason );
		self::assertSame( 0, $store->calls );
	}

	/** Early cart checks can defer identity without weakening final checkout. */
	public function test_missing_identity_can_be_provisional_only_when_explicitly_allowed(): void {
		$store       = new CountingCustomerCampaignStateStore();
		$identity    = new IdentityResolution( null, IdentityResolution::OUTCOME_MISSING );
		$engine      = new EligibilityEngine( $store );
		$provisional = $engine->evaluate(
			self::context( identity: $identity, allow_provisional_identity: true )
		);
		$final       = $engine->evaluate( self::context( identity: $identity ) );

		self::assertTrue( $provisional->allowed );
		self::assertTrue( $provisional->provisional );
		self::assertSame( EligibilityDecision::CUSTOMER_IDENTITY_MISSING, $final->reason );
		self::assertSame( 0, $store->calls );
	}

	/** Conflicting authenticated identities fail closed before state access. */
	public function test_identity_conflict_fails_closed(): void {
		$store    = new CountingCustomerCampaignStateStore();
		$identity = new IdentityResolution( self::customer(), IdentityResolution::OUTCOME_CONFLICT );
		$decision = ( new EligibilityEngine( $store ) )->evaluate( self::context( identity: $identity ) );

		self::assertSame( EligibilityDecision::IDENTITY_CONFLICT, $decision->reason );
		self::assertSame( 0, $store->calls );
	}

	/** Consumed and reserved counters both enforce the customer maximum. */
	public function test_committed_usage_reaches_customer_limit(): void {
		$state    = self::state( consumed_count: 0, reserved_count: 1 );
		$store    = new CountingCustomerCampaignStateStore( $state );
		$decision = ( new EligibilityEngine( $store ) )->evaluate( self::context() );

		self::assertSame( EligibilityDecision::CUSTOMER_LIMIT_REACHED, $decision->reason );
		self::assertSame( 1, $store->calls );
	}

	/** Same-campaign coupon conflicts run after one bounded state read. */
	public function test_existing_campaign_coupon_is_denied(): void {
		$store    = new CountingCustomerCampaignStateStore();
		$decision = ( new EligibilityEngine( $store ) )->evaluate(
			self::context( applied_campaign_coupon_count: 1 )
		);

		self::assertSame( EligibilityDecision::CAMPAIGN_COUPON_ALREADY_APPLIED, $decision->reason );
		self::assertSame( 1, $store->calls );
	}

	/** A valid customer below all limits receives final approval. */
	public function test_eligible_customer_is_allowed_with_one_state_lookup(): void {
		$store    = new CountingCustomerCampaignStateStore();
		$decision = ( new EligibilityEngine( $store ) )->evaluate( self::context() );

		self::assertTrue( $decision->allowed );
		self::assertFalse( $decision->provisional );
		self::assertSame( EligibilityDecision::ALLOWED, $decision->reason );
		self::assertSame( 1, $store->calls );
	}

	/**
	 * Build a policy context with focused overrides.
	 *
	 * @param Campaign|null           $campaign                      Campaign override.
	 * @param IdentityResolution|null $identity                      Identity override.
	 * @param bool                    $is_authenticated              Authentication state.
	 * @param int                     $applied_campaign_coupon_count Existing campaign coupon count.
	 * @param bool                    $allow_provisional_identity    Whether identity may be deferred.
	 */
	private static function context(
		?Campaign $campaign = null,
		?IdentityResolution $identity = null,
		bool $is_authenticated = true,
		int $applied_campaign_coupon_count = 0,
		bool $allow_provisional_identity = false
	): EligibilityContext {
		return new EligibilityContext(
			campaign: $campaign ?? self::campaign(),
			identity: $identity ?? new IdentityResolution( self::customer(), IdentityResolution::OUTCOME_MATCHED ),
			is_authenticated: $is_authenticated,
			applied_campaign_coupon_count: $applied_campaign_coupon_count,
			now_gmt: self::time( '2026-07-23 12:00:00' ),
			allow_provisional_identity: $allow_provisional_identity
		);
	}

	/**
	 * Build a persisted campaign.
	 *
	 * @param string                     $status        Stored campaign status.
	 * @param CampaignConfiguration|null $configuration Configuration override.
	 */
	private static function campaign(
		string $status = CampaignStatus::ACTIVE,
		?CampaignConfiguration $configuration = null
	): Campaign {
		return new Campaign(
			id: 1,
			uuid: '123e4567-e89b-42d3-a456-426614174000',
			name: 'Private beta',
			slug: 'private-beta',
			description: '',
			goal: null,
			status: $status,
			priority: 0,
			starts_at_gmt: null,
			ends_at_gmt: null,
			configuration: $configuration ?? CampaignConfiguration::defaults(),
			created_by: 1,
			created_at_gmt: self::time( '2026-07-22 12:00:00' ),
			updated_at_gmt: self::time( '2026-07-22 12:00:00' )
		);
	}

	/** Build the resolved authenticated customer. */
	private static function customer(): Customer {
		return new Customer( 2, 7, null, self::time( '2026-07-22 12:00:00' ), self::time( '2026-07-22 12:00:00' ) );
	}

	/**
	 * Build a bounded state snapshot.
	 *
	 * @param int $consumed_count Consumed count.
	 * @param int $reserved_count Reserved count.
	 */
	private static function state( int $consumed_count, int $reserved_count ): CustomerCampaignState {
		return new CustomerCampaignState(
			campaign_id: 1,
			customer_id: 2,
			consumed_count: $consumed_count,
			reserved_count: $reserved_count,
			total_discount: '0.00000000',
			first_consumed_at_gmt: null,
			last_consumed_at_gmt: null,
			last_order_id: null,
			lock_version: 0
		);
	}

	/**
	 * Build a GMT timestamp.
	 *
	 * @param string $value Database-style timestamp.
	 */
	private static function time( string $value ): DateTimeImmutable {
		return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
	}
}
