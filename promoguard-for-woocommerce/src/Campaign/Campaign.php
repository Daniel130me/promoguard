<?php
/**
 * Campaign aggregate.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Campaign;

use DateTimeImmutable;
use InvalidArgumentException;

/** Represents one validated campaign record. */
final class Campaign {
	private const MAX_IDENTIFIER_LENGTH = 191;

	/**
	 * Create an immutable campaign record.
	 *
	 * @param int|null               $id                Persisted database identifier.
	 * @param string                 $uuid              Stable public identifier.
	 * @param string                 $name              Administrative campaign name.
	 * @param string                 $slug              Unique normalized slug.
	 * @param string                 $description       Optional campaign description.
	 * @param string|null            $goal              Optional administrative goal.
	 * @param string                 $status            Stored campaign status.
	 * @param int                    $priority          Evaluation priority.
	 * @param DateTimeImmutable|null $starts_at_gmt    Optional GMT start boundary.
	 * @param DateTimeImmutable|null $ends_at_gmt      Optional GMT end boundary.
	 * @param CampaignConfiguration  $configuration     Validated campaign rules.
	 * @param int|null               $created_by        WordPress creator user ID.
	 * @param DateTimeImmutable      $created_at_gmt    Creation time in GMT.
	 * @param DateTimeImmutable      $updated_at_gmt    Last update time in GMT.
	 * @throws InvalidArgumentException When campaign data is inconsistent.
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly string $uuid,
		public readonly string $name,
		public readonly string $slug,
		public readonly string $description,
		public readonly ?string $goal,
		public readonly string $status,
		public readonly int $priority,
		public readonly ?DateTimeImmutable $starts_at_gmt,
		public readonly ?DateTimeImmutable $ends_at_gmt,
		public readonly CampaignConfiguration $configuration,
		public readonly ?int $created_by,
		public readonly DateTimeImmutable $created_at_gmt,
		public readonly DateTimeImmutable $updated_at_gmt
	) {
		$this->validate();
	}

	/**
	 * Resolve the current effective status from stored state and schedule.
	 *
	 * @param DateTimeImmutable $now_gmt Current GMT time.
	 * @throws InvalidArgumentException When the current time is not GMT.
	 */
	public function effective_status( DateTimeImmutable $now_gmt ): string {
		self::assert_gmt( $now_gmt );

		return CampaignStatus::effective(
			$this->status,
			$this->starts_at_gmt,
			$this->ends_at_gmt,
			$now_gmt
		);
	}

	/**
	 * Validate invariants shared by creation and hydration.
	 *
	 * @throws InvalidArgumentException When campaign data is inconsistent.
	 */
	private function validate(): void {
		if ( null !== $this->id && $this->id < 1 ) {
			throw new InvalidArgumentException( 'Campaign ID must be positive.' );
		}

		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $this->uuid ) ) {
			throw new InvalidArgumentException( 'Campaign UUID must be a valid version 4 UUID.' );
		}

		self::assert_identifier( $this->name );
		self::assert_identifier( $this->slug );

		if ( null !== $this->goal && strlen( $this->goal ) > self::MAX_IDENTIFIER_LENGTH ) {
			throw new InvalidArgumentException( 'Campaign goal is too long.' );
		}

		if ( ! CampaignStatus::is_stored( $this->status ) ) {
			throw new InvalidArgumentException( 'Unsupported campaign status.' );
		}

		foreach ( array_filter( array( $this->starts_at_gmt, $this->ends_at_gmt ) ) as $boundary ) {
			self::assert_gmt( $boundary );
		}

		if (
			null !== $this->starts_at_gmt
			&& null !== $this->ends_at_gmt
			&& $this->starts_at_gmt >= $this->ends_at_gmt
		) {
			throw new InvalidArgumentException( 'Campaign end must be after its start.' );
		}

		self::assert_gmt( $this->created_at_gmt );
		self::assert_gmt( $this->updated_at_gmt );

		if ( $this->updated_at_gmt < $this->created_at_gmt ) {
			throw new InvalidArgumentException( 'Campaign update cannot precede creation.' );
		}

		if ( null !== $this->created_by && $this->created_by < 1 ) {
			throw new InvalidArgumentException( 'Campaign creator ID must be positive.' );
		}
	}

	/**
	 * Validate a required, indexed text identifier.
	 *
	 * @param string $value Identifier value.
	 * @throws InvalidArgumentException When the identifier is empty or too long.
	 */
	private static function assert_identifier( string $value ): void {
		if ( '' === trim( $value ) ) {
			throw new InvalidArgumentException( 'Campaign name and slug are required.' );
		}

		if ( strlen( $value ) > self::MAX_IDENTIFIER_LENGTH ) {
			throw new InvalidArgumentException( 'Campaign identifier is too long.' );
		}
	}

	/**
	 * Enforce timezone-independent persistence values.
	 *
	 * @param DateTimeImmutable $value Time value to inspect.
	 * @throws InvalidArgumentException When the value does not have a zero UTC offset.
	 */
	private static function assert_gmt( DateTimeImmutable $value ): void {
		if ( 0 !== $value->getOffset() ) {
			throw new InvalidArgumentException( 'Campaign time values must use GMT.' );
		}
	}
}
