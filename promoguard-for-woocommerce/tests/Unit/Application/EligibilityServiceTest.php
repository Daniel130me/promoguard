<?php
/**
 * Eligibility application service tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use PromoGuard\Application\EligibilityService;
use PromoGuard\Campaign\Campaign;
use PromoGuard\Campaign\CampaignConfiguration;
use PromoGuard\Campaign\CampaignStatus;
use PromoGuard\Customer\EmailNormalizer;
use PromoGuard\Customer\IdentifierHasher;
use PromoGuard\Customer\IdentityResolver;
use PromoGuard\Eligibility\EligibilityDecision;
use PromoGuard\Eligibility\EligibilityEngine;
use PromoGuard\Tests\Support\CountingCustomerCampaignStateStore;
use PromoGuard\Tests\Support\InMemoryCustomerIdentityStore;

/** Verifies identity and policy orchestration without WordPress persistence. */
final class EligibilityServiceTest extends TestCase {
	/** Authenticated identity is created, hashed, and approved through one service. */
	public function test_authenticated_customer_is_resolved_and_evaluated(): void {
		$identities = new InMemoryCustomerIdentityStore();
		$states     = new CountingCustomerCampaignStateStore();
		$service    = new EligibilityService(
			new IdentityResolver(
				$identities,
				new EmailNormalizer(),
				new IdentifierHasher( str_repeat( 'ab', 32 ) )
			),
			new EligibilityEngine( $states )
		);

		$decision = $service->evaluate(
			self::campaign(),
			42,
			' Customer@example.com ',
			0,
			self::time( '2026-07-23 12:00:00' )
		);

		self::assertSame( EligibilityDecision::ALLOWED, $decision->reason );
		self::assertCount( 1, $identities->customers );
		self::assertSame( 42, $identities->customers[1]->wp_user_id );
		self::assertSame( 1, $states->calls );
	}

	/** Build an active persisted campaign. */
	private static function campaign(): Campaign {
		return new Campaign(
			id: 1,
			uuid: '123e4567-e89b-42d3-a456-426614174000',
			name: 'Private beta',
			slug: 'private-beta',
			description: '',
			goal: null,
			status: CampaignStatus::ACTIVE,
			priority: 0,
			starts_at_gmt: null,
			ends_at_gmt: null,
			configuration: CampaignConfiguration::defaults(),
			created_by: 1,
			created_at_gmt: self::time( '2026-07-22 12:00:00' ),
			updated_at_gmt: self::time( '2026-07-22 12:00:00' )
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
