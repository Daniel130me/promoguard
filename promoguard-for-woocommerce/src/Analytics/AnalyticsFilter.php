<?php
/**
 * Validated analytics report filters.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Analytics;

use DateTimeImmutable;
use DomainException;

/** Carries a bounded half-open GMT reporting period and optional campaign. */
final class AnalyticsFilter {
	public const MAX_DAYS = 366;
	private const SECONDS_PER_DAY = 86400;

	/**
	 * Validate analytics filters at the domain boundary.
	 *
	 * @param DateTimeImmutable $starts_at_gmt Inclusive report start.
	 * @param DateTimeImmutable $ends_at_gmt   Exclusive report end.
	 * @param int|null          $campaign_id   Optional campaign identifier.
	 */
	public function __construct(
		public readonly DateTimeImmutable $starts_at_gmt,
		public readonly DateTimeImmutable $ends_at_gmt,
		public readonly ?int $campaign_id = null
	) {
		if ( 0 !== $starts_at_gmt->getOffset() || 0 !== $ends_at_gmt->getOffset() ) {
			throw new DomainException( 'Analytics dates must use GMT.' );
		}
		if ( $ends_at_gmt <= $starts_at_gmt ) {
			throw new DomainException( 'Analytics end date must be later than the start date.' );
		}
		if ( $ends_at_gmt->getTimestamp() - $starts_at_gmt->getTimestamp() > self::MAX_DAYS * self::SECONDS_PER_DAY ) {
			throw new DomainException( 'Analytics date ranges cannot exceed 366 days.' );
		}
		if ( null !== $campaign_id && $campaign_id < 1 ) {
			throw new DomainException( 'Analytics campaign filters require a positive identifier.' );
		}
	}

	/** Return the inclusive database timestamp. */
	public function database_start(): string {
		return $this->starts_at_gmt->format( 'Y-m-d H:i:s' );
	}

	/** Return the exclusive database timestamp. */
	public function database_end(): string {
		return $this->ends_at_gmt->format( 'Y-m-d H:i:s' );
	}
}
