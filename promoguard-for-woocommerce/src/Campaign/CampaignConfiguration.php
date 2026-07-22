<?php
/**
 * Validated campaign configuration.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Campaign;

use InvalidArgumentException;

/** Holds only rules supported by the current product version. */
final class CampaignConfiguration {
	public const PERIOD_LIFETIME = 'lifetime';

	public const REFUND_RESTORE                     = 'restore';
	public const REFUND_KEEP_CONSUMED               = 'keep_consumed';
	public const REFUND_MANUAL_REVIEW               = 'manual_review';
	public const MAXIMUM_CAMPAIGN_COUPONS_PER_ORDER = 1;

	private const COUNTED_STATUSES = array( 'processing', 'completed', 'on-hold' );

	/**
	 * Validated usage lifecycle rules.
	 *
	 * @var array<string, mixed>
	 */
	private array $usage_rules;

	/**
	 * Validated coupon conflict rules.
	 *
	 * @var array<string, mixed>
	 */
	private array $conflict_rules;

	/**
	 * Validated general campaign settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings;

	/**
	 * Store configuration that has already passed validation.
	 *
	 * @param array<string, mixed> $usage_rules    Usage lifecycle rules.
	 * @param array<string, mixed> $conflict_rules Coupon conflict rules.
	 * @param array<string, mixed> $settings       General campaign settings.
	 */
	private function __construct( array $usage_rules, array $conflict_rules, array $settings ) {
		$this->usage_rules    = $usage_rules;
		$this->conflict_rules = $conflict_rules;
		$this->settings       = $settings;
	}

	/** Return the supported default configuration. */
	public static function defaults(): self {
		return self::from_arrays( array(), array(), array() );
	}

	/**
	 * Validate persisted or incoming configuration arrays.
	 *
	 * @param array<string, mixed> $usage_rules    Usage lifecycle rules.
	 * @param array<string, mixed> $conflict_rules Coupon conflict rules.
	 * @param array<string, mixed> $settings       General campaign settings.
	 * @throws InvalidArgumentException When a value is outside the supported rule boundary.
	 */
	public static function from_arrays(
		array $usage_rules,
		array $conflict_rules,
		array $settings
	): self {
		$usage_defaults    = array(
			'maximum_uses'            => 1,
			'period'                  => self::PERIOD_LIFETIME,
			'counted_statuses'        => array( 'processing', 'completed' ),
			'release_on_failure'      => true,
			'release_on_cancellation' => true,
			'refund_behavior'         => self::REFUND_RESTORE,
		);
		$conflict_defaults = array(
			'maximum_campaign_coupons_per_order' => self::MAXIMUM_CAMPAIGN_COUPONS_PER_ORDER,
		);
		$settings_defaults = array( 'login_required' => false );

		self::reject_unknown_keys( $usage_rules, $usage_defaults );
		self::reject_unknown_keys( $conflict_rules, $conflict_defaults );
		self::reject_unknown_keys( $settings, $settings_defaults );

		$usage_rules    = array_replace( $usage_defaults, $usage_rules );
		$conflict_rules = array_replace( $conflict_defaults, $conflict_rules );
		$settings       = array_replace( $settings_defaults, $settings );

		if ( ! is_int( $usage_rules['maximum_uses'] ) || $usage_rules['maximum_uses'] < 1 ) {
			throw new InvalidArgumentException( 'Maximum uses must be a positive integer.' );
		}

		if ( self::PERIOD_LIFETIME !== $usage_rules['period'] ) {
			throw new InvalidArgumentException( 'Only lifetime campaign periods are supported.' );
		}

		$usage_rules['counted_statuses'] = self::validate_counted_statuses( $usage_rules['counted_statuses'] );

		foreach ( array( 'release_on_failure', 'release_on_cancellation' ) as $boolean_rule ) {
			if ( ! is_bool( $usage_rules[ $boolean_rule ] ) ) {
				throw new InvalidArgumentException( 'Lifecycle release rules must be boolean.' );
			}
		}

		if ( ! in_array( $usage_rules['refund_behavior'], self::refund_behaviors(), true ) ) {
			throw new InvalidArgumentException( 'Unsupported refund behavior.' );
		}

		if ( self::MAXIMUM_CAMPAIGN_COUPONS_PER_ORDER !== $conflict_rules['maximum_campaign_coupons_per_order'] ) {
			throw new InvalidArgumentException( 'The MVP supports one campaign coupon per order.' );
		}

		if ( ! is_bool( $settings['login_required'] ) ) {
			throw new InvalidArgumentException( 'Login requirement must be boolean.' );
		}

		return new self( $usage_rules, $conflict_rules, $settings );
	}

	/**
	 * Return validated usage rules.
	 *
	 * @return array<string, mixed>
	 */
	public function usage_rules(): array {
		return $this->usage_rules;
	}

	/**
	 * Return validated conflict rules.
	 *
	 * @return array<string, mixed>
	 */
	public function conflict_rules(): array {
		return $this->conflict_rules;
	}

	/**
	 * Return validated general settings.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		return $this->settings;
	}

	/**
	 * Return supported full-refund behaviors.
	 *
	 * @return string[]
	 */
	private static function refund_behaviors(): array {
		return array(
			self::REFUND_RESTORE,
			self::REFUND_KEEP_CONSUMED,
			self::REFUND_MANUAL_REVIEW,
		);
	}

	/**
	 * Validate and deduplicate counted WooCommerce order statuses.
	 *
	 * @param mixed $statuses Candidate WooCommerce order statuses.
	 * @return string[]
	 * @throws InvalidArgumentException When no supported status remains.
	 */
	private static function validate_counted_statuses( $statuses ): array {
		if ( ! is_array( $statuses ) || array() === $statuses ) {
			throw new InvalidArgumentException( 'At least one counted status is required.' );
		}

		$statuses = array_values( array_unique( $statuses ) );

		foreach ( $statuses as $status ) {
			if ( ! is_string( $status ) || ! in_array( $status, self::COUNTED_STATUSES, true ) ) {
				throw new InvalidArgumentException( 'Unsupported counted order status.' );
			}
		}

		return $statuses;
	}

	/**
	 * Reject configuration that this version cannot enforce.
	 *
	 * @param array<string, mixed> $provided Candidate values.
	 * @param array<string, mixed> $supported Supported keys and defaults.
	 * @throws InvalidArgumentException When an unsupported key is supplied.
	 */
	private static function reject_unknown_keys( array $provided, array $supported ): void {
		if ( array() !== array_diff_key( $provided, $supported ) ) {
			throw new InvalidArgumentException( 'Unsupported campaign configuration key.' );
		}
	}
}
