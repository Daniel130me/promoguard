<?php
/**
 * Reservation expiration application service.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

use DateTimeImmutable;
use InvalidArgumentException;

/** Validates and delegates one bounded expiration batch. */
final class ExpirationService {
	public const DEFAULT_BATCH_SIZE = 50;
	public const MAX_BATCH_SIZE     = 100;

	/**
	 * Configure expiration persistence.
	 *
	 * @param ExpirationStore $store Expired reservation persistence.
	 */
	public function __construct( private readonly ExpirationStore $store ) {}

	/**
	 * Release one bounded batch.
	 *
	 * @param DateTimeImmutable $expired_before_gmt Inclusive GMT expiry boundary.
	 * @param int               $candidate_limit    Maximum candidate rows to inspect.
	 * @throws InvalidArgumentException When the batch boundary is unsafe.
	 */
	public function release_batch(
		DateTimeImmutable $expired_before_gmt,
		int $candidate_limit = self::DEFAULT_BATCH_SIZE
	): int {
		if ( 0 !== $expired_before_gmt->getOffset() ) {
			throw new InvalidArgumentException( 'Reservation expiration boundary must use GMT.' );
		}

		if ( $candidate_limit < 1 || $candidate_limit > self::MAX_BATCH_SIZE ) {
			throw new InvalidArgumentException( 'Reservation expiration batch size is out of range.' );
		}

		return $this->store->release_expired( $expired_before_gmt, $candidate_limit );
	}
}
