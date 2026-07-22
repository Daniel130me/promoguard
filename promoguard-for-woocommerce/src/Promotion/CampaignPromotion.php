<?php
/**
 * Campaign promotion assignment snapshot.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Promotion;

use DateTimeImmutable;
use InvalidArgumentException;

/** Represents one active source-promotion assignment. */
final class CampaignPromotion {
	private const MAX_SOURCE_LENGTH = 50;
	private const MAX_CODE_LENGTH   = 255;
	private const MAX_LABEL_LENGTH  = 191;

	/**
	 * Create a validated assignment snapshot.
	 *
	 * @param int|null            $id             Persisted assignment ID.
	 * @param string              $uuid           Stable assignment UUID.
	 * @param int                 $campaign_id    Owning campaign ID.
	 * @param string              $source         Promotion source key.
	 * @param string              $source_type    Source promotion type.
	 * @param string              $external_id    Stable source identifier.
	 * @param string|null         $external_code  Coupon/code snapshot.
	 * @param string|null         $channel        Optional acquisition channel.
	 * @param string|null         $label          Optional administrative label.
	 * @param int                 $sort_order     Administrative sort order.
	 * @param array<string,mixed> $settings     Assignment-specific settings.
	 * @param DateTimeImmutable   $created_at_gmt Creation timestamp.
	 * @param DateTimeImmutable   $updated_at_gmt Update timestamp.
	 * @throws InvalidArgumentException When assignment data is inconsistent.
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly string $uuid,
		public readonly int $campaign_id,
		public readonly string $source,
		public readonly string $source_type,
		public readonly string $external_id,
		public readonly ?string $external_code,
		public readonly ?string $channel,
		public readonly ?string $label,
		public readonly int $sort_order,
		public readonly array $settings,
		public readonly DateTimeImmutable $created_at_gmt,
		public readonly DateTimeImmutable $updated_at_gmt
	) {
		$this->validate();
	}

	/**
	 * Validate identifiers, lengths, and persistence timestamps.
	 *
	 * @throws InvalidArgumentException When assignment data is inconsistent.
	 */
	private function validate(): void {
		if ( null !== $this->id && $this->id < 1 ) {
			throw new InvalidArgumentException( 'Assignment ID must be positive.' );
		}

		if ( $this->campaign_id < 1 ) {
			throw new InvalidArgumentException( 'Assignment campaign ID must be positive.' );
		}

		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $this->uuid ) ) {
			throw new InvalidArgumentException( 'Assignment UUID must be a valid version 4 UUID.' );
		}

		self::assert_required_length( $this->source, self::MAX_SOURCE_LENGTH );
		self::assert_required_length( $this->source_type, self::MAX_SOURCE_LENGTH );
		self::assert_required_length( $this->external_id, self::MAX_LABEL_LENGTH );
		self::assert_optional_length( $this->external_code, self::MAX_CODE_LENGTH );
		self::assert_optional_length( $this->channel, self::MAX_SOURCE_LENGTH );
		self::assert_optional_length( $this->label, self::MAX_LABEL_LENGTH );

		if ( 0 !== $this->created_at_gmt->getOffset() || 0 !== $this->updated_at_gmt->getOffset() ) {
			throw new InvalidArgumentException( 'Assignment timestamps must use GMT.' );
		}

		if ( $this->updated_at_gmt < $this->created_at_gmt ) {
			throw new InvalidArgumentException( 'Assignment update cannot precede creation.' );
		}
	}

	/**
	 * Validate a required indexed identifier.
	 *
	 * @param string $value      Identifier value.
	 * @param int    $max_length Database column length.
	 * @throws InvalidArgumentException When the value is empty or too long.
	 */
	private static function assert_required_length( string $value, int $max_length ): void {
		if ( '' === trim( $value ) || strlen( $value ) > $max_length ) {
			throw new InvalidArgumentException( 'Promotion assignment identifier is invalid.' );
		}
	}

	/**
	 * Validate an optional indexed string.
	 *
	 * @param string|null $value      Optional value.
	 * @param int         $max_length Database column length.
	 * @throws InvalidArgumentException When the value is too long.
	 */
	private static function assert_optional_length( ?string $value, int $max_length ): void {
		if ( null !== $value && strlen( $value ) > $max_length ) {
			throw new InvalidArgumentException( 'Promotion assignment text is too long.' );
		}
	}
}
