<?php
/**
 * Native WooCommerce coupon source.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Promotion;

use DomainException;
use Exception;
use RuntimeException;
use WC_Coupon;
use WP_Query;

/** Resolves and creates coupons exclusively through public WooCommerce CRUD. */
final class WooCommerceCouponSource implements PromotionSource {
	public const SOURCE      = 'woocommerce';
	public const SOURCE_TYPE = 'coupon';

	private const MAX_SEARCH_RESULTS = 50;

	/** Return the stable WooCommerce source key. */
	public function source(): string {
		return self::SOURCE;
	}

	/** Return the native coupon source type. */
	public function source_type(): string {
		return self::SOURCE_TYPE;
	}

	/**
	 * Resolve one native coupon through WooCommerce CRUD.
	 *
	 * @param string $external_id Coupon post identifier.
	 */
	public function resolve( string $external_id ): ?Promotion {
		if ( ! ctype_digit( $external_id ) || (int) $external_id < 1 ) {
			return null;
		}

		try {
			$coupon = new WC_Coupon( (int) $external_id );
		} catch ( Exception $exception ) {
			return null;
		}

		if ( $coupon->get_id() < 1 || '' === $coupon->get_code() ) {
			return null;
		}

		return $this->to_promotion( $coupon );
	}

	/**
	 * Search native coupons with a bounded WordPress query.
	 *
	 * @param string $term  Coupon code or text fragment.
	 * @param int    $limit Maximum result count.
	 * @return Promotion[]
	 */
	public function search( string $term, int $limit ): array {
		$limit = min( self::MAX_SEARCH_RESULTS, max( 1, $limit ) );
		$term  = sanitize_text_field( $term );
		$ids   = array();

		if ( '' !== $term ) {
			$exact_id = wc_get_coupon_id_by_code( $term );
			if ( $exact_id > 0 ) {
				$ids[] = $exact_id;
			}
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'shop_coupon',
				'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page'         => $limit,
				's'                      => $term,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $query->posts as $coupon_id ) {
			if ( is_numeric( $coupon_id ) ) {
				$ids[] = (int) $coupon_id;
			}
		}

		$promotions = array();
		foreach ( array_slice( array_values( array_unique( $ids ) ), 0, $limit ) as $coupon_id ) {
			$promotion = $this->resolve( (string) $coupon_id );
			if ( null !== $promotion ) {
				$promotions[] = $promotion;
			}
		}

		return $promotions;
	}

	/**
	 * Create a native coupon without attaching PromoGuard metadata to it.
	 *
	 * @param CouponDraft $draft Validated coupon creation input.
	 * @throws DomainException  When the coupon code already exists.
	 * @throws RuntimeException When WooCommerce cannot persist the coupon.
	 */
	public function create( CouponDraft $draft ): Promotion {
		if ( wc_get_coupon_id_by_code( $draft->code ) > 0 ) {
			throw new DomainException( 'A WooCommerce coupon with this code already exists.' );
		}

		$coupon = new WC_Coupon();
		$coupon->set_code( $draft->code );
		$coupon->set_discount_type( $draft->discount_type );
		$coupon->set_amount( $draft->amount );
		$coupon->set_description( $draft->description );

		try {
			$coupon_id = $coupon->save();
		} catch ( Exception $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The previous exception is retained for diagnostic context and never rendered.
			throw new RuntimeException( 'WooCommerce coupon could not be created.', 0, $exception );
		}

		if ( $coupon_id < 1 ) {
			throw new RuntimeException( 'WooCommerce coupon could not be created.' );
		}

		return $this->to_promotion( $coupon );
	}

	/**
	 * Map a native coupon to the source-neutral result.
	 *
	 * @param WC_Coupon $coupon Loaded native coupon.
	 */
	private function to_promotion( WC_Coupon $coupon ): Promotion {
		$description = trim( $coupon->get_description() );

		return new Promotion(
			self::SOURCE,
			self::SOURCE_TYPE,
			(string) $coupon->get_id(),
			$coupon->get_code(),
			'' === $description ? $coupon->get_code() : $description,
			'publish' === $coupon->get_status()
		);
	}
}
