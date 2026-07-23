<?php
/**
 * WordPress historical indexing job persistence.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Indexing;

use PromoGuard\Support\Options;

/** Stores one bounded job snapshot in a non-autoloaded plugin option. */
final class WordPressIndexingJobStore implements IndexingJobStore {
	/**
	 * Configure option serialization.
	 *
	 * @param IndexingJobCodec $codec Job-state serializer.
	 */
	public function __construct( private readonly IndexingJobCodec $codec = new IndexingJobCodec() ) {}

	/**
	 * Return the current persisted job.
	 *
	 * @throws \InvalidArgumentException When stored state is malformed.
	 */
	public function current(): ?IndexingJob {
		$state = get_option( Options::HISTORICAL_INDEXING_JOB, array() );
		if ( array() === $state ) {
			return null;
		}

		if ( ! is_array( $state ) ) {
			throw new \InvalidArgumentException( 'Stored historical indexing job must be an array.' );
		}

		return $this->codec->decode( $state );
	}

	/**
	 * Persist a complete bounded job snapshot without autoloading it.
	 *
	 * @param IndexingJob $job Validated job state.
	 */
	public function save( IndexingJob $job ): void {
		update_option( Options::HISTORICAL_INDEXING_JOB, $this->codec->encode( $job ), false );
	}
}
