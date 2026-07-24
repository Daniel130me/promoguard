<?php
/**
 * Administration presenter tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use PromoGuard\Api\AdministrationPresenter;

/** Covers stable report response types without WordPress runtime dependencies. */
final class AdministrationPresenterTest extends TestCase {
	/** Usage rows expose numeric identifiers and normalized GMT values. */
	public function test_usage_page_normalizes_database_scalar_types(): void {
		$result = ( new AdministrationPresenter() )->usages(
			array(
				'items'    => array(
					array(
						'id'              => '19',
						'campaign_id'     => '3',
						'customer_id'     => '8',
						'wp_user_id'      => null,
						'order_id'        => '42',
						'discount_amount' => '12.50000000',
						'consumed_at_gmt' => '2026-07-24 10:30:00',
						'restored_at_gmt' => null,
						'created_at_gmt'  => '2026-07-24 10:20:00',
					),
				),
				'total'    => 1,
				'page'     => 1,
				'per_page' => 20,
			)
		);

		self::assertSame( 19, $result['items'][0]['id'] );
		self::assertSame( 42, $result['items'][0]['order_id'] );
		self::assertNull( $result['items'][0]['wp_user_id'] );
		self::assertSame( '12.50000000', $result['items'][0]['discount_amount'] );
		self::assertSame( '2026-07-24T10:30:00Z', $result['items'][0]['consumed_at_gmt'] );
		self::assertNull( $result['items'][0]['restored_at_gmt'] );
	}

	/** Decision pages preserve safe explanation text and pagination metadata. */
	public function test_decision_page_preserves_safe_fields(): void {
		$result = ( new AdministrationPresenter() )->decisions(
			array(
				'items'    => array(
					array(
						'id'                => '7',
						'campaign_id'       => null,
						'customer_id'       => '4',
						'order_id'          => null,
						'reason'            => 'login_required',
						'admin_explanation' => 'The campaign requires authentication.',
						'created_at_gmt'    => '2026-07-24 11:00:00',
					),
				),
				'total'    => 31,
				'page'     => 2,
				'per_page' => 20,
			)
		);

		self::assertNull( $result['items'][0]['campaign_id'] );
		self::assertSame( 4, $result['items'][0]['customer_id'] );
		self::assertSame( 'login_required', $result['items'][0]['reason'] );
		self::assertSame( '2026-07-24T11:00:00Z', $result['items'][0]['created_at_gmt'] );
		self::assertSame( 31, $result['total'] );
	}
}
