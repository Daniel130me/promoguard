<?php
/**
 * Historical indexing job statuses.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Indexing;

use InvalidArgumentException;

/** Centralizes the persisted historical-indexing state machine. */
final class IndexingStatus {
	public const QUEUED    = 'queued';
	public const RUNNING   = 'running';
	public const PAUSED    = 'paused';
	public const COMPLETED = 'completed';
	public const FAILED    = 'failed';

	/**
	 * Whether a status is supported.
	 *
	 * @param string $status Candidate status.
	 */
	public static function is_valid( string $status ): bool {
		return in_array(
			$status,
			array( self::QUEUED, self::RUNNING, self::PAUSED, self::COMPLETED, self::FAILED ),
			true
		);
	}

	/**
	 * Require one supported status.
	 *
	 * @param string $status Candidate status.
	 * @throws InvalidArgumentException When the status is unsupported.
	 */
	public static function assert_valid( string $status ): void {
		if ( ! self::is_valid( $status ) ) {
			throw new InvalidArgumentException( 'Historical indexing status is invalid.' );
		}
	}
}
