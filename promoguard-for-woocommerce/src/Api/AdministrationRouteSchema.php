<?php
/**
 * Full-administration REST route schemas.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Api;

use PromoGuard\Reservation\UsageStatus;

/** Centralizes bounded history filters for the administration API. */
final class AdministrationRouteSchema {
	private const MAX_PAGE_SIZE = 100;

	/**
	 * Return usage-history arguments.
	 *
	 * @return array<string,mixed>
	 */
	public static function usages(): array {
		return array_merge(
			self::pagination(),
			self::identifiers(),
			array(
				'status' => array(
					'type'     => 'string',
					'required' => false,
					'enum'     => array(
						UsageStatus::PENDING,
						UsageStatus::CONSUMED,
						UsageStatus::RELEASED,
						UsageStatus::RESTORED,
					),
				),
			)
		);
	}

	/**
	 * Return decision-history arguments.
	 *
	 * @return array<string,mixed>
	 */
	public static function decisions(): array {
		return array_merge(
			self::pagination(),
			self::identifiers(),
			array(
				'reason' => array(
					'type'              => 'string',
					'required'          => false,
					'maxLength'         => 64,
					'sanitize_callback' => 'sanitize_key',
				),
			)
		);
	}

	/**
	 * Return bounded pagination arguments.
	 *
	 * @return array<string,mixed>
	 */
	private static function pagination(): array {
		return array(
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
		);
	}

	/**
	 * Return optional exact identifier filters.
	 *
	 * @return array<string,mixed>
	 */
	private static function identifiers(): array {
		$identifier = array(
			'type'     => 'integer',
			'required' => false,
			'minimum'  => 1,
		);

		return array(
			'campaign_id' => $identifier,
			'order_id'    => $identifier,
		);
	}
}
