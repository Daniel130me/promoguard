<?php
/**
 * Usage lifecycle service tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Reservation;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use PromoGuard\Campaign\CampaignConfiguration;
use PromoGuard\Reservation\UsageLifecycleService;
use PromoGuard\Reservation\UsageTransition;
use PromoGuard\Tests\Support\InMemoryUsageLifecycleStore;

/** Verifies configured counted and release status routing. */
final class UsageLifecycleServiceTest extends TestCase {
	/** Processing consumes one pending reservation. */
	public function test_counted_status_consumes_usage(): void {
		$store  = new InMemoryUsageLifecycleStore();
		$result = ( new UsageLifecycleService( $store ) )->transition(
			self::transition( 'processing' ),
			self::rules()
		);

		self::assertSame( UsageLifecycleService::CONSUMED, $result );
		self::assertSame( array( 'consume' ), $store->operations );
	}

	/** Enabled failure and cancellation policies release pending usage. */
	public function test_failure_and_cancellation_release_usage(): void {
		$store   = new InMemoryUsageLifecycleStore();
		$service = new UsageLifecycleService( $store );

		self::assertSame( UsageLifecycleService::RELEASED, $service->transition( self::transition( 'failed' ), self::rules() ) );
		self::assertSame( UsageLifecycleService::RELEASED, $service->transition( self::transition( 'cancelled' ), self::rules() ) );
		self::assertSame( array( 'release', 'release' ), $store->operations );
	}

	/** Non-counted statuses and disabled release rules perform no writes. */
	public function test_non_counted_and_disabled_release_statuses_are_ignored(): void {
		$store                       = new InMemoryUsageLifecycleStore();
		$service                     = new UsageLifecycleService( $store );
		$rules                       = self::rules();
		$rules['release_on_failure'] = false;

		self::assertSame( UsageLifecycleService::UNCHANGED, $service->transition( self::transition( 'pending' ), $rules ) );
		self::assertSame( UsageLifecycleService::UNCHANGED, $service->transition( self::transition( 'failed' ), $rules ) );
		self::assertSame( array(), $store->operations );
	}

	/** Repeated persistence callbacks report an unchanged idempotent outcome. */
	public function test_already_transitioned_usage_is_unchanged(): void {
		$store                = new InMemoryUsageLifecycleStore();
		$store->changes_state = false;

		$result = ( new UsageLifecycleService( $store ) )->transition(
			self::transition( 'completed' ),
			self::rules()
		);

		self::assertSame( UsageLifecycleService::UNCHANGED, $result );
		self::assertSame( array( 'consume' ), $store->operations );
	}

	/**
	 * Return validated default campaign usage rules.
	 *
	 * @return array<string,mixed>
	 */
	private static function rules(): array {
		return CampaignConfiguration::defaults()->usage_rules();
	}

	/**
	 * Build one stable order transition.
	 *
	 * @param string $status WooCommerce order status.
	 */
	private static function transition( string $status ): UsageTransition {
		return new UsageTransition(
			campaign_id: 5,
			order_id: 101,
			order_status: $status,
			discount_amount: '10.50',
			occurred_at_gmt: new DateTimeImmutable( '2026-07-23 13:00:00', new DateTimeZone( 'UTC' ) )
		);
	}
}
