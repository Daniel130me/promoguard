<?php
/**
 * Customer persistence mapping.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Customer;

use DateTimeImmutable;
use DateTimeZone;

/** Converts validated customer database rows into domain snapshots. */
final class CustomerHydrator {
	/**
	 * Hydrate one persisted customer.
	 *
	 * @param array<string,mixed> $row Customer database row.
	 */
	public function from_row( array $row ): Customer {
		$gmt = new DateTimeZone( 'UTC' );

		return new Customer(
			id: (int) $row['id'],
			wp_user_id: null === $row['wp_user_id'] ? null : (int) $row['wp_user_id'],
			merged_into_customer_id: null === $row['merged_into_customer_id'] ? null : (int) $row['merged_into_customer_id'],
			created_at_gmt: new DateTimeImmutable( (string) $row['created_at_gmt'], $gmt ),
			updated_at_gmt: new DateTimeImmutable( (string) $row['updated_at_gmt'], $gmt )
		);
	}
}
