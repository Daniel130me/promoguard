<?php
/**
 * In-memory store-credit persistence for tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Support;

use PromoGuard\Credit\CreditGrantResult;
use PromoGuard\Credit\CreditStore;

/** Captures idempotent grants without database dependencies. */
final class InMemoryCreditStore implements CreditStore {
	/**
	 * Captured grants keyed by source and reference.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $grants = array();

	/**
	 * Capture one idempotent grant.
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param string $amount      Credit amount.
	 * @param string $currency    Currency code.
	 * @param string $type        Transaction type.
	 * @param string $source      Transaction source.
	 * @param string $reference   Idempotency reference.
	 * @param string $description Ledger description.
	 */
	public function grant(
		int $user_id,
		string $amount,
		string $currency,
		string $type,
		string $source,
		string $reference,
		string $description
	): CreditGrantResult {
		$key = $source . ':' . $reference;
		if ( isset( $this->grants[ $key ] ) ) {
			return new CreditGrantResult( false, (string) $this->grants[ $key ]['amount'], 1 );
		}

		$this->grants[ $key ] = compact( 'user_id', 'amount', 'currency', 'type', 'source', 'reference', 'description' );
		return new CreditGrantResult( true, $amount, 1 );
	}

	/**
	 * Return the captured balance for a user and currency.
	 *
	 * @param int    $user_id  WordPress user ID.
	 * @param string $currency Currency code.
	 */
	public function balance( int $user_id, string $currency ): string {
		foreach ( $this->grants as $grant ) {
			if ( $user_id === $grant['user_id'] && $currency === $grant['currency'] ) {
				return (string) $grant['amount'];
			}
		}

		return '0';
	}
}
