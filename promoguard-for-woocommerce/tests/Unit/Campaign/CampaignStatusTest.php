<?php
/**
 * Campaign status policy tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Campaign;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use PromoGuard\Campaign\CampaignStatus;

/** Covers stored and schedule-derived campaign statuses. */
final class CampaignStatusTest extends TestCase {
	/** Active campaigns become scheduled before their GMT start. */
	public function test_future_active_campaign_is_scheduled(): void {
		$timezone = new DateTimeZone( 'UTC' );

		self::assertSame(
			CampaignStatus::SCHEDULED,
			CampaignStatus::effective(
				CampaignStatus::ACTIVE,
				new DateTimeImmutable( '2026-07-23 00:00:00', $timezone ),
				null,
				new DateTimeImmutable( '2026-07-22 00:00:00', $timezone )
			)
		);
	}

	/** Active campaigns become completed after their GMT end. */
	public function test_expired_active_campaign_is_completed(): void {
		$timezone = new DateTimeZone( 'UTC' );

		self::assertSame(
			CampaignStatus::COMPLETED,
			CampaignStatus::effective(
				CampaignStatus::ACTIVE,
				null,
				new DateTimeImmutable( '2026-07-21 23:59:59', $timezone ),
				new DateTimeImmutable( '2026-07-22 00:00:00', $timezone )
			)
		);
	}

	/** Explicit administrative states are never overridden by scheduling. */
	public function test_paused_campaign_remains_paused(): void {
		$timezone = new DateTimeZone( 'UTC' );

		self::assertSame(
			CampaignStatus::PAUSED,
			CampaignStatus::effective(
				CampaignStatus::PAUSED,
				new DateTimeImmutable( '2026-07-23 00:00:00', $timezone ),
				null,
				new DateTimeImmutable( '2026-07-22 00:00:00', $timezone )
			)
		);
	}
}
