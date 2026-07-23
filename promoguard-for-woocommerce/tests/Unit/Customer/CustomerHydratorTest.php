<?php
/**
 * Customer hydrator tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Customer;

use PHPUnit\Framework\TestCase;
use PromoGuard\Customer\CustomerHydrator;

/** Verifies strict conversion of persisted customer values. */
final class CustomerHydratorTest extends TestCase {
	/** Hydrate nullable identifiers and GMT timestamps. */
	public function test_hydrates_guest_customer(): void {
		$customer = ( new CustomerHydrator() )->from_row(
			array(
				'id'                      => '17',
				'wp_user_id'              => null,
				'merged_into_customer_id' => null,
				'created_at_gmt'          => '2026-07-23 08:30:00',
				'updated_at_gmt'          => '2026-07-23 09:15:00',
			)
		);

		self::assertSame( 17, $customer->id );
		self::assertNull( $customer->wp_user_id );
		self::assertTrue( $customer->is_guest() );
		self::assertSame( 0, $customer->created_at_gmt->getOffset() );
	}

	/** Preserve authoritative and merge identifiers as integers. */
	public function test_hydrates_identity_references(): void {
		$hydrator = new CustomerHydrator();
		$user     = $hydrator->from_row(
			array(
				'id'                      => '18',
				'wp_user_id'              => '42',
				'merged_into_customer_id' => null,
				'created_at_gmt'          => '2026-07-23 08:30:00',
				'updated_at_gmt'          => '2026-07-23 08:30:00',
			)
		);
		$merged   = $hydrator->from_row(
			array(
				'id'                      => '17',
				'wp_user_id'              => null,
				'merged_into_customer_id' => '18',
				'created_at_gmt'          => '2026-07-23 08:00:00',
				'updated_at_gmt'          => '2026-07-23 09:00:00',
			)
		);

		self::assertSame( 42, $user->wp_user_id );
		self::assertSame( 18, $merged->merged_into_customer_id );
		self::assertTrue( $merged->is_merged() );
	}
}
