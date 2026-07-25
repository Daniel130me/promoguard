<?php
/**
 * Versioned analytics result cache.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Analytics;

use PromoGuard\Support\Options;

/** Caches bounded reports and invalidates them without tracking unbounded keys. */
final class CachedAnalyticsStore implements AnalyticsStore {
	private const CACHE_SECONDS = 300;
	private const KEY_PREFIX    = 'promoguard_analytics_';

	/**
	 * Wrap the authoritative analytics repository.
	 *
	 * @param AnalyticsStore $store Uncached analytics store.
	 */
	public function __construct( private readonly AnalyticsStore $store ) {}

	/** Register the shared mutation signal once during plugin boot. */
	public static function register_invalidation(): void {
		add_action( 'promoguard_analytics_changed', array( self::class, 'invalidate' ) );
	}

	/**
	 * Return one cached or freshly loaded summary.
	 *
	 * @param AnalyticsFilter $filter Validated report filters.
	 */
	public function summary( AnalyticsFilter $filter ): array {
		$key    = $this->cache_key( $filter );
		$cached = $this->normalize( get_transient( $key ) );

		if ( null !== $cached ) {
			return $cached;
		}

		$summary = $this->store->summary( $filter );
		set_transient( $key, $summary, self::CACHE_SECONDS );

		return $summary;
	}

	/** Advance the bounded cache namespace after report-affecting mutations. */
	public static function invalidate(): void {
		static $invalidated = false;

		if ( $invalidated ) {
			return;
		}

		update_option( Options::ANALYTICS_CACHE_VERSION, self::version() + 1, false );
		$invalidated = true;
	}

	/**
	 * Build a deterministic key without storing filter payloads in option names.
	 *
	 * @param AnalyticsFilter $filter Validated report filters.
	 */
	private function cache_key( AnalyticsFilter $filter ): string {
		$parts = array(
			(string) self::version(),
			$filter->database_start(),
			$filter->database_end(),
			(string) ( $filter->campaign_id ?? 0 ),
		);

		return self::KEY_PREFIX . md5( implode( '|', $parts ) );
	}

	/** Return a stable positive cache namespace. */
	private static function version(): int {
		return max( 1, (int) get_option( Options::ANALYTICS_CACHE_VERSION, 1 ) );
	}

	/**
	 * Rebuild the strict result shape at the untrusted transient boundary.
	 *
	 * @param mixed $value Cached value.
	 * @return array{
	 *   totals: array{redemptions:int,unique_customers:int,campaign_orders:int,global_orders:int,refunds:int,denials:int},
	 *   currencies: array<int,array{
	 *     currency:string,redemptions:int,refunds:int,discount_amount:string,restored_discount_amount:string,
	 *     order_count:int,revenue_amount:string,average_order_amount:string,average_discount_amount:string
	 *   }>,
	 *   denial_reasons: array<int,array{reason:string,count:int}>
	 * }|null
	 */
	private function normalize( mixed $value ): ?array {
		if (
			! is_array( $value )
			|| ! isset( $value['totals'], $value['currencies'], $value['denial_reasons'] )
			|| ! is_array( $value['totals'] )
			|| ! is_array( $value['currencies'] )
			|| ! is_array( $value['denial_reasons'] )
		) {
			return null;
		}

		$total_keys = array( 'redemptions', 'unique_customers', 'campaign_orders', 'global_orders', 'refunds', 'denials' );
		foreach ( $total_keys as $key ) {
			if ( ! isset( $value['totals'][ $key ] ) || ! is_numeric( $value['totals'][ $key ] ) ) {
				return null;
			}
		}
		$totals = array(
			'redemptions'      => (int) $value['totals']['redemptions'],
			'unique_customers' => (int) $value['totals']['unique_customers'],
			'campaign_orders'  => (int) $value['totals']['campaign_orders'],
			'global_orders'    => (int) $value['totals']['global_orders'],
			'refunds'          => (int) $value['totals']['refunds'],
			'denials'          => (int) $value['totals']['denials'],
		);

		$currencies = array();
		foreach ( $value['currencies'] as $row ) {
			if (
				! is_array( $row )
				|| ! isset(
					$row['currency'],
					$row['redemptions'],
					$row['refunds'],
					$row['discount_amount'],
					$row['restored_discount_amount'],
					$row['order_count'],
					$row['revenue_amount'],
					$row['average_order_amount'],
					$row['average_discount_amount']
				)
				|| ! is_string( $row['currency'] )
				|| ! is_numeric( $row['redemptions'] )
				|| ! is_numeric( $row['refunds'] )
				|| ! is_numeric( $row['discount_amount'] )
				|| ! is_numeric( $row['restored_discount_amount'] )
				|| ! is_numeric( $row['order_count'] )
				|| ! is_numeric( $row['revenue_amount'] )
				|| ! is_numeric( $row['average_order_amount'] )
				|| ! is_numeric( $row['average_discount_amount'] )
			) {
				return null;
			}
			$currencies[] = array(
				'currency'                 => $row['currency'],
				'redemptions'              => (int) $row['redemptions'],
				'refunds'                  => (int) $row['refunds'],
				'discount_amount'          => (string) $row['discount_amount'],
				'restored_discount_amount' => (string) $row['restored_discount_amount'],
				'order_count'              => (int) $row['order_count'],
				'revenue_amount'           => (string) $row['revenue_amount'],
				'average_order_amount'     => (string) $row['average_order_amount'],
				'average_discount_amount'  => (string) $row['average_discount_amount'],
			);
		}
		$reasons = array();
		foreach ( $value['denial_reasons'] as $row ) {
			if (
				! is_array( $row )
				|| ! isset( $row['reason'], $row['count'] )
				|| ! is_string( $row['reason'] )
				|| ! is_numeric( $row['count'] )
			) {
				return null;
			}
			$reasons[] = array(
				'reason' => $row['reason'],
				'count'  => (int) $row['count'],
			);
		}

		return array(
			'totals'         => $totals,
			'currencies'     => $currencies,
			'denial_reasons' => $reasons,
		);
	}
}
