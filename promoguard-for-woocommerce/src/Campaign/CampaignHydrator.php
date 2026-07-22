<?php
/**
 * Campaign persistence mapping.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Campaign;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;

/** Maps validated campaign aggregates to and from database rows. */
final class CampaignHydrator {
	private const DATE_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Hydrate a campaign from a selected database row.
	 *
	 * @param array<string, mixed> $row Campaign database row.
	 * @throws RuntimeException When persisted JSON or dates are invalid.
	 */
	public function from_row( array $row ): Campaign {
		return new Campaign(
			id: $this->required_int( $row, 'id' ),
			uuid: $this->required_string( $row, 'uuid' ),
			name: $this->required_string( $row, 'name' ),
			slug: $this->required_string( $row, 'slug' ),
			description: $this->nullable_string( $row, 'description' ) ?? '',
			goal: $this->nullable_string( $row, 'goal' ),
			status: $this->required_string( $row, 'status' ),
			priority: $this->required_int( $row, 'priority' ),
			starts_at_gmt: $this->nullable_date( $row, 'starts_at_gmt' ),
			ends_at_gmt: $this->nullable_date( $row, 'ends_at_gmt' ),
			configuration: CampaignConfiguration::from_arrays(
				$this->decode_object( $row, 'usage_rules' ),
				$this->decode_object( $row, 'conflict_rules' ),
				$this->decode_object( $row, 'settings' )
			),
			created_by: $this->nullable_int( $row, 'created_by' ),
			created_at_gmt: $this->required_date( $row, 'created_at_gmt' ),
			updated_at_gmt: $this->required_date( $row, 'updated_at_gmt' )
		);
	}

	/**
	 * Convert a campaign into database column values.
	 *
	 * @param Campaign $campaign Validated campaign.
	 * @return array<string, int|string|null>
	 * @throws JsonException When validated configuration cannot be encoded.
	 */
	public function to_row( Campaign $campaign ): array {
		return array(
			'uuid'           => $campaign->uuid,
			'name'           => $campaign->name,
			'slug'           => $campaign->slug,
			'description'    => $campaign->description,
			'goal'           => $campaign->goal,
			'status'         => $campaign->status,
			'priority'       => $campaign->priority,
			'starts_at_gmt'  => $this->format_date( $campaign->starts_at_gmt ),
			'ends_at_gmt'    => $this->format_date( $campaign->ends_at_gmt ),
			'usage_rules'    => $this->encode_object( $campaign->configuration->usage_rules() ),
			'conflict_rules' => $this->encode_object( $campaign->configuration->conflict_rules() ),
			'settings'       => $this->encode_object( $campaign->configuration->settings() ),
			'created_by'     => $campaign->created_by,
			'created_at_gmt' => $this->format_date( $campaign->created_at_gmt ),
			'updated_at_gmt' => $this->format_date( $campaign->updated_at_gmt ),
		);
	}

	/**
	 * Decode an object-shaped JSON column.
	 *
	 * @param array<string, mixed> $row    Database row.
	 * @param string               $column Column name.
	 * @return array<string, mixed>
	 * @throws RuntimeException When JSON is invalid or is not an object.
	 */
	private function decode_object( array $row, string $column ): array {
		$value = $this->required_string( $row, $column );

		try {
			$decoded = json_decode( $value, false, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The previous exception is diagnostic context, not rendered output.
			throw new RuntimeException( 'Stored campaign configuration JSON is invalid.', 0, $exception );
		}

		if ( ! is_object( $decoded ) ) {
			throw new RuntimeException( 'Stored campaign configuration must be an object.' );
		}

		return get_object_vars( $decoded );
	}

	/**
	 * Parse a required GMT database timestamp.
	 *
	 * @param array<string, mixed> $row    Database row.
	 * @param string               $column Column name.
	 * @throws RuntimeException When the timestamp is invalid.
	 */
	private function required_date( array $row, string $column ): DateTimeImmutable {
		$value = $this->required_string( $row, $column );
		$date  = DateTimeImmutable::createFromFormat( '!' . self::DATE_FORMAT, $value, new DateTimeZone( 'UTC' ) );

		if ( false === $date || $date->format( self::DATE_FORMAT ) !== $value ) {
			throw new RuntimeException( 'Stored campaign timestamp is invalid.' );
		}

		return $date;
	}

	/**
	 * Parse an optional GMT database timestamp.
	 *
	 * @param array<string, mixed> $row    Database row.
	 * @param string               $column Column name.
	 * @throws RuntimeException When a populated timestamp is invalid.
	 */
	private function nullable_date( array $row, string $column ): ?DateTimeImmutable {
		return null === ( $row[ $column ] ?? null ) ? null : $this->required_date( $row, $column );
	}

	/**
	 * Format an optional GMT timestamp for persistence.
	 *
	 * @param DateTimeImmutable|null $date Optional timestamp.
	 */
	private function format_date( ?DateTimeImmutable $date ): ?string {
		return null === $date ? null : $date->format( self::DATE_FORMAT );
	}

	/**
	 * Encode object-shaped configuration with explicit failure handling.
	 *
	 * @param array<string, mixed> $value Validated configuration.
	 * @throws JsonException When encoding fails.
	 */
	private function encode_object( array $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- JSON_THROW_ON_ERROR provides deterministic persistence failures.
		return json_encode( $value, JSON_THROW_ON_ERROR );
	}

	/**
	 * Read a required integer column.
	 *
	 * @param array<string, mixed> $row    Database row.
	 * @param string               $column Column name.
	 * @throws RuntimeException When the column is missing or non-numeric.
	 */
	private function required_int( array $row, string $column ): int {
		if ( ! isset( $row[ $column ] ) || ! is_numeric( $row[ $column ] ) ) {
			throw new RuntimeException( 'Required numeric campaign column is invalid.' );
		}

		return (int) $row[ $column ];
	}

	/**
	 * Read an optional integer column.
	 *
	 * @param array<string, mixed> $row    Database row.
	 * @param string               $column Column name.
	 * @throws RuntimeException When a populated column is non-numeric.
	 */
	private function nullable_int( array $row, string $column ): ?int {
		return null === ( $row[ $column ] ?? null ) ? null : $this->required_int( $row, $column );
	}

	/**
	 * Read a required string column.
	 *
	 * @param array<string, mixed> $row    Database row.
	 * @param string               $column Column name.
	 * @throws RuntimeException When the column is missing or not a string.
	 */
	private function required_string( array $row, string $column ): string {
		if ( ! isset( $row[ $column ] ) || ! is_string( $row[ $column ] ) ) {
			throw new RuntimeException( 'Required campaign text column is invalid.' );
		}

		return $row[ $column ];
	}

	/**
	 * Read an optional string column.
	 *
	 * @param array<string, mixed> $row    Database row.
	 * @param string               $column Column name.
	 * @throws RuntimeException When a populated column is not a string.
	 */
	private function nullable_string( array $row, string $column ): ?string {
		return null === ( $row[ $column ] ?? null ) ? null : $this->required_string( $row, $column );
	}
}
