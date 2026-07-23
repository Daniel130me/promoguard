<?php
/**
 * Historical WooCommerce order page.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Indexing;

use InvalidArgumentException;

/** Contains one bounded source page and safe order-loading errors. */
final class HistoricalOrderPage {
	/**
	 * Create a validated bounded source page.
	 *
	 * @param HistoricalOrder[]                             $orders   Loaded order snapshots.
	 * @param array<int,array{order_id:int,message:string}> $errors   Safe load failures.
	 * @param bool                                          $has_more Whether another page exists.
	 * @throws InvalidArgumentException When the page is malformed.
	 */
	public function __construct(
		public readonly array $orders,
		public readonly array $errors,
		public readonly bool $has_more
	) {

		foreach ( $errors as $error ) {
			if ( $error['order_id'] < 1 || '' === trim( $error['message'] ) ) {
				throw new InvalidArgumentException( 'Historical order page contains an invalid error.' );
			}
		}
	}
}
