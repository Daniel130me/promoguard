<?php
/**
 * Signup-bonus award orchestration.
 *
 * @package PromoGuard
 */

namespace PromoGuard\SignupBonus;

use PromoGuard\Credit\CreditAmount;
use PromoGuard\Credit\CreditGrantResult;
use PromoGuard\Credit\CreditStore;

/** Maps standalone signup campaign events to idempotent store-credit grants. */
final class SignupBonusService {
	private const SOURCE = 'signup_bonus';
	private const TYPE   = 'signup_bonus';

	/**
	 * Configure rules and credit persistence.
	 *
	 * @param SignupBonusRuleStore $rules   Signup campaign rule source.
	 * @param CreditStore          $credits Store-credit persistence.
	 */
	public function __construct(
		private readonly SignupBonusRuleStore $rules,
		private readonly CreditStore $credits
	) {}

	/**
	 * Award one matching signup event.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $audience Customer or vendor audience.
	 * @param string $event Registration or approval event.
	 * @param string $currency Current WooCommerce store currency.
	 */
	public function award( int $user_id, string $audience, string $event, string $currency ): ?CreditGrantResult {
		$amount = $this->rules->current()->amount_for( $audience, $event );
		if ( null === $amount || ! CreditAmount::is_positive( $amount ) ) {
			return null;
		}

		return $this->credits->grant(
			$user_id,
			$amount,
			$currency,
			self::TYPE,
			self::SOURCE,
			$audience . ':' . $event . ':' . $user_id,
			sprintf( 'Signup bonus: %s %s', $audience, $event )
		);
	}
}
