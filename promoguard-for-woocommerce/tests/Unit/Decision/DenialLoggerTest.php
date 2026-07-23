<?php
/**
 * Denial logger tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Decision;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use PromoGuard\Campaign\Campaign;
use PromoGuard\Campaign\CampaignConfiguration;
use PromoGuard\Campaign\CampaignStatus;
use PromoGuard\Checkout\CheckoutEvaluation;
use PromoGuard\Checkout\CheckoutTarget;
use PromoGuard\Decision\DenialLogger;
use PromoGuard\Eligibility\EligibilityDecision;
use PromoGuard\Promotion\CampaignPromotion;
use PromoGuard\Promotion\WooCommerceCouponSource;
use PromoGuard\Tests\Support\InMemoryDecisionStore;

/** Verifies privacy-safe, request-local denial deduplication. */
final class DenialLoggerTest extends TestCase {
	/** Allowed decisions never create diagnostic rows. */
	public function test_allowed_decision_is_not_recorded(): void {
		$store  = new InMemoryDecisionStore();
		$logger = new DenialLogger( $store );

		$logger->record( self::evaluation( true ), 'request-1', DenialLogger::CONTEXT_COUPON_VALIDATION, self::time() );

		self::assertSame( array(), $store->records );
	}

	/** Equivalent denials are recorded once within one request context. */
	public function test_denial_is_deduplicated_per_request_context(): void {
		$store      = new InMemoryDecisionStore();
		$logger     = new DenialLogger( $store );
		$evaluation = self::evaluation( false );

		$logger->record( $evaluation, 'request-1', DenialLogger::CONTEXT_CLASSIC_CHECKOUT, self::time() );
		$logger->record( $evaluation, 'request-1', DenialLogger::CONTEXT_CLASSIC_CHECKOUT, self::time() );

		self::assertCount( 1, $store->records );
		self::assertSame( 5, $store->records[0]->campaign_id );
		self::assertSame( 9, $store->records[0]->promotion_id );
		self::assertSame( 77, $store->records[0]->coupon_id );
		self::assertSame( 15, $store->records[0]->customer_id );
		self::assertSame( 'WELCOME10', $store->records[0]->coupon_code );
		self::assertSame( EligibilityDecision::CUSTOMER_LIMIT_REACHED, $store->records[0]->reason );
		self::assertSame( array(), $store->records[0]->metadata );
	}

	/** Different final-validation contexts retain their own denial evidence. */
	public function test_distinct_contexts_are_recorded_separately(): void {
		$store      = new InMemoryDecisionStore();
		$logger     = new DenialLogger( $store );
		$evaluation = self::evaluation( false );

		$logger->record( $evaluation, 'request-1', DenialLogger::CONTEXT_CLASSIC_CHECKOUT, self::time() );
		$logger->record( $evaluation, 'request-1', DenialLogger::CONTEXT_STORE_API, self::time(), 101 );

		self::assertCount( 2, $store->records );
		self::assertSame( 101, $store->records[1]->order_id );
	}

	/**
	 * Build an allowed or denied checkout evaluation.
	 *
	 * @param bool $allowed Whether the decision allows checkout.
	 */
	private static function evaluation( bool $allowed ): CheckoutEvaluation {
		$time       = self::time();
		$assignment = new CampaignPromotion(
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
			configuration: CampaignConfiguration::defaults(),
			created_by: 1,
			created_at_gmt: $time,
			updated_at_gmt: $time
		);
		$decision   = $allowed
			? new EligibilityDecision( true, false, EligibilityDecision::ALLOWED, '', 'Allowed.' )
			: new EligibilityDecision(
				false,
				false,
				EligibilityDecision::CUSTOMER_LIMIT_REACHED,
				'Limit reached.',
				'Committed usage reached the limit.'
			);

		return new CheckoutEvaluation( new CheckoutTarget( $assignment, $campaign ), $decision, 15 );
	}

	/** Build a stable GMT timestamp. */
	private static function time(): DateTimeImmutable {
		return new DateTimeImmutable( '2026-07-23 12:00:00', new DateTimeZone( 'UTC' ) );
	}
}
