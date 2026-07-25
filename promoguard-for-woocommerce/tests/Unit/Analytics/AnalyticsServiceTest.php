<?php
/**
 * Analytics summary service tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Analytics;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PromoGuard\Analytics\AnalyticsFilter;
use PromoGuard\Analytics\AnalyticsService;
use PromoGuard\Analytics\AnalyticsStore;
use PromoGuard\Analytics\OrderIdPageSource;
use PromoGuard\Analytics\OrderProvider;
use PromoGuard\Analytics\OrderRevenueCalculator;
use PromoGuard\Analytics\OrderSnapshot;

/** Covers revenue enrichment and currency-specific averages. */
final class AnalyticsServiceTest extends TestCase {
	/** Verify revenue enrichment and per-currency averages. */
	public function test_it_merges_revenue_and_calculates_averages_without_currency_conversion(): void {
		$ledger    = new class() implements AnalyticsStore {
			/**
			 * Return fixed ledger facts.
			 *
			 * @param AnalyticsFilter $filter Report filter.
			 */
			public function summary( AnalyticsFilter $filter ): array {
				return array(
					'totals'         => array(
						'redemptions'      => 3,
						'unique_customers' => 2,
						'campaign_orders'  => 3,
						'global_orders'    => 2,
						'refunds'          => 1,
						'denials'          => 0,
					),
					'currencies'     => array(
						array(
							'currency'                 => 'EUR',
							'redemptions'              => 1,
							'refunds'                  => 0,
							'discount_amount'          => '3',
							'restored_discount_amount' => '0',
						),
						array(
							'currency'                 => 'USD',
							'redemptions'              => 2,
							'refunds'                  => 1,
							'discount_amount'          => '10',
							'restored_discount_amount' => '4',
						),
					),
					'denial_reasons' => array(),
				);
			}
		};
		$order_ids = new class() implements OrderIdPageSource {
			/**
			 * Return the only test order once.
			 *
			 * @param AnalyticsFilter $filter         Report filter.
			 * @param int             $after_order_id Exclusive cursor.
			 * @param int             $limit          Page limit.
			 */
			public function order_ids_after( AnalyticsFilter $filter, int $after_order_id, int $limit ): array {
				return 0 === $after_order_id ? array( 7 ) : array();
			}
		};
		$orders    = new class() implements OrderProvider {
			/**
			 * Return one USD order snapshot.
			 *
			 * @param int[] $order_ids Order IDs.
			 */
			public function find_by_ids( array $order_ids ): array {
				return array( new OrderSnapshot( 'USD', '30' ) );
			}
		};
		$service   = new AnalyticsService(
			$ledger,
			new OrderRevenueCalculator( $order_ids, $orders )
		);

		$summary = $service->summary(
			new AnalyticsFilter(
				new DateTimeImmutable( '2026-01-01T00:00:00+00:00' ),
				new DateTimeImmutable( '2026-02-01T00:00:00+00:00' )
			)
		);

		self::assertSame(
			array(
				'currency'                 => 'EUR',
				'redemptions'              => 1,
				'refunds'                  => 0,
				'discount_amount'          => '3',
				'restored_discount_amount' => '0',
				'order_count'              => 0,
				'revenue_amount'           => '0',
				'average_order_amount'     => '0',
				'average_discount_amount'  => '3',
			),
			$summary['currencies'][0]
		);
		self::assertSame( '30', $summary['currencies'][1]['average_order_amount'] );
		self::assertSame( '5', $summary['currencies'][1]['average_discount_amount'] );
	}
}
