<?php
/**
 * Privacy retention application service.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Privacy;

use DateTimeImmutable;

/** Applies the documented decision-log retention policy. */
final class RetentionService {
	public const DECISION_RETENTION_DAYS = 365;
	public const BATCH_SIZE              = 250;

	/**
	 * Configure retention cleanup.
	 *
	 * @param RetentionStore $store Expired diagnostic store.
	 */
	public function __construct( private readonly RetentionStore $store ) {}

	/**
	 * Delete one bounded batch of expired decisions.
	 *
	 * @param DateTimeImmutable $now_gmt Current GMT time.
	 */
	public function cleanup( DateTimeImmutable $now_gmt ): int {
		$boundary = $now_gmt->modify( '-' . self::DECISION_RETENTION_DAYS . ' days' );

		return $this->store->delete_decisions_before( $boundary, self::BATCH_SIZE );
	}
}
