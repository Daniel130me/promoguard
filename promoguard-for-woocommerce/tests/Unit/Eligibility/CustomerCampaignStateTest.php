<?php
/**
 * Customer campaign state tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Eligibility;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PromoGuard\Eligibility\CustomerCampaignState;

/** Verifies bounded state invariants and committed usage totals. */
final class CustomerCampaignStateTest extends TestCase {
	/** Reservations and consumptions both count against the campaign limit. */
	public function test_committed_count_includes_active_reservations(): void {
		$state = self::state( consumed_count: 2, reserved_count: 3 );

		self::assertSame( 5, $state->committed_count() );
	}

	/**
	 * Invalid persisted counters are rejected.
	 *
	 * @param int $campaign_id    Campaign ID.
	 * @param int $consumed_count Consumed count.
	 * @param int $reserved_count Reserved count.
	 */
	#[DataProvider( 'invalid_counters' )]
	public function test_invalid_counters_are_rejected( int $campaign_id, int $consumed_count, int $reserved_count ): void {
		$this->expectException( InvalidArgumentException::class );

		self::state( $campaign_id, $consumed_count, $reserved_count );
	}

	/**
	 * Provide invalid identifier and counter combinations.
	 *
	 * @return array<string,array{int,int,int}>
	 */
	public static function invalid_counters(): array {
		return array(
			'invalid campaign'     => array( 0, 0, 0 ),
			'negative consumed'    => array( 1, -1, 0 ),
			'negative reservation' => array( 1, 0, -1 ),
		);
	}

	/**
	 * Build a valid state with selected counters.
	 *
	 * @param int $campaign_id    Campaign ID.
	 * @param int $consumed_count Consumed count.
	 * @param int $reserved_count Reserved count.
	 */
	private static function state( int $campaign_id = 1, int $consumed_count = 0, int $reserved_count = 0 ): CustomerCampaignState {
		$gmt = new DateTimeZone( 'UTC' );

		return new CustomerCampaignState(
			campaign_id: $campaign_id,
			customer_id: 2,
			consumed_count: $consumed_count,
			reserved_count: $reserved_count,
			total_discount: '0.00000000',
			first_consumed_at_gmt: new DateTimeImmutable( '2026-07-23 10:00:00', $gmt ),
			last_consumed_at_gmt: new DateTimeImmutable( '2026-07-23 11:00:00', $gmt ),
			last_order_id: 3,
			lock_version: 0
		);
	}
}
