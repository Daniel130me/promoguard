<?php
/**
 * Disposable reservation, lifecycle, refund, and reconciliation smoke test.
 *
 * Run with: wp-env run cli wp eval-file tests/Integration/lifecycle-smoke.php
 *
 * @package PromoGuard
 */

use PromoGuard\Checkout\WooCommerceCheckout;
use PromoGuard\Reconciliation\ReconciliationRepository;
use PromoGuard\Reconciliation\ReconciliationService;
use PromoGuard\Reconciliation\WooCommerceReconciliation;
use PromoGuard\Reservation\UsageStatus;
use PromoGuard\Reservation\WooCommerceOrderLifecycle;
use PromoGuard\Reservation\WooCommerceUsageExpiration;
use PromoGuard\Support\TableNames;

if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'This smoke test must run inside WordPress.' );
}

/**
 * Fail with a focused CLI-only assertion.
 *
 * @param bool   $condition Assertion result.
 * @param string $message   Failure message.
 * @throws RuntimeException When the assertion fails.
 */
function promoguard_lifecycle_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI-only diagnostic.
		throw new RuntimeException( $message );
	}
}

/**
 * Dispatch one PromoGuard REST request.
 *
 * @param string              $method HTTP method.
 * @param string              $route  REST route.
 * @param array<string,mixed> $body   Optional request body.
 */
function promoguard_lifecycle_request( string $method, string $route, array $body = array() ): WP_REST_Response {
	$request = new WP_REST_Request( $method, $route );
	if ( array() !== $body ) {
		$request->set_body_params( $body );
	}

	return rest_do_request( $request );
}

/**
 * Require an expected REST response.
 *
 * @param WP_REST_Response $response REST response.
 * @param int              $status   Expected status.
 * @return mixed Response data.
 */
function promoguard_lifecycle_response( WP_REST_Response $response, int $status ): mixed {
	promoguard_lifecycle_assert(
		$status === $response->get_status(),
		sprintf( 'Expected REST status %d, received %d.', $status, $response->get_status() )
	);

	return $response->get_data();
}

/**
 * Create one payable order through WooCommerce CRUD APIs.
 *
 * @param WC_Product $product     Disposable product.
 * @param string     $coupon_code Native coupon code.
 * @param string     $email       Unique guest billing email.
 */
function promoguard_lifecycle_order( WC_Product $product, string $coupon_code, string $email ): WC_Order {
	$order = wc_create_order();
	promoguard_lifecycle_assert( $order instanceof WC_Order, 'WooCommerce order creation failed.' );

	$order->set_billing_email( $email );
	$order->set_currency( 'USD' );
	$order->add_product( $product, 1 );
	$applied = $order->apply_coupon( $coupon_code );
	promoguard_lifecycle_assert( ! is_wp_error( $applied ), 'Native coupon could not be applied to the order.' );
	$order->calculate_totals();
	$order->save();

	return $order;
}

/**
 * Create one non-gateway refund through WooCommerce CRUD APIs.
 *
 * @param WC_Order $order  Order being refunded.
 * @param string   $amount Positive refund amount.
 */
function promoguard_lifecycle_refund( WC_Order $order, string $amount ): WC_Order_Refund {
	$refund = wc_create_refund(
		array(
			'order_id'       => $order->get_id(),
			'amount'         => $amount,
			'reason'         => 'Disposable PromoGuard lifecycle fixture.',
			'refund_payment' => false,
			'restock_items'  => false,
		)
	);
	promoguard_lifecycle_assert( $refund instanceof WC_Order_Refund, 'WooCommerce refund creation failed.' );
	return $refund;
}

/**
 * Load one order/campaign usage row.
 *
 * @param int $order_id    WooCommerce order ID.
 * @param int $campaign_id PromoGuard campaign ID.
 * @return array<string,mixed>|null
 */
function promoguard_lifecycle_usage( int $order_id, int $campaign_id ): ?array {
	global $wpdb;

	$sql = $wpdb->prepare(
		'SELECT * FROM %i WHERE order_id = %d AND campaign_id = %d LIMIT 1',
		TableNames::from_wordpress()->usages(),
		$order_id,
		$campaign_id
	);
	promoguard_lifecycle_assert( null !== $sql, 'Usage lookup could not be prepared.' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Disposable integration assertion.
	$row = $wpdb->get_row( $sql, ARRAY_A );
	return is_array( $row ) ? $row : null;
}

/**
 * Load one customer/campaign state row.
 *
 * @param int $campaign_id PromoGuard campaign ID.
 * @param int $customer_id Internal customer ID.
 * @return array<string,mixed>|null
 */
function promoguard_lifecycle_state( int $campaign_id, int $customer_id ): ?array {
	global $wpdb;

	$sql = $wpdb->prepare(
		'SELECT * FROM %i WHERE campaign_id = %d AND customer_id = %d LIMIT 1',
		TableNames::from_wordpress()->customer_campaign_state(),
		$campaign_id,
		$customer_id
	);
	promoguard_lifecycle_assert( null !== $sql, 'State lookup could not be prepared.' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Disposable integration assertion.
	$row = $wpdb->get_row( $sql, ARRAY_A );
	return is_array( $row ) ? $row : null;
}

global $wpdb;

wp_set_current_user( 1 );
$run_id      = strtolower( wp_generate_password( 8, false, false ) );
$campaign    = promoguard_lifecycle_response(
	promoguard_lifecycle_request(
		'POST',
		'/promoguard/v1/campaigns',
		array(
			'name'   => "Lifecycle Campaign {$run_id}",
			'slug'   => "lifecycle-campaign-{$run_id}",
			'status' => 'active',
		)
	),
	201
);
$coupon_code = 'promoguard-lifecycle-' . $run_id;
$coupon      = promoguard_lifecycle_response(
	promoguard_lifecycle_request(
		'POST',
		'/promoguard/v1/coupons',
		array(
			'code'          => $coupon_code,
			'discount_type' => 'percent',
			'amount'        => '10',
			'description'   => 'Disposable PromoGuard lifecycle fixture.',
		)
	),
	201
);
$assignment  = promoguard_lifecycle_response(
	promoguard_lifecycle_request(
		'POST',
		sprintf( '/promoguard/v1/campaigns/%d/promotions', $campaign['id'] ),
		array( 'external_id' => $coupon['external_id'] )
	),
	201
);

$product = new WC_Product_Simple();
$product->set_name( "Lifecycle Product {$run_id}" );
$product->set_regular_price( '100' );
$product->set_status( 'publish' );
$product->save();
promoguard_lifecycle_assert( $product->get_id() > 0, 'Disposable product creation failed.' );

// Admin/REST-created order: first counted status validates, reserves, and consumes.
$admin_order = promoguard_lifecycle_order( $product, $coupon_code, "admin-{$run_id}@example.com" );
$admin_order->update_status( 'processing' );
$admin_usage = promoguard_lifecycle_usage( $admin_order->get_id(), (int) $campaign['id'] );
promoguard_lifecycle_assert( null !== $admin_usage, 'Admin order did not create campaign usage.' );
promoguard_lifecycle_assert( UsageStatus::CONSUMED === $admin_usage['status'], 'Admin order usage was not consumed.' );
$admin_state = promoguard_lifecycle_state( (int) $campaign['id'], (int) $admin_usage['customer_id'] );
promoguard_lifecycle_assert( null !== $admin_state, 'Admin order state is missing.' );
promoguard_lifecycle_assert( 1 === (int) $admin_state['consumed_count'], 'Admin order consumption counter is incorrect.' );
promoguard_lifecycle_assert( 0 === (int) $admin_state['reserved_count'], 'Admin order reservation was not decremented.' );

// Repeated counted callbacks remain idempotent.
$admin_order->update_status( 'completed' );
$admin_state = promoguard_lifecycle_state( (int) $campaign['id'], (int) $admin_usage['customer_id'] );
promoguard_lifecycle_assert( 1 === (int) $admin_state['consumed_count'], 'Repeated status callback consumed twice.' );

// The same customer is denied on another admin-created order without removing financial data.
$denied_order = promoguard_lifecycle_order( $product, $coupon_code, "admin-{$run_id}@example.com" );
$denied_order->update_status( 'processing' );
promoguard_lifecycle_assert(
	null === promoguard_lifecycle_usage( $denied_order->get_id(), (int) $campaign['id'] ),
	'Denied admin order must not create or consume usage.'
);
$denial_marker = $denied_order->get_meta( '_promoguard_order_validation_denials', true );
promoguard_lifecycle_assert(
	is_array( $denial_marker ) && isset( $denial_marker[ $campaign['id'] ] ),
	'Denied admin order is missing its idempotency marker.'
);

// Partial refunds retain usage; the cumulative full refund restores it once.
promoguard_lifecycle_refund( $admin_order, '40' );
$admin_usage = promoguard_lifecycle_usage( $admin_order->get_id(), (int) $campaign['id'] );
$admin_state = promoguard_lifecycle_state( (int) $campaign['id'], (int) $admin_usage['customer_id'] );
promoguard_lifecycle_assert( UsageStatus::CONSUMED === $admin_usage['status'], 'Partial refund restored usage early.' );
promoguard_lifecycle_assert( 1 === (int) $admin_state['consumed_count'], 'Partial refund changed consumption.' );

$admin_order = wc_get_order( $admin_order->get_id() );
promoguard_lifecycle_assert( $admin_order instanceof WC_Order, 'Refunded order could not be reloaded.' );
$full_refund = promoguard_lifecycle_refund(
	$admin_order,
	wc_format_decimal( $admin_order->get_remaining_refund_amount(), wc_get_price_decimals() )
);
$admin_usage = promoguard_lifecycle_usage( $admin_order->get_id(), (int) $campaign['id'] );
$admin_state = promoguard_lifecycle_state( (int) $campaign['id'], (int) $admin_usage['customer_id'] );
promoguard_lifecycle_assert( UsageStatus::RESTORED === $admin_usage['status'], 'Cumulative full refund did not restore usage.' );
promoguard_lifecycle_assert( 0 === (int) $admin_state['consumed_count'], 'Full refund did not decrement consumption.' );
promoguard_lifecycle_assert( 0.0 === (float) $admin_state['total_discount'], 'Full refund did not restore discount totals.' );

do_action( 'woocommerce_order_refunded', $admin_order->get_id(), $full_refund->get_id() );
$admin_state = promoguard_lifecycle_state( (int) $campaign['id'], (int) $admin_usage['customer_id'] );
promoguard_lifecycle_assert( 0 === (int) $admin_state['consumed_count'], 'Repeated refund hook restored usage twice.' );
$admin_order = wc_get_order( $admin_order->get_id() );
promoguard_lifecycle_assert( $admin_order instanceof WC_Order, 'Refunded order outcome could not be reloaded.' );
$refund_marker = $admin_order->get_meta( '_promoguard_refund_outcomes', true );
promoguard_lifecycle_assert(
	is_array( $refund_marker )
		&& isset( $refund_marker[ $campaign['id'] ] )
		&& 'restored' === $refund_marker[ $campaign['id'] ],
	'Full refund is missing its deduplicated outcome marker.'
);

// Checkout reservation retries are idempotent and failed payment releases once.
$checkout     = WooCommerceCheckout::from_wordpress();
$failed_order = promoguard_lifecycle_order( $product, $coupon_code, "failed-{$run_id}@example.com" );
$checkout->reserve_store_api_order( $failed_order );
$checkout->reserve_store_api_order( $failed_order );
$failed_usage = promoguard_lifecycle_usage( $failed_order->get_id(), (int) $campaign['id'] );
promoguard_lifecycle_assert( UsageStatus::PENDING === $failed_usage['status'], 'Checkout reservation is not pending.' );
$failed_state = promoguard_lifecycle_state( (int) $campaign['id'], (int) $failed_usage['customer_id'] );
promoguard_lifecycle_assert( 1 === (int) $failed_state['reserved_count'], 'Idempotent reservation counted more than once.' );
$failed_order->update_status( 'failed' );
$failed_usage = promoguard_lifecycle_usage( $failed_order->get_id(), (int) $campaign['id'] );
$failed_state = promoguard_lifecycle_state( (int) $campaign['id'], (int) $failed_usage['customer_id'] );
promoguard_lifecycle_assert( UsageStatus::RELEASED === $failed_usage['status'], 'Failed payment did not release usage.' );
promoguard_lifecycle_assert( 0 === (int) $failed_state['reserved_count'], 'Failed payment did not decrement reservation.' );

// Bounded background cleanup releases expired pending usage.
$expired_order = promoguard_lifecycle_order( $product, $coupon_code, "expired-{$run_id}@example.com" );
$checkout->reserve_store_api_order( $expired_order );
$expired_usage = promoguard_lifecycle_usage( $expired_order->get_id(), (int) $campaign['id'] );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable expiry fixture.
$expired_updated = $wpdb->update(
	TableNames::from_wordpress()->usages(),
	array( 'reserved_until_gmt' => '2000-01-01 00:00:00' ),
	array( 'id' => $expired_usage['id'] ),
	array( '%s' ),
	array( '%d' )
);
promoguard_lifecycle_assert( 1 === $expired_updated, 'Expired reservation fixture could not be updated.' );
WooCommerceUsageExpiration::from_wordpress()->run();
$expired_usage = promoguard_lifecycle_usage( $expired_order->get_id(), (int) $campaign['id'] );
promoguard_lifecycle_assert( UsageStatus::RELEASED === $expired_usage['status'], 'Expiration batch did not release usage.' );

( WooCommerceUsageExpiration::from_wordpress() )->ensure_scheduled();
promoguard_lifecycle_assert(
	function_exists( 'as_has_scheduled_action' )
		&& as_has_scheduled_action( 'promoguard_release_expired_reservations', array(), 'promoguard' ),
	'PromoGuard recurring expiration action is not scheduled.'
);

// A persisted usage still consumes after its live assignment is detached.
$snapshot_order = promoguard_lifecycle_order( $product, $coupon_code, "snapshot-{$run_id}@example.com" );
$checkout->reserve_store_api_order( $snapshot_order );
promoguard_lifecycle_response(
	promoguard_lifecycle_request(
		'DELETE',
		sprintf( '/promoguard/v1/campaigns/%d/promotions/%d', $campaign['id'], $assignment['id'] )
	),
	204
);
( WooCommerceOrderLifecycle::from_wordpress() )->transition_order(
	$snapshot_order->get_id(),
	'pending',
	'processing',
	$snapshot_order
);
$snapshot_usage = promoguard_lifecycle_usage( $snapshot_order->get_id(), (int) $campaign['id'] );
promoguard_lifecycle_assert( UsageStatus::CONSUMED === $snapshot_usage['status'], 'Detached coupon usage snapshot was not consumed.' );
promoguard_lifecycle_assert(
	wc_get_coupon_id_by_code( $coupon_code ) === (int) $coupon['external_id'],
	'Lifecycle validation must not delete the native coupon.'
);

// Reconciliation repairs deliberately corrupted aggregates from the usage ledger.
$snapshot_state = promoguard_lifecycle_state( (int) $campaign['id'], (int) $snapshot_usage['customer_id'] );
promoguard_lifecycle_assert( null !== $snapshot_state, 'Snapshot state is missing before reconciliation.' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable corruption fixture.
$corrupted = $wpdb->update(
	TableNames::from_wordpress()->customer_campaign_state(),
	array(
		'consumed_count' => 9,
		'reserved_count' => 8,
		'total_discount' => '999',
	),
	array( 'id' => $snapshot_state['id'] ),
	array( '%d', '%d', '%s' ),
	array( '%d' )
);
promoguard_lifecycle_assert( 1 === $corrupted, 'Reconciliation corruption fixture could not be written.' );

$reconciliation = new ReconciliationService( new ReconciliationRepository( TableNames::from_wordpress() ) );
$batch          = $reconciliation->reconcile_batch(
	new DateTimeImmutable( current_time( 'mysql', true ), new DateTimeZone( 'UTC' ) ),
	0,
	100
);
promoguard_lifecycle_assert( $batch->processed > 0, 'Reconciliation did not inspect any state rows.' );
promoguard_lifecycle_assert( $batch->changed > 0, 'Reconciliation did not repair corrupted state.' );
$snapshot_state = promoguard_lifecycle_state( (int) $campaign['id'], (int) $snapshot_usage['customer_id'] );
promoguard_lifecycle_assert( 1 === (int) $snapshot_state['consumed_count'], 'Rebuilt consumed count does not match usages.' );
promoguard_lifecycle_assert( 0 === (int) $snapshot_state['reserved_count'], 'Rebuilt reserved count does not match usages.' );
promoguard_lifecycle_assert(
	(float) $snapshot_usage['discount_amount'] === (float) $snapshot_state['total_discount'],
	'Rebuilt discount total does not match consumed usages.'
);

( WooCommerceReconciliation::from_wordpress() )->ensure_scheduled();
promoguard_lifecycle_assert(
	function_exists( 'as_has_scheduled_action' )
		&& as_has_scheduled_action( 'promoguard_reconcile_usage_state', array( 0 ), 'promoguard' ),
	'PromoGuard recurring reconciliation action is not scheduled.'
);

WP_CLI::success( 'PromoGuard Phase 7 lifecycle smoke test passed.' );
