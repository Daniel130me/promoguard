<?php
/**
 * Refund service tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Refund;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use PromoGuard\Campaign\CampaignConfiguration;
use PromoGuard\Refund\RefundEvent;
use PromoGuard\Refund\RefundService;
use PromoGuard\Refund\RefundUsageContext;
use PromoGuard\Tests\Support\InMemoryRefundStore;

/** Verifies cumulative refund policy routing and idempotent outcomes. */
final class RefundServiceTest extends TestCase {
	/** Partial refunds retain consumption without reading usage state. */
	public function test_partial_refund_short_circuits_without_usage_queries(): void {
		$store   = new InMemoryRefundStore();
		$service = new RefundService( $store, $store );

		self::assertSame( array(), $service->handle( self::event( false ) ) );
		self::assertSame( 0, $store->context_reads );
		self::assertSame( array(), $store->restorations );
	}

	/** Full refunds route each persisted policy independently. */
	public function test_full_refund_applies_persisted_campaign_policies(): void {
		$store           = new InMemoryRefundStore();
		$store->contexts = array(
			new RefundUsageContext( 5, CampaignConfiguration::REFUND_RESTORE ),
			new RefundUsageContext( 6, CampaignConfiguration::REFUND_KEEP_CONSUMED ),
			new RefundUsageContext( 7, CampaignConfiguration::REFUND_MANUAL_REVIEW ),
		);
		$service         = new RefundService( $store, $store );

		self::assertSame(
			array(
				5 => RefundService::RESTORED,
				6 => RefundService::KEPT,
				7 => RefundService::MANUAL_REVIEW,
			),
			$service->handle( self::event( true ) )
		);
		self::assertSame( 1, $store->context_reads );
		self::assertSame(
			array(
				array(
					'order_id'    => 101,
					'campaign_id' => 5,
				),
			),
			$store->restorations
		);
	}

	/** Repeated full-refund callbacks expose an unchanged persistence result. */
	public function test_already_restored_usage_is_unchanged(): void {
		$store                = new InMemoryRefundStore();
		$store->contexts      = array(
			new RefundUsageContext( 5, CampaignConfiguration::REFUND_RESTORE ),
		);
		$store->changes_state = false;

		self::assertSame(
			array( 5 => RefundService::UNCHANGED ),
			( new RefundService( $store, $store ) )->handle( self::event( true ) )
		);
	}

	/**
	 * Build one stable cumulative refund event.
	 *
	 * @param bool $is_full_refund Whether cumulative refunds cover the order.
	 */
	private static function event( bool $is_full_refund ): RefundEvent {
		return new RefundEvent(
			101,
			$is_full_refund,
			new DateTimeImmutable( '2026-07-23 14:00:00', new DateTimeZone( 'UTC' ) )
		);
	}
}
