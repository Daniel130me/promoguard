<?php
/**
 * Tests for standalone signup-bonus campaign rules.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\SignupBonus;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PromoGuard\SignupBonus\SignupBonusRules;

/** Covers defaults, administrator overrides, and closed rule validation. */
final class SignupBonusRulesTest extends TestCase {
	/** Customers default to registration while vendors default to approval. */
	public function test_defaults_match_customer_and_vendor_lifecycle_requirements(): void {
		$rules = SignupBonusRules::defaults();

		self::assertSame( '10', $rules->amount_for( SignupBonusRules::AUDIENCE_CUSTOMER, SignupBonusRules::EVENT_REGISTRATION ) );
		self::assertNull( $rules->amount_for( SignupBonusRules::AUDIENCE_VENDOR, SignupBonusRules::EVENT_REGISTRATION ) );
		self::assertSame( '10', $rules->amount_for( SignupBonusRules::AUDIENCE_VENDOR, SignupBonusRules::EVENT_APPROVAL ) );
	}

	/** Administrators can change timing, amounts, or disable an audience. */
	public function test_rules_are_administrator_configurable(): void {
		$rules = SignupBonusRules::from_array(
			array(
				'customer_event'  => SignupBonusRules::EVENT_DISABLED,
				'customer_amount' => '5.50',
				'vendor_event'    => SignupBonusRules::EVENT_REGISTRATION,
				'vendor_amount'   => '25.00',
			)
		);

		self::assertNull( $rules->amount_for( SignupBonusRules::AUDIENCE_CUSTOMER, SignupBonusRules::EVENT_REGISTRATION ) );
		self::assertSame( '25', $rules->amount_for( SignupBonusRules::AUDIENCE_VENDOR, SignupBonusRules::EVENT_REGISTRATION ) );
	}

	/** Unknown fields cannot silently activate unsupported campaign behavior. */
	public function test_unknown_rule_fields_are_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		SignupBonusRules::from_array( array( 'coupon_campaign_id' => 12 ) );
	}
}
