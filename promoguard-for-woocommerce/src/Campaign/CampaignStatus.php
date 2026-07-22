<?php
/**
 * Campaign status policy.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Campaign;

use DateTimeImmutable;

/** Defines stored and schedule-derived campaign states. */
final class CampaignStatus {
	public const DRAFT     = 'draft';
	public const ACTIVE    = 'active';
	public const PAUSED    = 'paused';
	public const COMPLETED = 'completed';
	public const ARCHIVED  = 'archived';
	public const SCHEDULED = 'scheduled';

	/**
	 * Return stored status values.
	 *
	 * @return string[]
	 */
	public static function stored(): array {
		return array(
			self::DRAFT,
			self::ACTIVE,
			self::PAUSED,
			self::COMPLETED,
			self::ARCHIVED,
		);
	}

	/**
	 * Determine whether a value can be persisted.
	 *
	 * @param string $status Candidate status.
	 */
	public static function is_stored( string $status ): bool {
		return in_array( $status, self::stored(), true );
	}

	/**
	 * Resolve schedule boundaries without changing the stored status.
	 *
	 * @param string                 $status        Stored campaign status.
	 * @param DateTimeImmutable|null $starts_at_gmt Optional GMT start boundary.
	 * @param DateTimeImmutable|null $ends_at_gmt   Optional GMT end boundary.
	 * @param DateTimeImmutable      $now_gmt       Current GMT time.
	 */
	public static function effective(
		string $status,
		?DateTimeImmutable $starts_at_gmt,
		?DateTimeImmutable $ends_at_gmt,
		DateTimeImmutable $now_gmt
	): string {
		if ( self::ACTIVE !== $status ) {
			return $status;
		}

		if ( null !== $starts_at_gmt && $now_gmt < $starts_at_gmt ) {
			return self::SCHEDULED;
		}

		if ( null !== $ends_at_gmt && $now_gmt > $ends_at_gmt ) {
			return self::COMPLETED;
		}

		return self::ACTIVE;
	}

	/**
	 * Determine whether a campaign must preserve its configuration.
	 *
	 * @param string $status Stored campaign status.
	 */
	public static function is_read_only( string $status ): bool {
		return self::ARCHIVED === $status;
	}
}
