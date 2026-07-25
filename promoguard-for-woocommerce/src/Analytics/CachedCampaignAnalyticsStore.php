<?php
/**
 * Cached campaign analytics pages.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Analytics;

use PromoGuard\Support\Options;

/**
 * Caches campaign pages inside the shared mutation-version namespace.
 *
 * @phpstan-type CurrencyRow array{currency:string,redemptions:int,refunds:int,discount_amount:string,restored_discount_amount:string,average_discount_amount:string}
 * @phpstan-type CampaignRow array{campaign_id:int,campaign_name:string,redemptions:int,unique_customers:int,campaign_orders:int,refunds:int,denials:int,currencies:array<int,CurrencyRow>}
 * @phpstan-type CampaignPage array{items:array<int,CampaignRow>,total:int,page:int,per_page:int}
 */
final class CachedCampaignAnalyticsStore implements CampaignAnalyticsStore {
	private const CACHE_SECONDS = 300;
	private const KEY_PREFIX    = 'promoguard_campaign_analytics_';

	/**
	 * Wrap the authoritative campaign analytics store.
	 *
	 * @param CampaignAnalyticsStore $store Uncached store.
	 */
	public function __construct( private readonly CampaignAnalyticsStore $store ) {}

	/**
	 * Return one cached or freshly loaded campaign page.
	 *
	 * @param AnalyticsFilter $filter   Validated report filters.
	 * @param int             $page     One-based page.
	 * @param int             $per_page Requested page size.
	 */
	public function campaigns( AnalyticsFilter $filter, int $page, int $per_page ): array {
		$page     = max( 1, $page );
		$per_page = min( 50, max( 1, $per_page ) );
		$key      = $this->cache_key( $filter, $page, $per_page );
		$cached   = $this->normalize( get_transient( $key ) );

		if ( null !== $cached ) {
			return $cached;
		}

		$result = $this->store->campaigns( $filter, $page, $per_page );
		set_transient( $key, $result, self::CACHE_SECONDS );

		return $result;
	}

	/**
	 * Build a deterministic key in the shared analytics version namespace.
	 *
	 * @param AnalyticsFilter $filter   Validated report filters.
	 * @param int             $page     One-based page.
	 * @param int             $per_page Bounded page size.
	 */
	private function cache_key( AnalyticsFilter $filter, int $page, int $per_page ): string {
		$parts = array(
			(string) max( 1, (int) get_option( Options::ANALYTICS_CACHE_VERSION, 1 ) ),
			$filter->database_start(),
			$filter->database_end(),
			(string) ( $filter->campaign_id ?? 0 ),
			(string) $page,
			(string) $per_page,
		);

		return self::KEY_PREFIX . md5( implode( '|', $parts ) );
	}

	/**
	 * Rebuild the stable page shape at the untrusted transient boundary.
	 *
	 * @param mixed $value Cached value.
	 * @return CampaignPage|null
	 */
	private function normalize( mixed $value ): ?array {
		if (
			! is_array( $value )
			|| ! isset( $value['items'], $value['total'], $value['page'], $value['per_page'] )
			|| ! is_array( $value['items'] )
			|| ! is_numeric( $value['total'] )
			|| ! is_numeric( $value['page'] )
			|| ! is_numeric( $value['per_page'] )
		) {
			return null;
		}

		$items = array();
		foreach ( $value['items'] as $item ) {
			$normalized = $this->normalize_item( $item );
			if ( null === $normalized ) {
				return null;
			}
			$items[] = $normalized;
		}

		return array(
			'items'    => $items,
			'total'    => (int) $value['total'],
			'page'     => (int) $value['page'],
			'per_page' => (int) $value['per_page'],
		);
	}

	/**
	 * Normalize one campaign item.
	 *
	 * @param mixed $item Cached campaign item.
	 * @return CampaignRow|null
	 */
	private function normalize_item( mixed $item ): ?array {
		$integer_keys = array(
			'campaign_id',
			'redemptions',
			'unique_customers',
			'campaign_orders',
			'refunds',
			'denials',
		);
		if (
			! is_array( $item )
			|| ! isset( $item['campaign_name'], $item['currencies'] )
			|| ! is_string( $item['campaign_name'] )
			|| ! is_array( $item['currencies'] )
		) {
			return null;
		}
		foreach ( $integer_keys as $key ) {
			if ( ! isset( $item[ $key ] ) || ! is_numeric( $item[ $key ] ) ) {
				return null;
			}
		}

		$currencies = array();
		foreach ( $item['currencies'] as $currency ) {
			$normalized = $this->normalize_currency( $currency );
			if ( null === $normalized ) {
				return null;
			}
			$currencies[] = $normalized;
		}

		return array(
			'campaign_id'      => (int) $item['campaign_id'],
			'campaign_name'    => $item['campaign_name'],
			'redemptions'      => (int) $item['redemptions'],
			'unique_customers' => (int) $item['unique_customers'],
			'campaign_orders'  => (int) $item['campaign_orders'],
			'refunds'          => (int) $item['refunds'],
			'denials'          => (int) $item['denials'],
			'currencies'       => $currencies,
		);
	}

	/**
	 * Normalize one currency row.
	 *
	 * @param mixed $currency Cached currency row.
	 * @return CurrencyRow|null
	 */
	private function normalize_currency( mixed $currency ): ?array {
		$numeric_keys = array(
			'redemptions',
			'refunds',
			'discount_amount',
			'restored_discount_amount',
			'average_discount_amount',
		);
		if (
			! is_array( $currency )
			|| ! isset( $currency['currency'] )
			|| ! is_string( $currency['currency'] )
		) {
			return null;
		}
		foreach ( $numeric_keys as $key ) {
			if ( ! isset( $currency[ $key ] ) || ! is_numeric( $currency[ $key ] ) ) {
				return null;
			}
		}

		return array(
			'currency'                 => $currency['currency'],
			'redemptions'              => (int) $currency['redemptions'],
			'refunds'                  => (int) $currency['refunds'],
			'discount_amount'          => (string) $currency['discount_amount'],
			'restored_discount_amount' => (string) $currency['restored_discount_amount'],
			'average_discount_amount'  => (string) $currency['average_discount_amount'],
		);
	}
}
