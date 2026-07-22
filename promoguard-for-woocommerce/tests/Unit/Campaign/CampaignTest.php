<?php
/**
 * Campaign aggregate tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Campaign;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PromoGuard\Campaign\Campaign;
use PromoGuard\Campaign\CampaignConfiguration;
use PromoGuard\Campaign\CampaignStatus;

/** Covers campaign invariants before persistence. */
final class CampaignTest extends TestCase {
	/** Valid campaigns expose schedule-derived status without mutation. */
	public function test_effective_status_uses_validated_schedule(): void {
		$campaign = self::campaign(
			array(
				'starts_at_gmt' => self::gmt( '2026-08-01 00:00:00' ),
			)
		);

		self::assertSame( CampaignStatus::SCHEDULED, $campaign->effective_status( self::gmt( '2026-07-22 00:00:00' ) ) );
		self::assertSame( CampaignStatus::ACTIVE, $campaign->status );
	}

	/**
	 * Invalid persistence records are rejected before a repository call.
	 *
	 * @param array<string, mixed> $overrides Invalid property overrides.
	 */
	#[DataProvider( 'invalid_campaigns' )]
	public function test_invalid_campaign_is_rejected( array $overrides ): void {
		$this->expectException( InvalidArgumentException::class );

		self::campaign( $overrides );
	}

	/**
	 * Provide invalid campaign records.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function invalid_campaigns(): array {
		return array(
			'invalid UUID'       => array( array( 'uuid' => 'not-a-uuid' ) ),
			'empty name'         => array( array( 'name' => ' ' ) ),
			'empty slug'         => array( array( 'slug' => '' ) ),
			'unsupported status' => array( array( 'status' => 'deleted' ) ),
			'invalid schedule'   => array(
				array(
					'starts_at_gmt' => self::gmt( '2026-08-02 00:00:00' ),
					'ends_at_gmt'   => self::gmt( '2026-08-01 00:00:00' ),
				),
			),
			'non-GMT schedule'   => array(
				array(
					'starts_at_gmt' => new DateTimeImmutable( '2026-08-01 00:00:00', new DateTimeZone( 'Africa/Lagos' ) ),
				),
			),
		);
	}

	/**
	 * Build a campaign with focused test overrides.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 */
	private static function campaign( array $overrides = array() ): Campaign {
		$values = array_replace(
			array(
				'id'             => null,
				'uuid'           => '123e4567-e89b-42d3-a456-426614174000',
				'name'           => 'Customer Acquisition',
				'slug'           => 'customer-acquisition',
				'description'    => '',
				'goal'           => null,
				'status'         => CampaignStatus::ACTIVE,
				'priority'       => 0,
				'starts_at_gmt'  => null,
				'ends_at_gmt'    => null,
				'configuration'  => CampaignConfiguration::defaults(),
				'created_by'     => 1,
				'created_at_gmt' => self::gmt( '2026-07-22 00:00:00' ),
				'updated_at_gmt' => self::gmt( '2026-07-22 00:00:00' ),
			),
			$overrides
		);

		return new Campaign( ...$values );
	}

	/**
	 * Build a GMT test timestamp.
	 *
	 * @param string $time Timestamp value.
	 */
	private static function gmt( string $time ): DateTimeImmutable {
		return new DateTimeImmutable( $time, new DateTimeZone( 'UTC' ) );
	}
}
