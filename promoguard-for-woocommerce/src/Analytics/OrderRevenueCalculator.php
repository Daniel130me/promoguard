<?php
/**
 * WooCommerce order-revenue analytics.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Analytics;

use RuntimeException;

/** Calculates currency-separated revenue from bounded WooCommerce order pages. */
final class OrderRevenueCalculator {
	private const PAGE_SIZE = 100;

	/**
	 * Configure ledger paging and WooCommerce order loading.
	 *
	 * @param OrderIdPageSource $order_ids Distinct order-ID source.
	 * @param OrderProvider     $orders    HPOS-compatible order provider.
	 */
	public function __construct(
		private readonly OrderIdPageSource $order_ids,
		private readonly OrderProvider $orders
	) {}

	/**
	 * Return order count and revenue grouped by order currency.
	 *
	 * @param AnalyticsFilter $filter Validated report filters.
	 * @return array<string,array{order_count:int,revenue_amount:string}>
	 */
	public function totals( AnalyticsFilter $filter ): array {
		$cursor = 0;
		$totals = array();

		do {
			$order_ids = $this->order_ids->order_ids_after( $filter, $cursor, self::PAGE_SIZE );
			$this->assert_page( $order_ids, $cursor );

			foreach ( $this->orders->find_by_ids( $order_ids ) as $order ) {
				$currency = strtoupper( $order->currency );
				if ( '' === $currency ) {
					continue;
				}

				$totals[ $currency ] ??= array(
					'order_count'    => 0,
					'revenue_amount' => 0.0,
				);
				++$totals[ $currency ]['order_count'];
				$totals[ $currency ]['revenue_amount'] += (float) $order->total;
			}

			$page_count = count( $order_ids );
			$cursor     = array() === $order_ids ? $cursor : max( $order_ids );
		} while ( self::PAGE_SIZE === $page_count );

		ksort( $totals );

		return array_map(
			static fn ( array $row ): array => array(
				'order_count'    => $row['order_count'],
				'revenue_amount' => self::decimal( $row['revenue_amount'] ),
			),
			$totals
		);
	}

	/**
	 * Reject malformed pages before they can cause duplicate totals or an infinite loop.
	 *
	 * @param int[] $order_ids Order-ID page.
	 * @param int   $cursor    Exclusive order-ID cursor.
	 * @throws RuntimeException When the page is oversized, duplicated, or non-ascending.
	 */
	private function assert_page( array $order_ids, int $cursor ): void {
		if ( count( $order_ids ) > self::PAGE_SIZE ) {
			throw new RuntimeException( 'Analytics order page exceeded its bounded size.' );
		}

		$previous = $cursor;
		foreach ( $order_ids as $order_id ) {
			if ( $order_id <= $previous ) {
				throw new RuntimeException( 'Analytics order pages must contain ascending, unique IDs.' );
			}
			$previous = $order_id;
		}
	}

	/**
	 * Format one calculated amount without currency conversion.
	 *
	 * @param float $amount Calculated amount.
	 */
	public static function decimal( float $amount ): string {
		$formatted = rtrim( rtrim( number_format( $amount, 8, '.', '' ), '0' ), '.' );

		return '' === $formatted || '-0' === $formatted ? '0' : $formatted;
	}
}
