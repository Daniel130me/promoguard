<?php
/**
 * Order usage context tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Reservation;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PromoGuard\Campaign\CampaignConfiguration;
use PromoGuard\Reservation\OrderUsageContext;

/** Verifies persisted lifecycle context boundaries. */
final class OrderUsageContextTest extends TestCase {
	/** Valid persisted campaign facts remain available to lifecycle policy. */
	public function test_preserves_usage_snapshot(): void {
		$rules   = CampaignConfiguration::defaults()->usage_rules();
		$context = new OrderUsageContext( 5, 'WELCOME10', $rules );

		self::assertSame( 5, $context->campaign_id );
		self::assertSame( 'WELCOME10', $context->coupon_code );
		self::assertSame( $rules, $context->usage_rules );
	}

	/** Invalid persisted campaign identity fails closed. */
	public function test_rejects_invalid_campaign_id(): void {
		$this->expectException( InvalidArgumentException::class );
		new OrderUsageContext( 0, null, CampaignConfiguration::defaults()->usage_rules() );
	}
}
