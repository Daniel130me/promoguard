<?php
/**
 * PromoGuard-owned database table names.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Support;

/**
 * Builds table names from the active WordPress site prefix.
 */
final class TableNames {
	private const PLUGIN_PREFIX = 'promoguard_';

	/**
	 * Fully qualified prefix for every plugin-owned table.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Create site-scoped table names.
	 *
	 * @param string $wordpress_prefix Active site table prefix.
	 */
	public function __construct( string $wordpress_prefix ) {
		$this->prefix = $wordpress_prefix . self::PLUGIN_PREFIX;
	}

	/**
	 * Build names from the active WordPress database object.
	 */
	public static function from_wordpress(): self {
		global $wpdb;

		return new self( $wpdb->prefix );
	}

	/** Return the campaigns table name. */
	public function campaigns(): string {

		return $this->prefix . 'campaigns';
	}

	/** Return the campaign promotion assignments table name. */
	public function campaign_promotions(): string {

		return $this->prefix . 'campaign_promotions';
	}

	/** Return the internal customers table name. */
	public function customers(): string {

		return $this->prefix . 'customers';
	}

	/** Return the hashed customer identifiers table name. */
	public function customer_identifiers(): string {

		return $this->prefix . 'customer_identifiers';
	}

	/** Return the bounded customer campaign state table name. */
	public function customer_campaign_state(): string {

		return $this->prefix . 'customer_campaign_state';
	}

	/** Return the usage lifecycle table name. */
	public function usages(): string {

		return $this->prefix . 'usages';
	}

	/** Return the eligibility decision log table name. */
	public function decisions(): string {

		return $this->prefix . 'decisions';
	}

	/**
	 * Return every table owned by PromoGuard.
	 *
	 * @return string[]
	 */
	public function all(): array {
		return array(
			$this->campaigns(),
			$this->campaign_promotions(),
			$this->customers(),
			$this->customer_identifiers(),
			$this->customer_campaign_state(),
			$this->usages(),
			$this->decisions(),
		);
	}

	/**
	 * Tables whose counters and lifecycle rows require transactional locking.
	 *
	 * @return string[]
	 */
	public function transactional(): array {
		return array(
			$this->customer_campaign_state(),
			$this->usages(),
		);
	}
}
