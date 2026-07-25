<?php
/**
 * Order revenue calculator tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Analytics;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PromoGuard\Analytics\AnalyticsFilter;
use PromoGuard\Analytics\OrderIdPageSource;
use PromoGuard\Analytics\OrderProvider;
use PromoGuard\Analytics\OrderRevenueCalculator;
use PromoGuard\Analytics\OrderSnapshot;
use RuntimeException;

/** Covers bounded paging, currency isolation, and defensive cursor validation. */
final class OrderRevenueCalculatorTest extends TestCase {
	/** Verify bounded pages and isolated currency totals. */
	public function test_it_loads_orders_in_bounded_pages_and_keeps_currencies_separate(): void {
		$order_ids = new class() implements OrderIdPageSource {
			/**
			 * Recorded exclusive cursors.
			 *
			 * @var int[]
			 */
			public array $cursors = array();

			/**
			 * Return the next deterministic test page.
			 *
			 * @param AnalyticsFilter $filter         Report filter.
			 * @param int             $after_order_id Exclusive cursor.
			 * @param int             $limit          Page limit.
			 */
			public function order_ids_after( AnalyticsFilter $filter, int $after_order_id, int $limit ): array {
				$this->cursors[] = $after_order_id;
				$all             = range( 1, 101 );

				return array_slice(
					array_values( array_filter( $all, static fn ( int $id ): bool => $id > $after_order_id ) ),
					0,
					$limit
				);
			}
		};
		$orders    = new class() implements OrderProvider {
			/**
			 * Recorded provider pages.
			 *
			 * @var array<int,int[]>
			 */
			public array $pages = array();

			/**
			 * Return snapshots for the supplied test IDs.
			 *
			 * @param int[] $order_ids Order IDs.
			 */
			public function find_by_ids( array $order_ids ): array {
				$this->pages[] = $order_ids;

				return array_map(
					static fn ( int $id ): OrderSnapshot => new OrderSnapshot(
						101 === $id ? 'EUR' : 'USD',
						101 === $id ? '5' : '10'
					),
					$order_ids
				);
			}
		};

		$totals = ( new OrderRevenueCalculator( $order_ids, $orders ) )->totals( $this->filter() );

		self::assertSame( array( 0, 100 ), $order_ids->cursors );
		self::assertCount( 2, $orders->pages );
		self::assertSame(
			array(
				'EUR' => array(
					'order_count'    => 1,
					'revenue_amount' => '5',
				),
				'USD' => array(
					'order_count'    => 100,
					'revenue_amount' => '1000',
				),
			),
			$totals
		);
	}

	/** Verify duplicate cursors fail closed. */
	public function test_it_rejects_non_advancing_pages(): void {
		$order_ids = new class() implements OrderIdPageSource {
			/**
			 * Return the next deterministic test page.
			 *
			 * @param AnalyticsFilter $filter         Report filter.
			 * @param int             $after_order_id Exclusive cursor.
			 * @param int             $limit          Page limit.
			 */
			public function order_ids_after( AnalyticsFilter $filter, int $after_order_id, int $limit ): array {
				return array( 2, 2 );
			}
		};
		$orders    = new class() implements OrderProvider {
			/**
			 * Return snapshots for the supplied test IDs.
			 *
			 * @param int[] $order_ids Order IDs.
			 */
			public function find_by_ids( array $order_ids ): array {
				return array();
			}
		};

		$this->expectException( RuntimeException::class );
		( new OrderRevenueCalculator( $order_ids, $orders ) )->totals( $this->filter() );
	}

	/** Build one valid test period. */
	private function filter(): AnalyticsFilter {
		return new AnalyticsFilter(
			new DateTimeImmutable( '2026-01-01T00:00:00+00:00' ),
			new DateTimeImmutable( '2026-02-01T00:00:00+00:00' )
		);
	}
}
