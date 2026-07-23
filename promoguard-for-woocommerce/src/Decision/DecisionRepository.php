<?php
/**
 * Eligibility decision repository.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Decision;

use JsonException;
use PromoGuard\Support\TableNames;
use RuntimeException;

/** Persists denials to the plugin-owned indexed decision log. */
final class DecisionRepository implements DecisionStore {
	/**
	 * Configure site-scoped decision persistence.
	 *
	 * @param TableNames $tables Site-scoped plugin table names.
	 */
	public function __construct( private readonly TableNames $tables ) {}

	/** Build a repository for the active WordPress site. */
	public static function from_wordpress(): self {
		return new self( TableNames::from_wordpress() );
	}

	/**
	 * Persist one denial record.
	 *
	 * @param DenialRecord $denial Validated denial.
	 * @throws RuntimeException When metadata encoding or persistence fails.
	 */
	public function record( DenialRecord $denial ): void {
		global $wpdb;

		try {
			$metadata = wp_json_encode( (object) $denial->metadata, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is diagnostic context and is never rendered directly.
			throw new RuntimeException( 'Decision metadata could not be encoded.', 0, $exception );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared insert into the plugin-owned decision table.
		$inserted = $wpdb->insert(
			$this->tables->decisions(),
			array(
				'request_id'        => $denial->request_id,
				'campaign_id'       => $denial->campaign_id,
				'promotion_id'      => $denial->promotion_id,
				'customer_id'       => $denial->customer_id,
				'order_id'          => $denial->order_id,
				'coupon_id'         => $denial->coupon_id,
				'coupon_code'       => $denial->coupon_code,
				'context'           => $denial->context,
				'decision'          => 'denied',
				'reason'            => $denial->reason,
				'customer_message'  => $denial->customer_message,
				'admin_explanation' => $denial->admin_explanation,
				'metadata'          => $metadata,
				'created_at_gmt'    => $denial->created_at_gmt->format( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			throw new RuntimeException( 'Eligibility denial could not be recorded.' );
		}
	}
}
