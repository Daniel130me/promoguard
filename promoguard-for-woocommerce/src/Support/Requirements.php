<?php
/**
 * Runtime requirement evaluation.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Support;

/**
 * Represents the result of the plugin's runtime dependency checks.
 */
final class Requirements {
	public const MINIMUM_PHP_VERSION         = '8.1';
	public const MAXIMUM_PHP_VERSION         = '8.4';
	public const MINIMUM_WORDPRESS_VERSION   = '6.9';
	public const MAXIMUM_WORDPRESS_VERSION   = '7.0';
	public const MINIMUM_WOOCOMMERCE_VERSION = '10.8';
	public const MAXIMUM_WOOCOMMERCE_VERSION = '10.9';

	/**
	 * Human-readable reasons the environment is unsupported.
	 *
	 * @var string[]
	 */
	private array $errors;

	/**
	 * Create an evaluated requirements result.
	 *
	 * @param string[] $errors Human-readable requirement errors.
	 */
	private function __construct( array $errors ) {
		$this->errors = $errors;
	}

	/**
	 * Evaluate the active WordPress request.
	 */
	public static function from_environment(): self {
		global $wp_version;

		$woocommerce_version = defined( 'WC_VERSION' ) ? WC_VERSION : null;

		return self::evaluate(
			PHP_VERSION,
			is_string( $wp_version ) ? $wp_version : '',
			is_string( $woocommerce_version ) ? $woocommerce_version : null
		);
	}

	/**
	 * Evaluate explicit versions so the policy remains fast and unit-testable.
	 *
	 * @param string      $php_version         Active PHP version.
	 * @param string      $wordpress_version   Active WordPress version.
	 * @param string|null $woocommerce_version Active WooCommerce version, or null.
	 */
	public static function evaluate(
		string $php_version,
		string $wordpress_version,
		?string $woocommerce_version
	): self {
		$errors = array();

		self::validate_version_range(
			$errors,
			'PHP',
			$php_version,
			self::MINIMUM_PHP_VERSION,
			self::MAXIMUM_PHP_VERSION
		);
		self::validate_version_range(
			$errors,
			'WordPress',
			$wordpress_version,
			self::MINIMUM_WORDPRESS_VERSION,
			self::MAXIMUM_WORDPRESS_VERSION
		);

		if ( null === $woocommerce_version ) {
			$errors[] = 'WooCommerce must be installed and active.';
		} else {
			self::validate_version_range(
				$errors,
				'WooCommerce',
				$woocommerce_version,
				self::MINIMUM_WOOCOMMERCE_VERSION,
				self::MAXIMUM_WOOCOMMERCE_VERSION
			);
		}

		return new self( $errors );
	}

	/**
	 * Whether every runtime requirement is satisfied.
	 */
	public function is_satisfied(): bool {
		return array() === $this->errors;
	}

	/**
	 * Return a safe administrator-facing explanation.
	 */
	public function get_admin_message(): string {
		if ( $this->is_satisfied() ) {
			return 'PromoGuard runtime requirements are satisfied.';
		}

		return 'PromoGuard is inactive: ' . implode( ' ', $this->errors );
	}

	/**
	 * Add a range error when a version falls outside the tested baseline.
	 *
	 * @param string[] $errors          Accumulated validation errors.
	 * @param string   $component       Component name.
	 * @param string   $version         Active version.
	 * @param string   $minimum_version Minimum supported major/minor version.
	 * @param string   $maximum_version Maximum supported major/minor version.
	 */
	private static function validate_version_range(
		array &$errors,
		string $component,
		string $version,
		string $minimum_version,
		string $maximum_version
	): void {
		if ( version_compare( $version, $minimum_version, '<' ) ) {
			$errors[] = sprintf( '%1$s %2$s or newer is required.', $component, $minimum_version );
			return;
		}

		$maximum_parts = explode( '.', $maximum_version );
		$maximum_major = (int) $maximum_parts[0];
		$maximum_minor = (int) ( $maximum_parts[1] ?? 0 );

		// The configured upper bound includes the full major/minor release line.
		$next_unsupported_version = sprintf( '%d.%d', $maximum_major, $maximum_minor + 1 );

		if ( version_compare( $version, $next_unsupported_version, '>=' ) ) {
			$errors[] = sprintf( '%1$s versions later than %2$s have not been verified.', $component, $maximum_version );
		}
	}
}
