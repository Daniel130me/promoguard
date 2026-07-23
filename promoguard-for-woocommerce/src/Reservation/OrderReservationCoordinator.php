<?php
/**
 * Shared order reservation orchestration.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

use Closure;
use DateTimeImmutable;
use PromoGuard\Checkout\CheckoutEvaluation;
use PromoGuard\Checkout\CheckoutValidator;
use PromoGuard\Decision\DenialLogger;

/** Applies final eligibility and reservation policy to any WooCommerce order. */
final class OrderReservationCoordinator {
	/**
	 * Configure shared order reservation dependencies.
	 *
	 * @param CheckoutValidator         $validator    Final campaign validator.
	 * @param DenialLogger              $denials      Privacy-safe denial logger.
	 * @param ReservationService        $reservations Atomic reservation service.
	 * @param ReservationRequestFactory $requests     Reservation request factory.
	 * @param OrderUsageStore           $usages       Indexed active usage lookup.
	 * @param Closure                   $uuid_factory Usage UUID factory.
	 * @param string                    $request_id   Request correlation ID.
	 */
	public function __construct(
		private readonly CheckoutValidator $validator,
		private readonly DenialLogger $denials,
		private readonly ReservationService $reservations,
		private readonly ReservationRequestFactory $requests,
		private readonly OrderUsageStore $usages,
		private readonly Closure $uuid_factory,
		private readonly string $request_id
	) {}

	/**
	 * Validate and reserve every new protected campaign represented on an order.
	 *
	 * Existing pending or consumed usages bypass re-evaluation so idempotent
	 * status callbacks do not count an order's own reservation against itself.
	 *
	 * @param int[]             $coupon_ids           Native coupon IDs on the order.
	 * @param int|null          $wp_user_id           Authenticated WordPress user ID.
	 * @param string|null       $billing_email        Raw billing email.
	 * @param int               $order_id             WooCommerce order ID.
	 * @param string            $currency             Order currency.
	 * @param DateTimeImmutable $now_gmt              Stable GMT evaluation time.
	 * @param string            $denial_context       Decision-log context.
	 * @param int[]             $excluded_campaign_ids Campaigns already denied on this order.
	 * @return array<int,CheckoutEvaluation> Denials keyed by campaign ID.
	 * @throws ReservationLimitException When a raced limit cannot be classified by fresh policy.
	 */
	public function reserve(
		array $coupon_ids,
		?int $wp_user_id,
		?string $billing_email,
		int $order_id,
		string $currency,
		DateTimeImmutable $now_gmt,
		string $denial_context,
		array $excluded_campaign_ids = array()
	): array {
		$coupon_ids = array_values( array_unique( array_filter( array_map( 'intval', $coupon_ids ) ) ) );
		$skipped    = array_fill_keys(
			array_merge(
				$this->usages->active_campaign_ids_for_order( $order_id ),
				array_map( 'intval', $excluded_campaign_ids )
			),
			true
		);
		$allowed    = array();
		$denied     = array();
		$seen       = array();

		foreach ( $coupon_ids as $coupon_id ) {
			$target = $this->validator->resolve_target( $coupon_id );
			if ( null === $target ) {
				continue;
			}

			$campaign_id = $target->assignment->campaign_id;
			if ( isset( $skipped[ $campaign_id ] ) || isset( $seen[ $campaign_id ] ) ) {
				continue;
			}
			$seen[ $campaign_id ] = true;

			$evaluation = $this->validator->evaluate(
				$coupon_id,
				$coupon_ids,
				$wp_user_id,
				$billing_email,
				$now_gmt,
				false
			);
			if ( null === $evaluation ) {
				continue;
			}

			if ( ! $evaluation->decision->allowed ) {
				$this->record_denial( $evaluation, $denial_context, $now_gmt, $order_id );
				$denied[ $campaign_id ] = $evaluation;
				continue;
			}

			$allowed[ $campaign_id ] = $evaluation;
		}

		if ( array() !== $denied ) {
			return $denied;
		}

		foreach ( $allowed as $campaign_id => $evaluation ) {
			try {
				$request = $this->requests->create(
					$evaluation,
					$order_id,
					$currency,
					( $this->uuid_factory )(),
					$now_gmt
				);
				$this->reservations->reserve( $request );
			} catch ( ReservationLimitException $exception ) {
				$fresh = $this->validator->evaluate_fresh(
					(int) $evaluation->target->assignment->external_id,
					$coupon_ids,
					$wp_user_id,
					$billing_email,
					$now_gmt,
					false
				);

				if ( null === $fresh || $fresh->decision->allowed ) {
					throw $exception;
				}

				$this->record_denial( $fresh, $denial_context, $now_gmt, $order_id );
				$denied[ $campaign_id ] = $fresh;
			}
		}

		return $denied;
	}

	/**
	 * Persist one denied order evaluation.
	 *
	 * @param CheckoutEvaluation $evaluation Final denied campaign evaluation.
	 * @param string             $context    Decision-log context.
	 * @param DateTimeImmutable  $now_gmt    Stable GMT evaluation time.
	 * @param int                $order_id   WooCommerce order ID.
	 */
	private function record_denial(
		CheckoutEvaluation $evaluation,
		string $context,
		DateTimeImmutable $now_gmt,
		int $order_id
	): void {
		$this->denials->record(
			$evaluation,
			$this->request_id,
			$context,
			$now_gmt,
			$order_id
		);
	}
}
