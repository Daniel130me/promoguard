<?php
/**
 * Request-cached checkout eligibility validation.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Checkout;

use DateTimeImmutable;
use PromoGuard\Application\EligibilityEvaluator;

/** Evaluates only assigned coupons and reuses equivalent request decisions. */
final class CheckoutValidator {
	/**
	 * Policy results keyed by stable, non-reversible request facts.
	 *
	 * @var array<string,CheckoutEvaluation>
	 */
	private array $decisions = array();

	/**
	 * Configure checkout validation dependencies.
	 *
	 * @param CheckoutTargetResolver $targets     Coupon campaign resolver.
	 * @param EligibilityEvaluator   $eligibility Shared eligibility application service.
	 */
	public function __construct(
		private readonly CheckoutTargetResolver $targets,
		private readonly EligibilityEvaluator $eligibility
	) {}

	/**
	 * Evaluate one coupon without affecting unrelated WooCommerce coupons.
	 *
	 * @param int               $coupon_id                     Coupon being evaluated.
	 * @param int[]             $applied_coupon_ids            Coupon IDs already on the cart/order.
	 * @param int|null          $wp_user_id                    Authenticated WordPress user ID.
	 * @param string|null       $billing_email                 Raw billing email.
	 * @param DateTimeImmutable $now_gmt                       Current GMT time.
	 * @param bool              $allow_provisional_identity    Whether early validation may defer identity.
	 */
	public function evaluate(
		int $coupon_id,
		array $applied_coupon_ids,
		?int $wp_user_id,
		?string $billing_email,
		DateTimeImmutable $now_gmt,
		bool $allow_provisional_identity
	): ?CheckoutEvaluation {
		return $this->evaluate_with_cache(
			$coupon_id,
			$applied_coupon_ids,
			$wp_user_id,
			$billing_email,
			$now_gmt,
			$allow_provisional_identity,
			true
		);
	}

	/**
	 * Resolve one protected coupon target without evaluating customer policy.
	 *
	 * @param int $coupon_id Native coupon ID.
	 */
	public function resolve_target( int $coupon_id ): ?CheckoutTarget {
		return $this->targets->resolve( $coupon_id );
	}

	/**
	 * Re-evaluate current state after an atomic reservation race.
	 *
	 * @param int               $coupon_id                  Coupon being evaluated.
	 * @param int[]             $applied_coupon_ids         Coupon IDs on the order.
	 * @param int|null          $wp_user_id                 WordPress user ID.
	 * @param string|null       $billing_email              Raw billing email.
	 * @param DateTimeImmutable $now_gmt                    Current GMT time.
	 * @param bool              $allow_provisional_identity Whether identity may be deferred.
	 */
	public function evaluate_fresh(
		int $coupon_id,
		array $applied_coupon_ids,
		?int $wp_user_id,
		?string $billing_email,
		DateTimeImmutable $now_gmt,
		bool $allow_provisional_identity
	): ?CheckoutEvaluation {
		return $this->evaluate_with_cache(
			$coupon_id,
			$applied_coupon_ids,
			$wp_user_id,
			$billing_email,
			$now_gmt,
			$allow_provisional_identity,
			false
		);
	}

	/**
	 * Evaluate one coupon with optional request-cache reuse.
	 *
	 * @param int               $coupon_id                  Coupon being evaluated.
	 * @param int[]             $applied_coupon_ids         Coupon IDs on the order.
	 * @param int|null          $wp_user_id                 WordPress user ID.
	 * @param string|null       $billing_email              Raw billing email.
	 * @param DateTimeImmutable $now_gmt                    Current GMT time.
	 * @param bool              $allow_provisional_identity Whether identity may be deferred.
	 * @param bool              $use_cache                  Whether equivalent decisions may be reused.
	 */
	private function evaluate_with_cache(
		int $coupon_id,
		array $applied_coupon_ids,
		?int $wp_user_id,
		?string $billing_email,
		DateTimeImmutable $now_gmt,
		bool $allow_provisional_identity,
		bool $use_cache
	): ?CheckoutEvaluation {
		$target = $this->targets->resolve( $coupon_id );
		if ( null === $target ) {
			return null;
		}

		$campaign_coupon_count = $this->count_other_campaign_coupons(
			$coupon_id,
			$target->assignment->campaign_id,
			$applied_coupon_ids
		);
		$cache_key             = $this->cache_key(
			$target->assignment->campaign_id,
			$wp_user_id,
			$billing_email,
			$campaign_coupon_count,
			$now_gmt,
			$allow_provisional_identity
		);

		if ( $use_cache && isset( $this->decisions[ $cache_key ] ) ) {
			return $this->decisions[ $cache_key ];
		}

		$eligibility = $this->eligibility->evaluate(
			$target->campaign,
			$wp_user_id,
			$billing_email,
			$campaign_coupon_count,
			$now_gmt,
			$allow_provisional_identity
		);
		$result      = new CheckoutEvaluation( $target, $eligibility->decision, $eligibility->customer_id() );

		$this->decisions[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * Count other applied coupons assigned to the same campaign.
	 *
	 * @param int   $coupon_id          Coupon currently being evaluated.
	 * @param int   $campaign_id        Target campaign ID.
	 * @param int[] $applied_coupon_ids Applied coupon IDs.
	 */
	private function count_other_campaign_coupons( int $coupon_id, int $campaign_id, array $applied_coupon_ids ): int {
		$count = 0;

		foreach ( array_unique( $applied_coupon_ids ) as $applied_coupon_id ) {
			if ( $coupon_id === $applied_coupon_id ) {
				continue;
			}

			$applied_target = $this->targets->resolve( $applied_coupon_id );
			if ( null !== $applied_target && $applied_target->assignment->campaign_id === $campaign_id ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Build a request-local cache key without retaining the raw email.
	 *
	 * @param int               $campaign_id                   Campaign ID.
	 * @param int|null          $wp_user_id                    WordPress user ID.
	 * @param string|null       $billing_email                 Raw billing email.
	 * @param int               $campaign_coupon_count         Other campaign coupon count.
	 * @param DateTimeImmutable $now_gmt                       Evaluation time.
	 * @param bool              $allow_provisional_identity    Whether identity may be deferred.
	 */
	private function cache_key(
		int $campaign_id,
		?int $wp_user_id,
		?string $billing_email,
		int $campaign_coupon_count,
		DateTimeImmutable $now_gmt,
		bool $allow_provisional_identity
	): string {
		return implode(
			':',
			array(
				(string) $campaign_id,
				(string) ( $wp_user_id ?? 0 ),
				hash( 'sha256', $billing_email ?? '' ),
				(string) $campaign_coupon_count,
				$now_gmt->format( 'U' ),
				$allow_provisional_identity ? '1' : '0',
			)
		);
	}
}
