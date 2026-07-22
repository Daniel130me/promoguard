<?php
/**
 * Campaign promotion assignment tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Promotion;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PromoGuard\Promotion\CampaignPromotion;

/** Covers assignment snapshot invariants. */
final class CampaignPromotionTest extends TestCase {
	/** Valid assignment snapshots retain source identity. */
	public function test_valid_assignment_retains_identity(): void {
		$assignment = self::assignment();

		self::assertSame( 10, $assignment->campaign_id );
		self::assertSame( 'woocommerce', $assignment->source );
		self::assertSame( '42', $assignment->external_id );
	}

	/**
	 * Invalid assignment snapshots fail before persistence.
	 *
	 * @param array<string,mixed> $overrides Invalid property overrides.
	 */
	#[DataProvider( 'invalid_assignments' )]
	public function test_invalid_assignment_is_rejected( array $overrides ): void {
		$this->expectException( InvalidArgumentException::class );

		self::assignment( $overrides );
	}

	/**
	 * Provide invalid snapshot values.
	 *
	 * @return array<string,array{array<string,mixed>}>
	 */
	public static function invalid_assignments(): array {
		return array(
			'invalid UUID'      => array( array( 'uuid' => 'invalid' ) ),
			'invalid campaign'  => array( array( 'campaign_id' => 0 ) ),
			'empty source'      => array( array( 'source' => '' ) ),
			'empty external ID' => array( array( 'external_id' => '' ) ),
			'non-GMT timestamp' => array(
				array(
					'created_at_gmt' => new DateTimeImmutable( '2026-07-22 00:00:00', new DateTimeZone( 'Africa/Lagos' ) ),
				),
			),
		);
	}

	/**
	 * Build an assignment with focused overrides.
	 *
	 * @param array<string,mixed> $overrides Property overrides.
	 */
	private static function assignment( array $overrides = array() ): CampaignPromotion {
		$gmt    = new DateTimeZone( 'UTC' );
		$values = array_replace(
			array(
				'id'             => null,
				'uuid'           => '123e4567-e89b-42d3-a456-426614174000',
				'campaign_id'    => 10,
				'source'         => 'woocommerce',
				'source_type'    => 'coupon',
				'external_id'    => '42',
				'external_code'  => 'WELCOME10',
				'channel'        => null,
				'label'          => 'Welcome offer',
				'sort_order'     => 0,
				'settings'       => array(),
				'created_at_gmt' => new DateTimeImmutable( '2026-07-22 00:00:00', $gmt ),
				'updated_at_gmt' => new DateTimeImmutable( '2026-07-22 00:00:00', $gmt ),
			),
			$overrides
		);

		return new CampaignPromotion( ...$values );
	}
}
