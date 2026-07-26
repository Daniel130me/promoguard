<?php
/**
 * Privacy database repository.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Privacy;

use PromoGuard\Support\TableNames;
use RuntimeException;
use Throwable;

/** Reads and anonymizes personal data through bounded, indexed queries. */
final class PrivacyRepository implements PrivacyStore {
	/**
	 * Configure site-scoped privacy persistence.
	 *
	 * @param TableNames $tables Plugin table names.
	 */
	public function __construct( private readonly TableNames $tables ) {}

	/** Build a repository for the active WordPress site. */
	public static function from_wordpress(): self {
		return new self( TableNames::from_wordpress() );
	}

	/**
	 * Find the canonical customer linked to a WordPress user or email hash.
	 *
	 * @param int|null $wp_user_id WordPress user ID when available.
	 * @param string   $email_hash Domain-separated email hash.
	 */
	public function find_customer_id( ?int $wp_user_id, string $email_hash ): ?int {
		global $wpdb;

		$customers = $this->tables->customers();
		if ( null !== $wp_user_id && $wp_user_id > 0 ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned table; user ID is prepared.
			$user_sql = $wpdb->prepare(
				"SELECT id FROM {$customers} WHERE wp_user_id = %d AND merged_into_customer_id IS NULL LIMIT 1",
				$wp_user_id
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Unique indexed lookup against plugin-owned data.
			$customer_id = $wpdb->get_var( $user_sql );
			if ( null !== $customer_id ) {
				return (int) $customer_id;
			}
		}

		$identifiers = $this->tables->customer_identifiers();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned tables; hash is prepared.
		$email_sql = $wpdb->prepare(
			"SELECT customer.id
			FROM {$identifiers} AS identifier
			INNER JOIN {$customers} AS customer ON customer.id = identifier.customer_id
			WHERE identifier.identifier_type = 'email'
			AND identifier.identifier_hash = %s
			AND customer.merged_into_customer_id IS NULL
			LIMIT 1",
			$email_hash
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Unique indexed identifier lookup against plugin-owned data.
		$customer_id = $wpdb->get_var( $email_sql );

		return null === $customer_id ? null : (int) $customer_id;
	}

	/**
	 * Return one bounded page from each personal-data category.
	 *
	 * @param int $customer_id Internal customer ID.
	 * @param int $page        One-based page number.
	 * @param int $limit       Maximum rows per category.
	 * @return array{
	 *   states:list<array<string,int|string|null>>,
	 *   usages:list<array<string,int|string|null>>,
	 *   decisions:list<array<string,int|string|null>>,
	 *   done:bool
	 * }
	 */
	public function export_page( int $customer_id, int $page, int $limit ): array {
		$offset    = ( max( 1, $page ) - 1 ) * $limit;
		$states    = $this->state_page( $customer_id, $limit, $offset );
		$usages    = $this->usage_page( $customer_id, $limit, $offset );
		$decisions = $this->decision_page( $customer_id, $limit, $offset );

		return array(
			'states'    => $states,
			'usages'    => $usages,
			'decisions' => $decisions,
			'done'      => count( $states ) < $limit && count( $usages ) < $limit && count( $decisions ) < $limit,
		);
	}

	/**
	 * Remove direct identifiers while retaining anonymous accounting records.
	 *
	 * @param int $customer_id Internal customer ID.
	 * @throws Throwable When anonymization cannot be completed atomically.
	 */
	public function anonymize( int $customer_id ): bool {
		global $wpdb;

		$this->require_query( 'START TRANSACTION', 'Privacy transaction could not be started.' );

		try {
			$customers = $this->tables->customers();
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned table; customer ID is prepared.
			$lock_sql = $wpdb->prepare(
				"SELECT id FROM {$customers} WHERE id = %d AND merged_into_customer_id IS NULL LIMIT 1 FOR UPDATE",
				$customer_id
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Primary-key lock inside the short anonymization transaction.
			$existing_id = $wpdb->get_var( $lock_sql );
			if ( null === $existing_id ) {
				$this->require_query( 'COMMIT', 'Privacy transaction could not be completed.' );
				return false;
			}

			$identifiers = $this->tables->customer_identifiers();
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned tables; customer ID and timestamp are prepared.
			$delete_sql = $wpdb->prepare( "DELETE FROM {$identifiers} WHERE customer_id = %d", $customer_id );
			$update_sql = $wpdb->prepare(
				"UPDATE {$customers} SET wp_user_id = NULL, updated_at_gmt = %s WHERE id = %d",
				current_time( 'mysql', true ),
				$customer_id
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

			$this->require_query( $delete_sql, 'Customer identifiers could not be removed.' );
			$this->require_query( $update_sql, 'Customer identity could not be anonymized.' );
			$this->require_query( 'COMMIT', 'Privacy transaction could not be completed.' );

			return true;
		} catch ( Throwable $exception ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Fixed rollback statement.
			$wpdb->query( 'ROLLBACK' );
			throw $exception;
		}
	}

	/**
	 * Read customer campaign counters in primary-key order.
	 *
	 * @param int $customer_id Internal customer ID.
	 * @param int $limit       Maximum rows.
	 * @param int $offset      Row offset.
	 * @return list<array<string,int|string|null>>
	 */
	private function state_page( int $customer_id, int $limit, int $offset ): array {
		global $wpdb;

		$states    = $this->tables->customer_campaign_state();
		$campaigns = $this->tables->campaigns();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned tables; paging values are prepared.
		$sql = $wpdb->prepare(
			"SELECT state.id, state.campaign_id, campaign.name AS campaign_name,
				state.consumed_count, state.reserved_count, state.total_discount,
				state.first_consumed_at_gmt, state.last_consumed_at_gmt, state.last_order_id
			FROM {$states} AS state
			LEFT JOIN {$campaigns} AS campaign ON campaign.id = state.campaign_id
			WHERE state.customer_id = %d
			ORDER BY state.id ASC
			LIMIT %d OFFSET %d",
			$customer_id,
			$limit,
			$offset
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return $this->rows( $sql );
	}

	/**
	 * Read promotion usage history in primary-key order.
	 *
	 * @param int $customer_id Internal customer ID.
	 * @param int $limit       Maximum rows.
	 * @param int $offset      Row offset.
	 * @return list<array<string,int|string|null>>
	 */
	private function usage_page( int $customer_id, int $limit, int $offset ): array {
		global $wpdb;

		$usages    = $this->tables->usages();
		$campaigns = $this->tables->campaigns();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned tables; paging values are prepared.
		$sql = $wpdb->prepare(
			"SELECT usage_record.id, usage_record.campaign_id, campaign.name AS campaign_name,
				usage_record.order_id, usage_record.coupon_code, usage_record.status,
				usage_record.order_status, usage_record.discount_amount, usage_record.currency,
				usage_record.reserved_at_gmt, usage_record.consumed_at_gmt,
				usage_record.released_at_gmt, usage_record.restored_at_gmt,
				usage_record.created_at_gmt
			FROM {$usages} AS usage_record
			LEFT JOIN {$campaigns} AS campaign ON campaign.id = usage_record.campaign_id
			WHERE usage_record.customer_id = %d
			ORDER BY usage_record.id ASC
			LIMIT %d OFFSET %d",
			$customer_id,
			$limit,
			$offset
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return $this->rows( $sql );
	}

	/**
	 * Read eligibility decisions in primary-key order.
	 *
	 * @param int $customer_id Internal customer ID.
	 * @param int $limit       Maximum rows.
	 * @param int $offset      Row offset.
	 * @return list<array<string,int|string|null>>
	 */
	private function decision_page( int $customer_id, int $limit, int $offset ): array {
		global $wpdb;

		$decisions = $this->tables->decisions();
		$campaigns = $this->tables->campaigns();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned tables; paging values are prepared.
		$sql = $wpdb->prepare(
			"SELECT decision_record.id, decision_record.campaign_id,
				campaign.name AS campaign_name, decision_record.order_id,
				decision_record.coupon_code, decision_record.context,
				decision_record.decision, decision_record.reason,
				decision_record.customer_message, decision_record.created_at_gmt
			FROM {$decisions} AS decision_record
			LEFT JOIN {$campaigns} AS campaign ON campaign.id = decision_record.campaign_id
			WHERE decision_record.customer_id = %d
			ORDER BY decision_record.id ASC
			LIMIT %d OFFSET %d",
			$customer_id,
			$limit,
			$offset
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return $this->rows( $sql );
	}

	/**
	 * Execute a prepared bounded select.
	 *
	 * @param string $sql Prepared SQL statement.
	 * @return list<array<string,int|string|null>>
	 */
	private function rows( string $sql ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared, bounded plugin-owned read.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$normalized_rows = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$normalized_row = array();
			foreach ( $row as $key => $value ) {
				if ( ! is_string( $key ) || ( ! is_int( $value ) && ! is_string( $value ) && null !== $value ) ) {
					continue;
				}
				$normalized_row[ $key ] = $value;
			}
			$normalized_rows[] = $normalized_row;
		}

		return $normalized_rows;
	}

	/**
	 * Execute a required write or transaction statement.
	 *
	 * @param string $sql     Prepared SQL or fixed transaction statement.
	 * @param string $message Safe failure message.
	 * @throws RuntimeException When the database rejects the statement.
	 */
	private function require_query( string $sql, string $message ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared by the private caller or a fixed transaction statement.
		if ( false === $wpdb->query( $sql ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Private callers provide fixed safe messages that are never rendered directly.
			throw new RuntimeException( $message );
		}
	}
}
