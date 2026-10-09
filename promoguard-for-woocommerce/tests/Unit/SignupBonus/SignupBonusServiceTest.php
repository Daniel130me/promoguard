<?php
/**
 * Tests for signup-bonus award orchestration.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\SignupBonus;

use PHPUnit\Framework\TestCase;
use PromoGuard\SignupBonus\SignupBonusRules;
use PromoGuard\SignupBonus\SignupBonusService;
use PromoGuard\Tests\Support\InMemoryCreditStore;
use PromoGuard\Tests\Support\StaticSignupBonusRuleStore;

/** Covers event matching and idempotent standalone credit references. */
final class SignupBonusServiceTest extends TestCase {
	/**
	 * Captured credit grants.
	 *
	 * @var InMemoryCreditStore
	 */
	private InMemoryCreditStore $credits;
	/**
	 * Service under test.
	 *
	 * @var SignupBonusService
	 */
	private SignupBonusService $service;

	/** Build default rules and in-memory persistence. */
	protected function setUp(): void {
		$this->credits = new InMemoryCreditStore();
		$this->service = new SignupBonusService(
			new StaticSignupBonusRuleStore( SignupBonusRules::defaults() ),
			$this->credits
		);
	}

	/** Customer registration creates one currency-scoped signup grant. */
	public function test_customer_registration_is_awarded_once(): void {
		$first = $this->service->award( 42, SignupBonusRules::AUDIENCE_CUSTOMER, SignupBonusRules::EVENT_REGISTRATION, 'NGN' );
		$retry = $this->service->award( 42, SignupBonusRules::AUDIENCE_CUSTOMER, SignupBonusRules::EVENT_REGISTRATION, 'NGN' );

		self::assertNotNull( $first );
		self::assertTrue( $first->created );
		self::assertNotNull( $retry );
		self::assertFalse( $retry->created );
		self::assertCount( 1, $this->credits->grants );
		self::assertArrayHasKey( 'signup_bonus:customer:registration:42', $this->credits->grants );
	}

	/** Vendor registration waits and approval creates the default grant. */
	public function test_vendor_default_waits_for_approval(): void {
		$registration = $this->service->award( 81, SignupBonusRules::AUDIENCE_VENDOR, SignupBonusRules::EVENT_REGISTRATION, 'USD' );
		$approval     = $this->service->award( 81, SignupBonusRules::AUDIENCE_VENDOR, SignupBonusRules::EVENT_APPROVAL, 'USD' );

		self::assertNull( $registration );
		self::assertNotNull( $approval );
		self::assertTrue( $approval->created );
		self::assertArrayHasKey( 'signup_bonus:vendor:approval:81', $this->credits->grants );
	}
}
