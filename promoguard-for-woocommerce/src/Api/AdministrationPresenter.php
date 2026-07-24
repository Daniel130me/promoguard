<?php
/**
 * Administration REST representation mapper.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Api;

/** Maps database read models to stable, typed API resources. */
final class AdministrationPresenter {
	private const DATE_KEYS = array(
		'reserved_at_gmt',
		'consumed_at_gmt',
		'released_at_gmt',
		'restored_at_gmt',
		'created_at_gmt',
	);

	/**
	 * Present one operational overview.
	 *
	 * @param array<string,mixed> $overview Repository overview.
	 * @return array<string,mixed>
	 */
	public function overview( array $overview ): array {
		return $overview;
	}

	/**
	 * Present one usage page.
	 *
	 * @param array{items: array<int,array<string,mixed>>, total:int, page:int, per_page:int} $page Repository page.
	 * @return array{items: array<int,array<string,mixed>>, total:int, page:int, per_page:int}
	 */
	public function usages( array $page ): array {
		return $this->page( $page, true );
	}

	/**
	 * Present one decision page.
	 *
	 * @param array{items: array<int,array<string,mixed>>, total:int, page:int, per_page:int} $page Repository page.
	 * @return array{items: array<int,array<string,mixed>>, total:int, page:int, per_page:int}
	 */
	public function decisions( array $page ): array {
		return $this->page( $page, false );
	}

	/**
	 * Normalize identifiers, amounts, nullable values, and timestamps.
	 *
	 * @param array{items: array<int,array<string,mixed>>, total:int, page:int, per_page:int} $page     Repository page.
	 * @param bool                                                                            $is_usage Whether usage-only amount fields are present.
	 * @return array{items: array<int,array<string,mixed>>, total:int, page:int, per_page:int}
	 */
	private function page( array $page, bool $is_usage ): array {
		$integer_keys = array( 'id', 'campaign_id', 'customer_id', 'wp_user_id', 'order_id' );
		$items        = array();

		foreach ( $page['items'] as $row ) {
			foreach ( $integer_keys as $key ) {
				if ( array_key_exists( $key, $row ) ) {
					$row[ $key ] = null === $row[ $key ] ? null : (int) $row[ $key ];
				}
			}
			foreach ( self::DATE_KEYS as $key ) {
				if ( array_key_exists( $key, $row ) ) {
					$row[ $key ] = $this->date( $row[ $key ] );
				}
			}
			if ( $is_usage && array_key_exists( 'discount_amount', $row ) ) {
				$row['discount_amount'] = (string) $row['discount_amount'];
			}
			$items[] = $row;
		}

		return array(
			'items'    => $items,
			'total'    => $page['total'],
			'page'     => $page['page'],
			'per_page' => $page['per_page'],
		);
	}

	/**
	 * Convert a nullable database GMT timestamp to the REST format.
	 *
	 * @param mixed $value Database timestamp value.
	 */
	private function date( mixed $value ): ?string {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		return str_replace( ' ', 'T', $value ) . 'Z';
	}
}
