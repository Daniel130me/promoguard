<?php
/**
 * Request-deduplicated eligibility denial logger.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Decision;

use DateTimeImmutable;
use PromoGuard\Checkout\CheckoutEvaluation;

/** Converts checkout denials into privacy-safe persistence records. */
final class DenialLogger {
	public const CONTEXT_COUPON_VALIDATION = 'coupon_validation';
	public const CONTEXT_CLASSIC_CHECKOUT  = 'classic_checkout';
	public const CONTEXT_STORE_API         = 'store_api_checkout';
	public const CONTEXT_RESERVATION       = 'reservation';

	/**
	 * Successfully persisted request-local denial keys.
	 *
	 * @var array<string,true>
	 */
	private array $recorded = array();

	/**
	 * Configure decision persistence.
	 *
	 * @param DecisionStore $store Decision persistence.
	 */
	public function __construct( private readonly DecisionStore $store ) {}

	/**
	 * Persist a denied checkout evaluation at most once per request context.
	 *
	 * @param CheckoutEvaluation $evaluation  Checkout policy result.
	 * @param string             $request_id  Request correlation ID.
	 * @param string             $context     Validation context.
	 * @param DateTimeImmutable  $now_gmt     Current GMT time.
	 * @param int|null           $order_id    Checkout order ID.
	 * @param string|null        $coupon_code Coupon code snapshot.
	 */
	public function record(
		CheckoutEvaluation $evaluation,
		string $request_id,
		string $context,
		DateTimeImmutable $now_gmt,
		?int $order_id = null,
		?string $coupon_code = null
	): void {
		if ( $evaluation->decision->allowed ) {
			return;
		}

		$assignment = $evaluation->target->assignment;
		$key        = implode(
			':',
			array(
				$request_id,
				$context,
				(string) $assignment->campaign_id,
				(string) ( $assignment->id ?? 0 ),
				(string) ( $order_id ?? 0 ),
				$evaluation->decision->reason,
			)
		);

		if ( isset( $this->recorded[ $key ] ) ) {
			return;
		}

		$coupon_id = ctype_digit( $assignment->external_id ) ? (int) $assignment->external_id : null;
		$this->store->record(
			new DenialRecord(
				request_id: $request_id,
				campaign_id: $assignment->campaign_id,
				promotion_id: $assignment->id,
				customer_id: $evaluation->customer_id,
				order_id: $order_id,
				coupon_id: $coupon_id,
				coupon_code: $coupon_code ?? $assignment->external_code,
				context: $context,
				reason: $evaluation->decision->reason,
				customer_message: $evaluation->decision->customer_message,
				admin_explanation: $evaluation->decision->admin_explanation,
				metadata: array(),
				created_at_gmt: $now_gmt
			)
		);

		// Mark only successful writes so a transient failure can be retried.
		$this->recorded[ $key ] = true;
	}
}
