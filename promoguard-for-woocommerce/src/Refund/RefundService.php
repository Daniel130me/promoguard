<?php
/**
 * Refund policy application service.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Refund;

use PromoGuard\Campaign\CampaignConfiguration;

/** Applies cumulative full-refund behavior to consumed usages. */
final class RefundService {
	public const RESTORED      = 'restored';
	public const KEPT          = 'kept';
	public const MANUAL_REVIEW = 'manual_review';
	public const UNCHANGED     = 'unchanged';

	/**
	 * Configure refund context and restoration persistence.
	 *
	 * @param RefundContextStore     $contexts     Consumed usage lookup.
	 * @param RefundRestorationStore $restorations Atomic restoration persistence.
	 */
	public function __construct(
		private readonly RefundContextStore $contexts,
		private readonly RefundRestorationStore $restorations
	) {}

	/**
	 * Apply policies for a cumulative refund observation.
	 *
	 * Partial refunds intentionally avoid both usage reads and state writes.
	 *
	 * @param RefundEvent $event Validated cumulative refund facts.
	 * @return array<int,string> Outcomes keyed by campaign ID.
	 */
	public function handle( RefundEvent $event ): array {
		if ( ! $event->is_full_refund ) {
			return array();
		}

		$outcomes = array();
		foreach ( $this->contexts->consumed_for_order( $event->order_id ) as $context ) {
			if ( CampaignConfiguration::REFUND_KEEP_CONSUMED === $context->refund_behavior ) {
				$outcomes[ $context->campaign_id ] = self::KEPT;
				continue;
			}

			if ( CampaignConfiguration::REFUND_MANUAL_REVIEW === $context->refund_behavior ) {
				$outcomes[ $context->campaign_id ] = self::MANUAL_REVIEW;
				continue;
			}

			$outcomes[ $context->campaign_id ] = $this->restorations->restore(
				$event->order_id,
				$context->campaign_id,
				$event->occurred_at_gmt
			) ? self::RESTORED : self::UNCHANGED;
		}

		return $outcomes;
	}
}
