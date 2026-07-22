<?php
/**
 * Campaign input builder.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Campaign;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Converts transport-neutral input into validated campaign aggregates. */
final class CampaignBuilder {
	private const GMT = 'UTC';

	/**
	 * Build a new campaign from validated scalar input.
	 *
	 * @param array<string,mixed> $input      Campaign fields.
	 * @param string              $uuid       Generated version 4 UUID.
	 * @param int|null            $created_by WordPress creator user ID.
	 * @param DateTimeImmutable   $now_gmt    Current GMT time.
	 */
	public function create(
		array $input,
		string $uuid,
		?int $created_by,
		DateTimeImmutable $now_gmt
	): Campaign {
		return new Campaign(
			id: null,
			uuid: $uuid,
			name: $this->required_string( $input, 'name' ),
			slug: $this->required_string( $input, 'slug' ),
			description: $this->optional_string( $input, 'description', '' ),
			goal: $this->nullable_string( $input, 'goal', null ),
			status: $this->optional_string( $input, 'status', CampaignStatus::DRAFT ),
			priority: $this->optional_int( $input, 'priority', 0 ),
			starts_at_gmt: $this->optional_date( $input, 'starts_at_gmt', null ),
			ends_at_gmt: $this->optional_date( $input, 'ends_at_gmt', null ),
			configuration: $this->configuration( $input ),
			created_by: $created_by,
			created_at_gmt: $now_gmt,
			updated_at_gmt: $now_gmt
		);
	}

	/**
	 * Apply a partial campaign update without resetting omitted fields.
	 *
	 * @param Campaign            $campaign Existing campaign.
	 * @param array<string,mixed> $input    Changed campaign fields.
	 * @param DateTimeImmutable   $now_gmt  Current GMT time.
	 */
	public function update( Campaign $campaign, array $input, DateTimeImmutable $now_gmt ): Campaign {
		return new Campaign(
			id: $campaign->id,
			uuid: $campaign->uuid,
			name: $this->optional_string( $input, 'name', $campaign->name ),
			slug: $this->optional_string( $input, 'slug', $campaign->slug ),
			description: $this->optional_string( $input, 'description', $campaign->description ),
			goal: $this->nullable_string( $input, 'goal', $campaign->goal ),
			status: $this->optional_string( $input, 'status', $campaign->status ),
			priority: $this->optional_int( $input, 'priority', $campaign->priority ),
			starts_at_gmt: $this->optional_date( $input, 'starts_at_gmt', $campaign->starts_at_gmt ),
			ends_at_gmt: $this->optional_date( $input, 'ends_at_gmt', $campaign->ends_at_gmt ),
			configuration: $this->configuration( $input, $campaign->configuration ),
			created_by: $campaign->created_by,
			created_at_gmt: $campaign->created_at_gmt,
			updated_at_gmt: $now_gmt
		);
	}

	/**
	 * Merge provided rule groups with the current supported configuration.
	 *
	 * @param array<string,mixed>        $input   Campaign fields.
	 * @param CampaignConfiguration|null $current Existing configuration.
	 */
	private function configuration(
		array $input,
		?CampaignConfiguration $current = null
	): CampaignConfiguration {
		$defaults       = $current ?? CampaignConfiguration::defaults();
		$usage_rules    = $this->merged_array( $input, 'usage_rules', $defaults->usage_rules() );
		$conflict_rules = $this->merged_array( $input, 'conflict_rules', $defaults->conflict_rules() );
		$settings       = $this->merged_array( $input, 'settings', $defaults->settings() );

		return CampaignConfiguration::from_arrays( $usage_rules, $conflict_rules, $settings );
	}

	/**
	 * Read a required string.
	 *
	 * @param array<string,mixed> $input Campaign fields.
	 * @param string              $key   Field key.
	 * @throws InvalidArgumentException When the field is missing or not a string.
	 */
	private function required_string( array $input, string $key ): string {
		if ( ! array_key_exists( $key, $input ) ) {
			throw new InvalidArgumentException( 'A required campaign field is missing.' );
		}

		return $this->string( $input[ $key ] );
	}

	/**
	 * Read an optional string.
	 *
	 * @param array<string,mixed> $input   Campaign fields.
	 * @param string              $key     Field key.
	 * @param string              $fallback Existing or fallback value.
	 */
	private function optional_string( array $input, string $key, string $fallback ): string {
		return array_key_exists( $key, $input ) ? $this->string( $input[ $key ] ) : $fallback;
	}

	/**
	 * Read a nullable string.
	 *
	 * @param array<string,mixed> $input   Campaign fields.
	 * @param string              $key     Field key.
	 * @param string|null         $fallback Existing or fallback value.
	 * @throws InvalidArgumentException When a provided value is neither null nor a string.
	 */
	private function nullable_string( array $input, string $key, ?string $fallback ): ?string {
		if ( ! array_key_exists( $key, $input ) ) {
			return $fallback;
		}

		return null === $input[ $key ] ? null : $this->string( $input[ $key ] );
	}

	/**
	 * Read an optional integer.
	 *
	 * @param array<string,mixed> $input   Campaign fields.
	 * @param string              $key     Field key.
	 * @param int                 $fallback Existing or fallback value.
	 * @throws InvalidArgumentException When a provided value is not an integer.
	 */
	private function optional_int( array $input, string $key, int $fallback ): int {
		if ( ! array_key_exists( $key, $input ) ) {
			return $fallback;
		}

		if ( ! is_int( $input[ $key ] ) ) {
			throw new InvalidArgumentException( 'A campaign integer field is invalid.' );
		}

		return $input[ $key ];
	}

	/**
	 * Read and normalize an optional API timestamp.
	 *
	 * @param array<string,mixed>    $input   Campaign fields.
	 * @param string                 $key     Field key.
	 * @param DateTimeImmutable|null $fallback Existing or fallback value.
	 * @throws InvalidArgumentException When a provided timestamp is invalid.
	 */
	private function optional_date(
		array $input,
		string $key,
		?DateTimeImmutable $fallback
	): ?DateTimeImmutable {
		if ( ! array_key_exists( $key, $input ) ) {
			return $fallback;
		}

		if ( null === $input[ $key ] ) {
			return null;
		}

		if ( ! is_string( $input[ $key ] ) ) {
			throw new InvalidArgumentException( 'A campaign timestamp must be an ISO 8601 string or null.' );
		}

		try {
			return ( new DateTimeImmutable( $input[ $key ] ) )->setTimezone( new DateTimeZone( self::GMT ) );
		} catch ( \Exception $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is diagnostic context, never rendered.
			throw new InvalidArgumentException( 'A campaign timestamp is invalid.', 0, $exception );
		}
	}

	/**
	 * Merge a provided configuration group without discarding omitted keys.
	 *
	 * @param array<string,mixed> $input   Campaign fields.
	 * @param string              $key     Configuration group.
	 * @param array<string,mixed> $fallback Existing or fallback group.
	 * @return array<string,mixed>
	 * @throws InvalidArgumentException When a provided group is not an object-like array.
	 */
	private function merged_array( array $input, string $key, array $fallback ): array {
		if ( ! array_key_exists( $key, $input ) ) {
			return $fallback;
		}

		if ( ! is_array( $input[ $key ] ) ) {
			throw new InvalidArgumentException( 'A campaign configuration group must be an object.' );
		}

		return array_replace( $fallback, $input[ $key ] );
	}

	/**
	 * Validate one string value.
	 *
	 * @param mixed $value Candidate value.

	 * @throws InvalidArgumentException When the value is not a string.
	 */
	private function string( $value ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'A campaign text field is invalid.' );
		}

		return trim( $value );
	}
}
