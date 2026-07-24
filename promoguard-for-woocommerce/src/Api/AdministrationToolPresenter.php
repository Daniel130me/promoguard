<?php
/**
 * Administration settings and tool response mapper.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Api;

use PromoGuard\Indexing\IndexingJob;

/** Maps settings and indexing state to stable, transport-safe resources. */
final class AdministrationToolPresenter {
	private const DATE_FORMAT = 'Y-m-d\TH:i:s\Z';

	/**
	 * Present implemented settings and storage health.
	 *
	 * @param array{delete_data_on_uninstall:bool,storage_engine_supported:bool|null,storage_engine_checked_at_gmt:string|null} $settings Stored settings.
	 * @return array<string,mixed>
	 */
	public function settings( array $settings ): array {
		$checked = $settings['storage_engine_checked_at_gmt'];
		if ( null !== $checked ) {
			$settings['storage_engine_checked_at_gmt'] = str_replace( ' ', 'T', $checked ) . 'Z';
		}

		return $settings;
	}

	/**
	 * Present the current or most recent historical-indexing job.
	 *
	 * @param IndexingJob|null $job Persisted job, when one exists.
	 * @return array<string,mixed>|null
	 */
	public function indexing( ?IndexingJob $job ): ?array {
		if ( null === $job ) {
			return null;
		}

		return array(
			'id'               => $job->id,
			'status'           => $job->status,
			'target_order_ids' => $job->target_order_ids,
			'targeted'         => $job->is_targeted(),
			'batch_size'       => $job->batch_size,
			'page'             => $job->page,
			'processed'        => $job->processed,
			'imported'         => $job->imported,
			'skipped'          => $job->skipped,
			'failed'           => $job->failed,
			'errors'           => $job->errors,
			'created_at_gmt'   => $job->created_at_gmt->format( self::DATE_FORMAT ),
			'updated_at_gmt'   => $job->updated_at_gmt->format( self::DATE_FORMAT ),
		);
	}
}
