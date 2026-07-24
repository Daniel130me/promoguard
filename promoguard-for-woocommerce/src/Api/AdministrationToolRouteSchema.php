<?php
/**
 * Administration settings and tool route schemas.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Api;

use PromoGuard\Indexing\IndexingJob;

/** Centralizes closed settings and bounded indexing-tool inputs. */
final class AdministrationToolRouteSchema {
	/**
	 * Return implemented settings mutation arguments.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		return array(
			'delete_data_on_uninstall' => array(
				'type'     => 'boolean',
				'required' => true,
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
