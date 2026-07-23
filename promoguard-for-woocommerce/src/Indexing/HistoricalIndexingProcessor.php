<?php
/**
 * Historical order indexing processor.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Indexing;

use PromoGuard\Customer\IdentityResolver;
use Throwable;

/** Reconstructs consumed usages from one bounded source page. */
final class HistoricalIndexingProcessor {
	/**
	 * Configure the bounded historical processor.
	 *
	 * @param HistoricalOrderSource $orders     Bounded order source.
	 * @param HistoricalImportStore $imports    Assignment and usage persistence.
	 * @param IdentityResolver      $identities Safe user-first identity resolver.
	 */
	public function __construct(
		private readonly HistoricalOrderSource $orders,
		private readonly HistoricalImportStore $imports,
		private readonly IdentityResolver $identities
	) {}

	/**
	 * Process one claimed job page.
	 *
	 * @param IndexingJob $job Claimed job.
	 */
	public function process( IndexingJob $job ): IndexingBatch {
		$page      = $this->orders->page( $job );
		$campaigns = $this->imports->campaigns_for_codes( $this->distinct_codes( $page->orders ) );
		$imported  = 0;
		$skipped   = 0;
		$errors    = $page->errors;

		foreach ( $page->orders as $order ) {
			try {
				$targets = $this->counted_targets( $order, $campaigns );
				if ( array() === $targets ) {
					++$skipped;
					continue;
				}

				$resolution = $this->identities->resolve( $order->wp_user_id, $order->billing_email );
				if ( null === $resolution->customer || $resolution->has_conflict() ) {
					$errors[] = $this->error( $order->id, 'Customer identity could not be resolved safely.' );
					continue;
				}

				$created = false;
				foreach ( $targets as $target ) {
					$created = $this->imports->import(
						new HistoricalUsage(
							$target['campaign']->campaign_id,
							$target['campaign']->promotion_id,
							$resolution->customer->id,
							$order->id,
							$target['coupon']->order_item_id,
							$target['campaign']->coupon_id,
							$target['coupon']->code,
							$order->status,
							$target['coupon']->discount,
							$order->currency,
							$target['campaign']->refund_behavior,
							$order->occurred_at_gmt
						)
					) || $created;
				}

				if ( $created ) {
					++$imported;
				} else {
					++$skipped;
				}
			} catch ( Throwable $exception ) {
				$errors[] = $this->error( $order->id, 'Historical order could not be imported.' );
			}
		}

		return new IndexingBatch(
			count( $page->orders ) + count( $page->errors ),
			$imported,
			$skipped,
			$errors,
			$page->has_more
		);
	}

	/**
	 * Return distinct code keys for one bulk assignment lookup.
	 *
	 * @param HistoricalOrder[] $orders Order page.
	 * @return string[]
	 */
	private function distinct_codes( array $orders ): array {
		$codes = array();
		foreach ( $orders as $order ) {
			foreach ( $order->coupons as $coupon ) {
				$codes[] = $coupon->code_key;
			}
		}

		return array_values( array_unique( $codes ) );
	}

	/**
	 * Resolve at most one coupon item per campaign for a counted order.
	 *
	 * @param HistoricalOrder                  $order     Historical order.
	 * @param array<string,HistoricalCampaign> $campaigns Campaigns keyed by normalized code.
	 * @return array<int,array{campaign:HistoricalCampaign,coupon:HistoricalCoupon}>
	 */
	private function counted_targets( HistoricalOrder $order, array $campaigns ): array {
		$targets = array();
		foreach ( $order->coupons as $coupon ) {
			$campaign = $campaigns[ $coupon->code_key ] ?? null;
			if ( null === $campaign || ! $campaign->counts_status( $order->status ) ) {
				continue;
			}
			$targets[ $campaign->campaign_id ] ??= array(
				'campaign' => $campaign,
				'coupon'   => $coupon,
			);
		}

		return $targets;
	}

	/**
	 * Build one safe bounded error summary.
	 *
	 * @param int    $order_id WooCommerce order ID.
	 * @param string $message  Fixed safe message.
	 * @return array{order_id:int,message:string}
	 */
	private function error( int $order_id, string $message ): array {
		return array(
			'order_id' => $order_id,
			'message'  => $message,
		);
	}
}
