<?php
/**
 * Administrator-configurable store-credit redemption settings.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Credit;

use PromoGuard\Support\Options;

/** Keeps redemption policy separate from coupon and signup campaign rules. */
final class StoreCreditSettings {
	/**
	 * Configure the redemption feature flag.
	 *
	 * @param bool $redemption_enabled Whether customers may redeem account credit.
	 */
	public function __construct( public readonly bool $redemption_enabled ) {}

	/** Return safe defaults for a new or upgraded installation. */
	public static function defaults(): self {
		return new self( true );
	}

	/**
	 * Build settings from an untrusted option or REST payload.
	 *
	 * @param array<string,mixed> $settings Candidate closed settings payload.
	 */
	public static function from_array( array $settings ): self {
		$enabled = $settings['redemption_enabled'] ?? true;
		return new self( true === $enabled );
	}

	/** Read the active WordPress option. */
	public static function current(): self {
		$value = get_option( Options::STORE_CREDIT_SETTINGS, self::defaults()->to_array() );
		return self::from_array( is_array( $value ) ? $value : array() );
	}

	/**
	 * Return the closed settings payload.
	 *
	 * @return array{redemption_enabled:bool}
	 */
	public function to_array(): array {
		return array( 'redemption_enabled' => $this->redemption_enabled );
	}
}
