<?php
/**
 * WordPress personal-data integration.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Privacy;

use PromoGuard\Customer\CustomerIdentifier;
use PromoGuard\Customer\EmailNormalizer;
use PromoGuard\Customer\IdentifierHasher;
use PromoGuard\Support\Options;
use RuntimeException;
use Throwable;
use WP_User;

/** Registers privacy policy, export, and erasure support. */
final class PrivacyService {
	private const PAGE_SIZE = 20;

	/**
	 * Configure privacy operations.
	 *
	 * @param PrivacyStore     $store      Personal-data store.
	 * @param EmailNormalizer  $normalizer Conservative email normalizer.
	 * @param IdentifierHasher $hasher     Non-reversible identifier hasher.
	 */
	public function __construct(
		private readonly PrivacyStore $store,
		private readonly EmailNormalizer $normalizer,
		private readonly IdentifierHasher $hasher
	) {}

	/**
	 * Build the service for the active WordPress site.
	 *
	 * @throws RuntimeException When the persistent identifier key is unavailable.
	 */
	public static function from_wordpress(): self {
		$hash_key = get_option( Options::HASH_KEY, false );
		if ( ! is_string( $hash_key ) || '' === $hash_key ) {
			throw new RuntimeException( 'PromoGuard customer identity key is unavailable.' );
		}

		return new self(
			PrivacyRepository::from_wordpress(),
			new EmailNormalizer(),
			new IdentifierHasher( $hash_key )
		);
	}

	/** Register WordPress privacy hooks. */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	/**
	 * Register the PromoGuard exporter.
	 *
	 * @param array<string,array<string,mixed>> $exporters Existing exporters.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['promoguard'] = array(
			'exporter_friendly_name' => __( 'PromoGuard promotion activity', 'promoguard-for-woocommerce' ),
			'callback'               => array( $this, 'export_personal_data' ),
		);

		return $exporters;
	}

	/**
	 * Register the PromoGuard eraser.
	 *
	 * @param array<string,array<string,mixed>> $erasers Existing erasers.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['promoguard'] = array(
			'eraser_friendly_name' => __( 'PromoGuard customer identifiers', 'promoguard-for-woocommerce' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);

		return $erasers;
	}

	/**
	 * Export one page of personal data for a WordPress privacy request.
	 *
	 * @param string $email_address Requested email address.
	 * @param int    $page          One-based page number.
	 * @return array{data:list<array<string,mixed>>,done:bool}
	 */
	public function export_personal_data( string $email_address, int $page = 1 ): array {
		return $this->export_for_identity(
			$email_address,
			$page,
			$this->wordpress_user_id( $email_address )
		);
	}

	/**
	 * Export a testable page for a known email and optional WordPress user.
	 *
	 * @param string   $email_address Requested email address.
	 * @param int      $page          One-based page number.
	 * @param int|null $wp_user_id    Matching WordPress user ID.
	 * @return array{data:list<array<string,mixed>>,done:bool}
	 */
	public function export_for_identity( string $email_address, int $page, ?int $wp_user_id ): array {
		$customer_id = $this->customer_id( $email_address, $wp_user_id );
		if ( null === $customer_id ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$page    = max( 1, $page );
		$records = $this->store->export_page( $customer_id, $page, self::PAGE_SIZE );
		$data    = array();

		foreach ( $records['states'] as $row ) {
			$data[] = $this->export_item( 'state', $row, $this->state_fields( $row ) );
		}
		foreach ( $records['usages'] as $row ) {
			$data[] = $this->export_item( 'usage', $row, $this->usage_fields( $row ) );
		}
		foreach ( $records['decisions'] as $row ) {
			$data[] = $this->export_item( 'decision', $row, $this->decision_fields( $row ) );
		}

		return array(
			'data' => $data,
			'done' => $records['done'],
		);
	}

	/**
	 * Erase direct identifiers for a WordPress privacy request.
	 *
	 * @param string $email_address Requested email address.
	 * @param int    $page          One-based page number.
	 * @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool}
	 */
	public function erase_personal_data( string $email_address, int $page = 1 ): array {
		if ( $page > 1 ) {
			return $this->erasure_result( false, false, array() );
		}

		return $this->erase_for_identity( $email_address, $this->wordpress_user_id( $email_address ) );
	}

	/**
	 * Erase direct identifiers for a known email and optional WordPress user.
	 *
	 * @param string   $email_address Requested email address.
	 * @param int|null $wp_user_id    Matching WordPress user ID.
	 * @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool}
	 */
	public function erase_for_identity( string $email_address, ?int $wp_user_id ): array {
		$customer_id = $this->customer_id( $email_address, $wp_user_id );
		if ( null === $customer_id ) {
			return $this->erasure_result( false, false, array() );
		}

		try {
			if ( ! $this->store->anonymize( $customer_id ) ) {
				return $this->erasure_result( false, false, array() );
			}
		} catch ( Throwable $exception ) {
			return $this->erasure_result(
				false,
				true,
				array( __( 'PromoGuard customer identifiers could not be removed. Anonymous promotion records were retained.', 'promoguard-for-woocommerce' ) )
			);
		}

		return $this->erasure_result(
			true,
			true,
			array( __( 'PromoGuard removed the customer identifiers. Promotion usage and decision records were retained without a direct customer identifier for accounting and abuse-prevention purposes.', 'promoguard-for-woocommerce' ) )
		);
	}

	/** Add transparent suggested text to the WordPress privacy policy guide. */
	public function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = __(
			'PromoGuard records promotion campaign activity needed to enforce usage rules and prevent repeated redemption. Records can include an internal customer reference, a non-reversible keyed hash of a user ID or billing email, campaign and coupon details, WooCommerce order references, discount amounts, currencies, eligibility decisions, and event times. PromoGuard does not send this information to an external service and does not collect telemetry. Administrators can export this activity through the WordPress personal-data tools. Erasure removes the WordPress user link and hashed identifiers. Anonymous promotion usage and aggregate records may be retained for accounting, fraud prevention, and lifetime-rule enforcement; eligibility decisions are retained for 365 days.',
			'promoguard-for-woocommerce'
		);

		wp_add_privacy_policy_content(
			__( 'PromoGuard for WooCommerce', 'promoguard-for-woocommerce' ),
			wp_kses_post( wpautop( $content, false ) )
		);
	}

	/**
	 * Resolve an internal customer without exposing or storing the raw email.
	 *
	 * @param string   $email_address Requested email address.
	 * @param int|null $wp_user_id    Matching WordPress user ID.
	 */
	private function customer_id( string $email_address, ?int $wp_user_id ): ?int {
		$normalized_email = $this->normalizer->normalize( $email_address );
		if ( null === $normalized_email ) {
			return null;
		}

		$email_hash = $this->hasher->hash( CustomerIdentifier::TYPE_EMAIL, $normalized_email );

		return $this->store->find_customer_id( $wp_user_id, $email_hash );
	}

	/**
	 * Find the WordPress user that owns an email address.
	 *
	 * @param string $email_address Requested email address.
	 */
	private function wordpress_user_id( string $email_address ): ?int {
		$user = get_user_by( 'email', $email_address );

		return $user instanceof WP_User && $user->ID > 0 ? $user->ID : null;
	}

	/**
	 * Build a WordPress personal-data export item.
	 *
	 * @param string                                $type   Stable record type.
	 * @param array<string,int|string|null>         $row    Source row.
	 * @param list<array{name:string,value:string}> $fields Human-readable fields.
	 * @return array<string,mixed>
	 */
	private function export_item( string $type, array $row, array $fields ): array {
		return array(
			'group_id'    => 'promoguard-activity',
			'group_label' => __( 'PromoGuard promotion activity', 'promoguard-for-woocommerce' ),
			'item_id'     => 'promoguard-' . $type . '-' . (string) ( $row['id'] ?? 'unknown' ),
			'data'        => $fields,
		);
	}

	/**
	 * Format campaign state fields.
	 *
	 * @param array<string,int|string|null> $row Source row.
	 * @return list<array{name:string,value:string}>
	 */
	private function state_fields( array $row ): array {
		return $this->fields(
			$row,
			array(
				'campaign_name'         => __( 'Campaign', 'promoguard-for-woocommerce' ),
				'campaign_id'           => __( 'Campaign ID', 'promoguard-for-woocommerce' ),
				'consumed_count'        => __( 'Consumed uses', 'promoguard-for-woocommerce' ),
				'reserved_count'        => __( 'Reserved uses', 'promoguard-for-woocommerce' ),
				'total_discount'        => __( 'Total discount', 'promoguard-for-woocommerce' ),
				'first_consumed_at_gmt' => __( 'First consumed at (GMT)', 'promoguard-for-woocommerce' ),
				'last_consumed_at_gmt'  => __( 'Last consumed at (GMT)', 'promoguard-for-woocommerce' ),
				'last_order_id'         => __( 'Last order ID', 'promoguard-for-woocommerce' ),
			)
		);
	}

	/**
	 * Format promotion usage fields.
	 *
	 * @param array<string,int|string|null> $row Source row.
	 * @return list<array{name:string,value:string}>
	 */
	private function usage_fields( array $row ): array {
		return $this->fields(
			$row,
			array(
				'campaign_name'   => __( 'Campaign', 'promoguard-for-woocommerce' ),
				'campaign_id'     => __( 'Campaign ID', 'promoguard-for-woocommerce' ),
				'order_id'        => __( 'Order ID', 'promoguard-for-woocommerce' ),
				'coupon_code'     => __( 'Coupon code', 'promoguard-for-woocommerce' ),
				'status'          => __( 'Usage status', 'promoguard-for-woocommerce' ),
				'order_status'    => __( 'Order status', 'promoguard-for-woocommerce' ),
				'discount_amount' => __( 'Discount amount', 'promoguard-for-woocommerce' ),
				'currency'        => __( 'Currency', 'promoguard-for-woocommerce' ),
				'reserved_at_gmt' => __( 'Reserved at (GMT)', 'promoguard-for-woocommerce' ),
				'consumed_at_gmt' => __( 'Consumed at (GMT)', 'promoguard-for-woocommerce' ),
				'released_at_gmt' => __( 'Released at (GMT)', 'promoguard-for-woocommerce' ),
				'restored_at_gmt' => __( 'Restored at (GMT)', 'promoguard-for-woocommerce' ),
				'created_at_gmt'  => __( 'Created at (GMT)', 'promoguard-for-woocommerce' ),
			)
		);
	}

	/**
	 * Format eligibility decision fields.
	 *
	 * @param array<string,int|string|null> $row Source row.
	 * @return list<array{name:string,value:string}>
	 */
	private function decision_fields( array $row ): array {
		return $this->fields(
			$row,
			array(
				'campaign_name'    => __( 'Campaign', 'promoguard-for-woocommerce' ),
				'campaign_id'      => __( 'Campaign ID', 'promoguard-for-woocommerce' ),
				'order_id'         => __( 'Order ID', 'promoguard-for-woocommerce' ),
				'coupon_code'      => __( 'Coupon code', 'promoguard-for-woocommerce' ),
				'context'          => __( 'Decision context', 'promoguard-for-woocommerce' ),
				'decision'         => __( 'Decision', 'promoguard-for-woocommerce' ),
				'reason'           => __( 'Decision reason', 'promoguard-for-woocommerce' ),
				'customer_message' => __( 'Customer message', 'promoguard-for-woocommerce' ),
				'created_at_gmt'   => __( 'Created at (GMT)', 'promoguard-for-woocommerce' ),
			)
		);
	}

	/**
	 * Convert selected row values to WordPress export fields.
	 *
	 * @param array<string,int|string|null> $row    Source row.
	 * @param array<string,string>          $labels Field labels keyed by source column.
	 * @return list<array{name:string,value:string}>
	 */
	private function fields( array $row, array $labels ): array {
		$fields = array();
		foreach ( $labels as $key => $label ) {
			if ( ! array_key_exists( $key, $row ) || null === $row[ $key ] || '' === $row[ $key ] ) {
				continue;
			}
			$fields[] = array(
				'name'  => $label,
				'value' => (string) $row[ $key ],
			);
		}

		return $fields;
	}

	/**
	 * Build the standard WordPress eraser result.
	 *
	 * @param bool     $removed  Whether identifiers were removed.
	 * @param bool     $retained Whether anonymous records remain.
	 * @param string[] $messages Human-readable status.
	 * @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool}
	 */
	private function erasure_result( bool $removed, bool $retained, array $messages ): array {
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => array_values( $messages ),
			'done'           => true,
		);
	}
}
