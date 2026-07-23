<?php
/**
 * Customer email normalization.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Customer;

/** Normalizes email identifiers without provider-specific alias rules. */
final class EmailNormalizer {
	/**
	 * Return a valid lowercase email or null.
	 *
	 * @param string $email Candidate email address.
	 */
	public function normalize( string $email ): ?string {
		$email = strtolower( trim( $email ) );

		if ( '' === $email ) {
			return null;
		}

		if ( function_exists( 'is_email' ) ) {
			return false === is_email( $email ) ? null : $email;
		}

		// WordPress is unavailable only in isolated unit tests; production always uses is_email().
		return false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ? null : $email;
	}
}
