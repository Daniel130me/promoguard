<?php
/**
 * Promotion source contract.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Promotion;

/** Defines only the source operations required by campaign administration. */
interface PromotionSource {
	/** Return the stable source key. */
	public function source(): string;

	/** Return the promotion type managed by this adapter. */
	public function source_type(): string;

	/**
	 * Resolve one source promotion by its stable external identifier.
	 *
	 * @param string $external_id Source identifier.
	 */
	public function resolve( string $external_id ): ?Promotion;

	/**
	 * Return a bounded source search result.
	 *
	 * @param string $term  Search text.
	 * @param int    $limit Maximum result count.
	 * @return Promotion[]
	 */
	public function search( string $term, int $limit ): array;

	/**
	 * Create a source promotion from validated native-coupon input.
	 *
	 * @param CouponDraft $draft Validated coupon creation input.
	 */
	public function create( CouponDraft $draft ): Promotion;
}
