<?php
/**
 * Complete analytics summary service.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Analytics;

/** Enriches ledger facts with HPOS-compatible WooCommerce revenue metrics. */
final class AnalyticsService implements AnalyticsStore {
	/**
	 * Configure ledger and order analytics.
	 *
	 * @param AnalyticsStore         $ledger  Plugin-owned ledger facts.
	 * @param OrderRevenueCalculator $revenue WooCommerce order metrics.
	 */
	public function __construct(
		private readonly AnalyticsStore $ledger,
		private readonly OrderRevenueCalculator $revenue
	) {}

	/**
	 * Return the complete currency-separated summary.
	 *
	 * @param AnalyticsFilter $filter Validated report filters.
	 */
	public function summary( AnalyticsFilter $filter ): array {
		$summary = $this->ledger->summary( $filter );
		$revenue = $this->revenue->totals( $filter );
		$rows    = array();

		foreach ( $summary['currencies'] as $currency ) {
			$rows[ $currency['currency'] ] = $currency;
		}

		foreach ( $revenue as $currency => $order_totals ) {
			$rows[ $currency ] ??= array(
				'currency'                 => $currency,
				'redemptions'              => 0,
				'refunds'                  => 0,
				'discount_amount'          => '0',
				'restored_discount_amount' => '0',
			);
			$rows[ $currency ]   = array_merge( $rows[ $currency ], $order_totals );
		}

		foreach ( $rows as &$row ) {
			$row['order_count']           ??= 0;
			$row['revenue_amount']        ??= '0';
			$row['average_order_amount']    = $this->average( $row['revenue_amount'], $row['order_count'] );
			$row['average_discount_amount'] = $this->average( $row['discount_amount'], $row['redemptions'] );
		}
		unset( $row );

		ksort( $rows );
		$summary['currencies'] = array_values( $rows );

		return $summary;
	}

	/**
	 * Divide one decimal amount by a non-negative fact count.
	 *
	 * @param string $amount Decimal amount.
	 * @param int    $count  Fact count.
	 */
	private function average( string $amount, int $count ): string {
		if ( 0 === $count ) {
			return '0';
		}

		return OrderRevenueCalculator::decimal( (float) $amount / $count );
	}
}
