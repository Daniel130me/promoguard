<?php
/**
 * WooCommerce historical order source.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Indexing;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use WC_Order;
use WC_Order_Item_Coupon;

/** Loads bounded order-ID pages through WooCommerce CRUD APIs. */
final class WooCommerceHistoricalOrderSource implements HistoricalOrderSource {
	/**
	 * Load one full-scan or targeted order page.
	 *
	 * @param IndexingJob $job Claimed job and next page.
	 */
	public function page( IndexingJob $job ): HistoricalOrderPage {
		$page = $job->is_targeted()
			? $this->targeted_ids( $job )
			: $this->historical_ids( $job );

		$orders = array();
		$errors = array();
		foreach ( $page['ids'] as $order_id ) {
			try {
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof WC_Order ) {
					$errors[] = $this->error( $order_id, 'WooCommerce order could not be loaded.' );
					continue;
				}
				$orders[] = $this->snapshot( $order );
			} catch ( Throwable $exception ) {
				$errors[] = $this->error( $order_id, 'WooCommerce order could not be loaded.' );
			}
		}

		return new HistoricalOrderPage( $orders, $errors, $page['has_more'] );
	}

	/**
	 * Query one stable ascending order-ID page.
	 *
	 * @param IndexingJob $job Claimed full-scan job.
	 * @return array{ids:int[],has_more:bool}
	 * @throws RuntimeException When WooCommerce returns an invalid page.
	 */
	private function historical_ids( IndexingJob $job ): array {
		$result = wc_get_orders(
			array(
				'type'     => 'shop_order',
				'status'   => array_keys( wc_get_order_statuses() ),
				'limit'    => $job->batch_size,
				'paged'    => $job->page,
				'paginate' => true,
				'return'   => 'ids',
				'orderby'  => 'ID',
				'order'    => 'ASC',
			)
		);
		if ( ! is_object( $result ) || ! isset( $result->orders, $result->max_num_pages ) || ! is_array( $result->orders ) ) {
			throw new RuntimeException( 'WooCommerce historical order query returned an invalid page.' );
		}

		$ids = array();
		foreach ( $result->orders as $order_id ) {
			if ( is_numeric( $order_id ) && (int) $order_id > 0 ) {
				$ids[] = (int) $order_id;
			}
		}

		return array(
			'ids'      => $ids,
			'has_more' => $job->page < (int) $result->max_num_pages,
		);
	}

	/**
	 * Slice an explicit bounded target set without querying unrelated orders.
	 *
	 * @param IndexingJob $job Claimed targeted job.
	 * @return array{ids:int[],has_more:bool}
	 */
	private function targeted_ids( IndexingJob $job ): array {
		$offset = ( $job->page - 1 ) * $job->batch_size;
		$ids    = array_slice( $job->target_order_ids, $offset, $job->batch_size );

		return array(
			'ids'      => $ids,
			'has_more' => $offset + count( $ids ) < count( $job->target_order_ids ),
		);
	}

	/**
	 * Convert one WooCommerce order to a transport-neutral snapshot.
	 *
	 * @param WC_Order $order Loaded order.
	 * @throws RuntimeException When required historical facts are missing.
	 */
	private function snapshot( WC_Order $order ): HistoricalOrder {
		$created = $order->get_date_created();
		if ( null === $created ) {
			throw new RuntimeException( 'WooCommerce order creation time is unavailable.' );
		}

		$coupons = array();
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Coupon || $item->get_id() < 1 ) {
				continue;
			}
			$coupons[] = new HistoricalCoupon(
				$item->get_id(),
				$item->get_code(),
				wc_format_coupon_code( $item->get_code() ),
				wc_format_decimal( $item->get_discount(), 8 )
			);
		}

		$email = trim( $order->get_billing_email() );
		return new HistoricalOrder(
			$order->get_id(),
			$order->get_customer_id() > 0 ? $order->get_customer_id() : null,
			'' === $email ? null : $email,
			$order->get_status(),
			strtoupper( $order->get_currency() ),
			( new DateTimeImmutable( '@' . $created->getTimestamp() ) )->setTimezone( new DateTimeZone( 'UTC' ) ),
			$coupons,
			(float) $order->get_total_refunded() > 0.0
				&& (float) $order->get_remaining_refund_amount() <= 0.0
		);
	}

	/**
	 * Build one safe source error.
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
