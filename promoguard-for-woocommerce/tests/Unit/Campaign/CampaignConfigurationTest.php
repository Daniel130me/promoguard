<?php
/**
 * Campaign configuration tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Campaign;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PromoGuard\Campaign\CampaignConfiguration;

/** Covers the supported campaign rule boundary. */
final class CampaignConfigurationTest extends TestCase {
	/** Defaults express the private-beta campaign behavior. */
	public function test_defaults_are_supported_and_explicit(): void {
		$configuration = CampaignConfiguration::defaults();

		self::assertSame( 1, $configuration->usage_rules()['maximum_uses'] );
		self::assertSame( 'lifetime', $configuration->usage_rules()['period'] );
		self::assertSame( array( 'processing', 'completed' ), $configuration->usage_rules()['counted_statuses'] );
		self::assertSame( 'restore', $configuration->usage_rules()['refund_behavior'] );
		self::assertSame( 1, $configuration->conflict_rules()['maximum_campaign_coupons_per_order'] );
		self::assertFalse( $configuration->settings()['login_required'] );
	}

	/**
	 * Unsupported or dormant settings are rejected.
	 *
	 * @param array<string, mixed> $usage     Candidate usage rules.
	 * @param array<string, mixed> $conflicts Candidate conflict rules.
	 * @param array<string, mixed> $settings  Candidate general settings.
	 */
	#[DataProvider( 'invalid_configurations' )]
	public function test_invalid_configuration_is_rejected( array $usage, array $conflicts, array $settings ): void {
		$this->expectException( InvalidArgumentException::class );

		CampaignConfiguration::from_arrays( $usage, $conflicts, $settings );
	}

	/**
	 * Provide unsupported configuration samples.
	 *
	 * @return array<string, array{array<string, mixed>, array<string, mixed>, array<string, mixed>}>
	 */
	public static function invalid_configurations(): array {
		return array(
			'zero maximum uses'       => array( array( 'maximum_uses' => 0 ), array(), array() ),
			'unsupported period'      => array( array( 'period' => 'monthly' ), array(), array() ),
			'unsupported order limit' => array( array(), array( 'maximum_campaign_coupons_per_order' => 2 ), array() ),
			'unknown setting'         => array( array(), array(), array( 'cooldown_days' => 30 ) ),
			'unsupported status'      => array( array( 'counted_statuses' => array( 'pending' ) ), array(), array() ),
		);
	}
}
