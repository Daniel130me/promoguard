<?php
/**
 * Analytics REST route schema.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Api;

/** Centralizes validated analytics report filters. */
final class AnalyticsRouteSchema {
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
}
