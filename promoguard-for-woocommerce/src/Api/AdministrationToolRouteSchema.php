<?php
/**
 * Administration settings and tool route schemas.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Api;

use PromoGuard\Indexing\IndexingJob;
use PromoGuard\SignupBonus\SignupBonusRules;

/** Centralizes closed settings and bounded indexing-tool inputs. */
final class AdministrationToolRouteSchema {
	/**
	 * Return implemented settings mutation arguments.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$amount = array(
			'type'     => 'string',
			'required' => true,
			'pattern'  => '^(?:0|[1-9][0-9]{0,17})(?:\\.[0-9]{1,8})?$',
		);

		return array(
			'delete_data_on_uninstall' => array(
				'type'     => 'boolean',
				'required' => true,
			),
			'store_credit'             => array(
				'type'                 => 'object',
				'required'             => true,
				'additionalProperties' => false,
				'properties'           => array(
					'redemption_enabled' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			),
			'signup_bonus'             => array(
				'type'                 => 'object',
				'required'             => true,
				'additionalProperties' => false,
				'properties'           => array(
					'customer_event'  => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( SignupBonusRules::EVENT_REGISTRATION, SignupBonusRules::EVENT_DISABLED ),
					),
					'customer_amount' => $amount,
					'vendor_event'    => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( SignupBonusRules::EVENT_REGISTRATION, SignupBonusRules::EVENT_APPROVAL, SignupBonusRules::EVENT_DISABLED ),
					),
					'vendor_amount'   => $amount,
				),
			),
		);
	}

	/**
	 * Return historical-indexing start arguments.
	 *
	 * @return array<string,mixed>
	 */
	public static function indexing_start(): array {
		return array(
			'order_ids'  => array(
				'type'     => 'array',
				'required' => false,
				'default'  => array(),
				'maxItems' => IndexingJob::MAXIMUM_TARGETS,
				'items'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'batch_size' => array(
				'type'     => 'integer',
				'required' => false,
				'default'  => IndexingJob::DEFAULT_BATCH_SIZE,
				'minimum'  => 1,
				'maximum'  => IndexingJob::MAXIMUM_BATCH_SIZE,
			),
		);
	}
}
