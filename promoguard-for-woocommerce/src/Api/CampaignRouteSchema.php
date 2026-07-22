<?php
/**
 * Campaign REST route schemas.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Api;

use PromoGuard\Campaign\CampaignConfiguration;
use PromoGuard\Campaign\CampaignStatus;
use PromoGuard\Promotion\CouponDraft;

/** Centralizes validation and sanitization metadata for campaign endpoints. */
final class CampaignRouteSchema {
	private const MAX_PAGE_SIZE = 100;

	/**
	 * Return campaign collection arguments.
	 *
	 * @return array<string,mixed>
	 */
	public static function campaign_collection(): array {
		return array(
			'page'     => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'per_page' => self::per_page(),
			'status'   => array(
				'type'     => 'string',
				'enum'     => CampaignStatus::stored(),
				'required' => false,
			),
		);
	}

	/**
	 * Return campaign mutation arguments.
	 *
	 * @param bool $creating Whether required creation fields should be enforced.
	 * @return array<string,mixed>
	 */
	public static function campaign_mutation( bool $creating ): array {
		return array(
			'name'           => array(
				'type'              => 'string',
				'required'          => $creating,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'slug'           => array(
				'type'              => 'string',
				'required'          => $creating,
				'sanitize_callback' => 'sanitize_title',
			),
			'description'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'goal'           => self::nullable_text(),
			'status'         => array(
				'type' => 'string',
				'enum' => CampaignStatus::stored(),
			),
			'priority'       => array( 'type' => 'integer' ),
			'starts_at_gmt'  => self::nullable_date(),
			'ends_at_gmt'    => self::nullable_date(),
			'usage_rules'    => self::usage_rules(),
			'conflict_rules' => self::conflict_rules(),
			'settings'       => self::settings(),
		);
	}

	/**
	 * Return coupon creation arguments.
	 *
	 * @return array<string,mixed>
	 */
	public static function coupon(): array {
		return array(
			'code'          => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'discount_type' => array(
				'type'     => 'string',
				'required' => true,
				'enum'     => CouponDraft::discount_types(),
			),
			'amount'        => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'description'   => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
		);
	}

	/**
	 * Return assignment mutation arguments.
	 *
	 * @return array<string,mixed>
	 */
	public static function assignment(): array {
		return array(
			'external_id'        => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'allow_reassignment' => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'channel'            => self::nullable_text(),
			'label'              => self::nullable_text(),
			'sort_order'         => array(
				'type'    => 'integer',
				'default' => 0,
			),
			'settings'           => array(
				'type'                 => 'object',
				'default'              => array(),
				'additionalProperties' => true,
			),
		);
	}

	/**
	 * Return route identifier arguments.
	 *
	 * @return array<string,mixed>
	 */
	public static function identifiers(): array {
		return array( 'id' => self::positive_integer() );
	}

	/**
	 * Return a positive integer schema.
	 *
	 * @return array<string,mixed>
	 */
	public static function positive_integer(): array {
		return array(
			'type'     => 'integer',
			'minimum'  => 1,
			'required' => true,
		);
	}

	/**
	 * Return a bounded page-size schema.
	 *
	 * @return array<string,mixed>
	 */
	public static function per_page(): array {
		return array(
			'type'    => 'integer',
			'default' => 20,
			'minimum' => 1,
			'maximum' => self::MAX_PAGE_SIZE,
		);
	}

	/**
	 * Return supported usage-rule schema.
	 *
	 * @return array<string,mixed>
	 */
	private static function usage_rules(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'maximum_uses'            => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'period'                  => array(
					'type' => 'string',
					'enum' => array( CampaignConfiguration::PERIOD_LIFETIME ),
				),
				'counted_statuses'        => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'string',
						'enum' => array( 'processing', 'completed', 'on-hold' ),
					),
				),
				'release_on_failure'      => array( 'type' => 'boolean' ),
				'release_on_cancellation' => array( 'type' => 'boolean' ),
				'refund_behavior'         => array(
					'type' => 'string',
					'enum' => array(
						CampaignConfiguration::REFUND_RESTORE,
						CampaignConfiguration::REFUND_KEEP_CONSUMED,
						CampaignConfiguration::REFUND_MANUAL_REVIEW,
					),
				),
			),
		);
	}

	/**
	 * Return supported conflict-rule schema.
	 *
	 * @return array<string,mixed>
	 */
	private static function conflict_rules(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'maximum_campaign_coupons_per_order' => array(
					'type' => 'integer',
					'enum' => array( CampaignConfiguration::MAXIMUM_CAMPAIGN_COUPONS_PER_ORDER ),
				),
			),
		);
	}

	/**
	 * Return supported settings schema.
	 *
	 * @return array<string,mixed>
	 */
	private static function settings(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'login_required' => array( 'type' => 'boolean' ),
			),
		);
	}

	/**
	 * Return a nullable sanitized text schema.
	 *
	 * @return array<string,mixed>
	 */
	private static function nullable_text(): array {
		return array(
			'type'              => array( 'string', 'null' ),
			'sanitize_callback' => static function ( mixed $value ): ?string {
				return is_string( $value ) ? sanitize_text_field( $value ) : null;
			},
		);
	}

	/**
	 * Return a nullable ISO-8601 date schema.
	 *
	 * @return array<string,mixed>
	 */
	private static function nullable_date(): array {
		return array(
			'type'   => array( 'string', 'null' ),
			'format' => 'date-time',
		);
	}
}
