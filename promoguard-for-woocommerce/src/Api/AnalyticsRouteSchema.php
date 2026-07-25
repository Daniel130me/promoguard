<?php
/**
 * Analytics REST route schema.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Api;

/** Centralizes validated analytics report filters. */
final class AnalyticsRouteSchema {
	private const MAX_PAGE_SIZE = 50;

	/**
	 * Return summary report arguments.
	 *
	 * @return array<string,mixed>
	 */
	public static function summary(): array {
		$date = array(
			'type'     => 'string',
			'required' => false,
			'format'   => 'date-time',
		);

		return array(
			'starts_at_gmt' => $date,
			'ends_at_gmt'   => $date,
			'campaign_id'   => array(
				'type'     => 'integer',
				'required' => false,
				'minimum'  => 1,
			),
		);
	}
	/**
	 * Return paginated campaign report arguments.
	 *
	 * @return array<string,mixed>
	 */
	public static function campaigns(): array {
		return array_merge(
			self::summary(),
			array(
				'page'     => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'default' => 20,
					'minimum' => 1,
					'maximum' => self::MAX_PAGE_SIZE,
				),
			)
		);
	}
}
