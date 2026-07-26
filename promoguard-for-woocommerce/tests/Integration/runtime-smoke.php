<?php
/**
 * Disposable wp-env runtime smoke test.
 *
 * Run with: wp-env run cli wp eval-file tests/Integration/runtime-smoke.php
 *
 * @package PromoGuard
 */

use PromoGuard\Activation\Migrator;
use PromoGuard\Customer\CustomerIdentifier;
use PromoGuard\Customer\IdentifierHasher;
use PromoGuard\Support\Capabilities;
use PromoGuard\Support\Options;
use PromoGuard\Support\TableNames;

if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'This smoke test must run inside WordPress.' );
}

/**
 * Fail the smoke test with a focused assertion message.
 *
 * @param bool   $condition Assertion result.
 * @param string $message   Failure message.
 * @throws RuntimeException When the assertion fails.
 */
function promoguard_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI-only diagnostic, never rendered in a web response.
		throw new RuntimeException( $message );
	}
}

/**
 * Dispatch one REST request with an optional JSON-compatible body.
 *
 * @param string              $method HTTP method.
 * @param string              $route  REST route.
 * @param array<string,mixed> $body   Optional request body.
 */
function promoguard_smoke_request( string $method, string $route, array $body = array() ): WP_REST_Response {
	$GLOBALS['promoguard_smoke_rest_error'] = null;
	$request                                = new WP_REST_Request( $method, $route );

	if ( array() !== $body ) {
		if ( 'GET' === $method ) {
			$request->set_query_params( $body );
		} else {
			$request->set_body_params( $body );
		}
	}

	return rest_do_request( $request );
}

/**
 * Require an expected REST status and return its response data.
 *
 * @param WP_REST_Response $response REST response.
 * @param int              $status   Expected status.
 * @return mixed Response data.
 */
function promoguard_smoke_expect_status( WP_REST_Response $response, int $status ): mixed {
	$rest_error = $GLOBALS['promoguard_smoke_rest_error'] ?? null;
	$details    = $rest_error instanceof Throwable
		? get_class( $rest_error ) . ': ' . $rest_error->getMessage()
		: wp_json_encode( $response->get_data() );

	promoguard_smoke_assert(
		$status === $response->get_status(),
		sprintf(
			'Expected REST status %d, received %d: %s',
			$status,
			$response->get_status(),
			$details
		)
	);

	return $response->get_data();
}

add_action(
	'promoguard_rest_error',
	static function ( Throwable $exception ): void {
		$GLOBALS['promoguard_smoke_rest_error'] = $exception;
	}
);

global $wpdb;

$tables      = TableNames::from_wordpress();
$table_names = $tables->all();
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One fixed metadata query against the disposable smoke-test database.
$engines = $wpdb->get_results( 'SHOW TABLE STATUS', OBJECT_K );

foreach ( $table_names as $table_name ) {
	promoguard_smoke_assert( isset( $engines[ $table_name ] ), "{$table_name} must exist." );
	promoguard_smoke_assert( 'InnoDB' === $engines[ $table_name ]->Engine, "{$table_name} must use InnoDB." );
}

promoguard_smoke_assert( PROMOGUARD_VERSION === get_option( Options::VERSION ), 'Plugin version option is stale.' );
promoguard_smoke_assert( Migrator::CURRENT_VERSION === get_option( Options::DB_VERSION ), 'Database version option is stale.' );
promoguard_smoke_assert( ! ( new Migrator() )->migrate(), 'Current migrations must be idempotent.' );
promoguard_smoke_assert( Options::storage_is_supported(), 'Transactional storage verification must pass.' );

$administrator = get_role( 'administrator' );
$shop_manager  = get_role( 'shop_manager' );

promoguard_smoke_assert( null !== $administrator, 'Administrator role is missing.' );
promoguard_smoke_assert( null !== $shop_manager, 'Shop Manager role is missing.' );
foreach ( Capabilities::administrator() as $capability ) {
	promoguard_smoke_assert( $administrator->has_cap( $capability ), "Administrator lacks {$capability}." );
}
foreach ( Capabilities::shop_manager() as $capability ) {
	promoguard_smoke_assert( $shop_manager->has_cap( $capability ), "Shop Manager lacks {$capability}." );
}
promoguard_smoke_assert(
	! $shop_manager->has_cap( Capabilities::MANAGE_SETTINGS ),
	'Shop Manager must not manage PromoGuard settings.'
);

$shop_manager_user_id = wp_insert_user(
	array(
		'user_login' => 'promoguard_runtime_shop_manager_' . strtolower( wp_generate_password( 8, false, false ) ),
		'user_pass'  => wp_generate_password( 24, true, true ),
		'user_email' => 'promoguard-runtime-' . strtolower( wp_generate_password( 8, false, false ) ) . '@example.test',
		'role'       => 'shop_manager',
	)
);
promoguard_smoke_assert( ! is_wp_error( $shop_manager_user_id ), 'Shop Manager runtime user could not be created.' );

wp_set_current_user( 0 );
$unauthorized = promoguard_smoke_request( 'GET', '/promoguard/v1/campaigns' );
promoguard_smoke_assert( 401 === $unauthorized->get_status() || 403 === $unauthorized->get_status(), 'Campaign routes must reject anonymous requests.' );
foreach (
	array(
		'/promoguard/v1/administration/overview',
		'/promoguard/v1/administration/usages',
		'/promoguard/v1/administration/decisions',
		'/promoguard/v1/analytics/summary',
		'/promoguard/v1/administration/settings',
		'/promoguard/v1/administration/indexing',
	) as $administration_route
) {
	$unauthorized = promoguard_smoke_request( 'GET', $administration_route );
	promoguard_smoke_assert(
		401 === $unauthorized->get_status() || 403 === $unauthorized->get_status(),
		"{$administration_route} must reject anonymous requests."
	);
}

wp_set_current_user( (int) $shop_manager_user_id );
promoguard_smoke_expect_status(
	promoguard_smoke_request( 'GET', '/promoguard/v1/administration/overview' ),
	200
);
promoguard_smoke_expect_status(
	promoguard_smoke_request( 'GET', '/promoguard/v1/analytics/summary' ),
	200
);
promoguard_smoke_expect_status(
	promoguard_smoke_request( 'GET', '/promoguard/v1/administration/indexing' ),
	200
);
$forbidden_settings = promoguard_smoke_request( 'GET', '/promoguard/v1/administration/settings' );
promoguard_smoke_assert( 403 === $forbidden_settings->get_status(), 'Shop Manager must not read administrator-only settings.' );

wp_set_current_user( 1 );
$overview = promoguard_smoke_expect_status(
	promoguard_smoke_request( 'GET', '/promoguard/v1/administration/overview' ),
	200
);
promoguard_smoke_assert( isset( $overview['campaigns'], $overview['usages'], $overview['totals'] ), 'Administration overview is incomplete.' );

$usage_page = promoguard_smoke_expect_status(
	promoguard_smoke_request(
		'GET',
		'/promoguard/v1/administration/usages',
		array(
			'page'     => 1,
			'per_page' => 20,
		)
	),
	200
);
promoguard_smoke_assert( 1 === $usage_page['page'] && 20 === $usage_page['per_page'], 'Usage history pagination is unstable.' );

$decision_page = promoguard_smoke_expect_status(
	promoguard_smoke_request(
		'GET',
		'/promoguard/v1/administration/decisions',
		array(
			'page'     => 1,
			'per_page' => 20,
		)
	),
	200
);
promoguard_smoke_assert( 1 === $decision_page['page'] && 20 === $decision_page['per_page'], 'Decision history pagination is unstable.' );

$settings = promoguard_smoke_expect_status(
	promoguard_smoke_request( 'GET', '/promoguard/v1/administration/settings' ),
	200
);
promoguard_smoke_assert( array_key_exists( 'storage_engine_supported', $settings ), 'Storage health is absent from settings.' );
promoguard_smoke_expect_status(
	promoguard_smoke_request(
		'PATCH',
		'/promoguard/v1/administration/settings',
		array( 'delete_data_on_uninstall' => false )
	),
	200
);
promoguard_smoke_expect_status(
	promoguard_smoke_request(
		'POST',
		'/promoguard/v1/administration/indexing/start',
		array( 'batch_size' => 101 )
	),
	400
);

$run_id       = strtolower( wp_generate_password( 8, false, false ) );
$campaign_one = promoguard_smoke_expect_status(
	promoguard_smoke_request(
		'POST',
		'/promoguard/v1/campaigns',
		array(
			'name' => "Runtime Campaign One {$run_id}",
			'slug' => "runtime-campaign-one-{$run_id}",
		)
	),
	201
);
$campaign_two = promoguard_smoke_expect_status(
	promoguard_smoke_request(
		'POST',
		'/promoguard/v1/campaigns',
		array(
			'name' => "Runtime Campaign Two {$run_id}",
			'slug' => "runtime-campaign-two-{$run_id}",
		)
	),
	201
);

$coupon_code = 'promoguard-runtime-' . wp_generate_password( 8, false, false );
$coupon      = promoguard_smoke_expect_status(
	promoguard_smoke_request(
		'POST',
		'/promoguard/v1/coupons',
		array(
			'code'          => $coupon_code,
			'discount_type' => 'percent',
			'amount'        => '10',
			'description'   => 'PromoGuard disposable runtime smoke test.',
		)
	),
	201
);

$assignment = promoguard_smoke_expect_status(
	promoguard_smoke_request(
		'POST',
		sprintf( '/promoguard/v1/campaigns/%d/promotions', $campaign_one['id'] ),
		array( 'external_id' => $coupon['external_id'] )
	),
	201
);

// Early Phase 2 builds serialized empty settings as []; listing must remain backward compatible.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Creates one disposable legacy fixture for the REST compatibility check.
$legacy_settings_updated = $wpdb->update(
	$tables->campaign_promotions(),
	array( 'settings' => '[]' ),
	array( 'id' => $assignment['id'] ),
	array( '%s' ),
	array( '%d' )
);
promoguard_smoke_assert( false !== $legacy_settings_updated, 'Legacy assignment settings fixture could not be created.' );
$listed_assignments = promoguard_smoke_expect_status(
	promoguard_smoke_request(
		'GET',
		sprintf( '/promoguard/v1/campaigns/%d/promotions', $campaign_one['id'] ),
		array( 'per_page' => 100 )
	),
	200
);
promoguard_smoke_assert( $assignment['id'] === $listed_assignments[0]['id'], 'Legacy empty settings must remain listable.' );

promoguard_smoke_expect_status(
	promoguard_smoke_request(
		'POST',
		sprintf( '/promoguard/v1/campaigns/%d/promotions', $campaign_two['id'] ),
		array( 'external_id' => $coupon['external_id'] )
	),
	400
);

$reassigned = promoguard_smoke_expect_status(
	promoguard_smoke_request(
		'POST',
		sprintf( '/promoguard/v1/campaigns/%d/promotions', $campaign_two['id'] ),
		array(
			'external_id'        => $coupon['external_id'],
			'allow_reassignment' => true,
		)
	),
	201
);
promoguard_smoke_assert( $assignment['id'] === $reassigned['id'], 'Explicit reassignment must preserve the assignment identity.' );

promoguard_smoke_expect_status(
	promoguard_smoke_request( 'POST', sprintf( '/promoguard/v1/campaigns/%d/archive', $campaign_two['id'] ) ),
	200
);
promoguard_smoke_expect_status(
	promoguard_smoke_request(
		'DELETE',
		sprintf( '/promoguard/v1/campaigns/%d/promotions/%d', $campaign_two['id'], $reassigned['id'] )
	),
	400
);
promoguard_smoke_expect_status(
	promoguard_smoke_request( 'DELETE', sprintf( '/promoguard/v1/campaigns/%d', $campaign_two['id'] ) ),
	409
);
promoguard_smoke_assert( wc_get_coupon_id_by_code( $coupon_code ) === (int) $coupon['external_id'], 'Campaign operations must not delete the native coupon.' );

$analytics_time = current_time( 'mysql', true );
promoguard_smoke_assert(
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Creates one disposable analytics customer fixture.
	false !== $wpdb->insert(
		$tables->customers(),
		array(
			'wp_user_id'              => null,
			'merged_into_customer_id' => null,
			'created_at_gmt'          => $analytics_time,
			'updated_at_gmt'          => $analytics_time,
		)
	),
	'Analytics customer fixture could not be created.'
);
$analytics_customer_id = (int) $wpdb->insert_id;
$privacy_email         = 'promoguard-privacy-runtime@example.test';
$privacy_hash_key      = get_option( Options::HASH_KEY, '' );
promoguard_smoke_assert( is_string( $privacy_hash_key ) && '' !== $privacy_hash_key, 'Privacy hash key is unavailable.' );
$privacy_email_hash = ( new IdentifierHasher( $privacy_hash_key ) )->hash( CustomerIdentifier::TYPE_EMAIL, $privacy_email );
promoguard_smoke_assert(
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Creates one disposable privacy identifier fixture.
	false !== $wpdb->insert(
		$tables->customer_identifiers(),
		array(
			'customer_id'       => $analytics_customer_id,
			'identifier_type'   => CustomerIdentifier::TYPE_EMAIL,
			'identifier_hash'   => $privacy_email_hash,
			'is_primary'        => 1,
			'first_seen_at_gmt' => $analytics_time,
			'last_seen_at_gmt'  => $analytics_time,
		)
	),
	'Privacy email fixture could not be created.'
);

/**
 * Create one real WooCommerce order for HPOS-compatible revenue verification.
 *
 * @param string $currency Order currency.
 * @param string $total    Order total.
 */
$create_analytics_order   = static function ( string $currency, string $total ): WC_Order {
	$order = wc_create_order();
	if ( ! $order instanceof WC_Order ) {
		throw new RuntimeException( 'Analytics order fixture could not be created.' );
	}
	$order->set_currency( $currency );
	$order->set_total( $total );
	$order->set_status( 'completed' );
	$order->save();

	return $order;
};
$analytics_usd_order      = $create_analytics_order( 'USD', '100' );
$analytics_eur_order      = $create_analytics_order( 'EUR', '50' );
$analytics_restored_order = $create_analytics_order( 'USD', '70' );
$analytics_late_order     = $create_analytics_order( 'USD', '20' );

/**
 * Insert one isolated usage fact for analytics verification.
 *
 * @param string $status   Usage status.
 * @param int    $order_id WooCommerce order ID.
 * @param string $amount   Discount amount.
 * @param string $currency Order currency.
 */
$insert_analytics_usage = static function (
	string $status,
	int $order_id,
	string $amount,
	string $currency
) use (
	$wpdb,
	$tables,
	$campaign_two,
	$reassigned,
	$analytics_customer_id,
	$analytics_time,
	$coupon,
	$coupon_code
): void {
	$uuid = wp_generate_uuid4();
	promoguard_smoke_assert(
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Creates one disposable analytics usage fixture.
		false !== $wpdb->insert(
			$tables->usages(),
			array(
				'uuid'            => $uuid,
				'campaign_id'     => $campaign_two['id'],
				'promotion_id'    => $reassigned['id'],
				'customer_id'     => $analytics_customer_id,
				'order_id'        => $order_id,
				'coupon_id'       => $coupon['external_id'],
				'coupon_code'     => $coupon_code,
				'status'          => $status,
				'order_status'    => 'completed',
				'discount_amount' => $amount,
				'currency'        => $currency,
				'reservation_key' => hash( 'sha256', $uuid ),
				'consumed_at_gmt' => $analytics_time,
				'restored_at_gmt' => 'restored' === $status ? $analytics_time : null,
				'created_at_gmt'  => $analytics_time,
				'updated_at_gmt'  => $analytics_time,
				'metadata'        => '{}',
			)
		),
		'Analytics usage fixture could not be created.'
	);
};

$insert_analytics_usage( 'consumed', $analytics_usd_order->get_id(), '10', 'USD' );
$insert_analytics_usage( 'consumed', $analytics_eur_order->get_id(), '5', 'EUR' );
$insert_analytics_usage( 'restored', $analytics_restored_order->get_id(), '7', 'USD' );

foreach ( array( 'customer_limit_reached', 'campaign_paused' ) as $reason ) {
	promoguard_smoke_assert(
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Creates one disposable analytics denial fixture.
		false !== $wpdb->insert(
			$tables->decisions(),
			array(
				'request_id'        => wp_generate_uuid4(),
				'campaign_id'       => $campaign_two['id'],
				'promotion_id'      => $reassigned['id'],
				'customer_id'       => $analytics_customer_id,
				'coupon_id'         => $coupon['external_id'],
				'coupon_code'       => $coupon_code,
				'context'           => 'runtime_smoke',
				'decision'          => 'denied',
				'reason'            => $reason,
				'customer_message'  => 'Promotion unavailable.',
				'admin_explanation' => 'Disposable analytics fixture.',
				'metadata'          => '{}',
				'created_at_gmt'    => $analytics_time,
			)
		),
		'Analytics denial fixture could not be created.'
	);
}

$period_end   = new DateTimeImmutable( '+1 day', new DateTimeZone( 'UTC' ) );
$period_start = $period_end->modify( '-2 days' );
$analytics    = promoguard_smoke_expect_status(
	promoguard_smoke_request(
		'GET',
		'/promoguard/v1/analytics/summary',
		array(
			'starts_at_gmt' => $period_start->format( DATE_ATOM ),
			'ends_at_gmt'   => $period_end->format( DATE_ATOM ),
			'campaign_id'   => $campaign_two['id'],
		)
	),
	200
);
promoguard_smoke_assert(
	2 === $analytics['totals']['redemptions'],
	'Analytics redemptions do not reconcile: ' . wp_json_encode( $analytics )
);
promoguard_smoke_assert( 1 === $analytics['totals']['unique_customers'], 'Analytics customers must be distinct across currencies.' );
promoguard_smoke_assert( 2 === $analytics['totals']['global_orders'], 'Analytics global orders must be distinct.' );
promoguard_smoke_assert( 1 === $analytics['totals']['refunds'], 'Analytics restored usage count does not reconcile.' );
promoguard_smoke_assert( 2 === $analytics['totals']['denials'], 'Analytics denial count does not reconcile.' );
$currency_totals = array_column( $analytics['currencies'], null, 'currency' );
promoguard_smoke_assert( '5.00000000' === $currency_totals['EUR']['discount_amount'], 'EUR analytics discount is incorrect.' );
promoguard_smoke_assert( '10.00000000' === $currency_totals['USD']['discount_amount'], 'USD analytics discount is incorrect.' );
promoguard_smoke_assert( '7.00000000' === $currency_totals['USD']['restored_discount_amount'], 'USD restored discount is incorrect.' );
promoguard_smoke_assert( '50' === $currency_totals['EUR']['revenue_amount'], 'EUR order revenue is incorrect.' );
promoguard_smoke_assert( '100' === $currency_totals['USD']['revenue_amount'], 'USD order revenue is incorrect.' );
promoguard_smoke_assert( '10' === $currency_totals['USD']['average_discount_amount'], 'USD average discount is incorrect.' );

$campaign_analytics = promoguard_smoke_expect_status(
	promoguard_smoke_request(
		'GET',
		'/promoguard/v1/analytics/campaigns',
		array(
			'starts_at_gmt' => $period_start->format( DATE_ATOM ),
			'ends_at_gmt'   => $period_end->format( DATE_ATOM ),
			'campaign_id'   => $campaign_two['id'],
			'page'          => 1,
			'per_page'      => 10,
		)
	),
	200
);
promoguard_smoke_assert( 1 === $campaign_analytics['total'], 'Campaign analytics total is incorrect.' );
promoguard_smoke_assert( 2 === $campaign_analytics['items'][0]['redemptions'], 'Campaign analytics redemptions do not reconcile.' );
promoguard_smoke_assert( 2 === $campaign_analytics['items'][0]['denials'], 'Campaign analytics denials do not reconcile.' );

$insert_analytics_usage( 'consumed', $analytics_late_order->get_id(), '2', 'USD' );
$cached_analytics = promoguard_smoke_expect_status(
	promoguard_smoke_request(
		'GET',
		'/promoguard/v1/analytics/summary',
		array(
			'starts_at_gmt' => $period_start->format( DATE_ATOM ),
			'ends_at_gmt'   => $period_end->format( DATE_ATOM ),
			'campaign_id'   => $campaign_two['id'],
		)
	),
	200
);
promoguard_smoke_assert( 2 === $cached_analytics['totals']['redemptions'], 'Analytics summary was not served from cache.' );
$cached_campaign_analytics = promoguard_smoke_expect_status(
	promoguard_smoke_request(
		'GET',
		'/promoguard/v1/analytics/campaigns',
		array(
			'starts_at_gmt' => $period_start->format( DATE_ATOM ),
			'ends_at_gmt'   => $period_end->format( DATE_ATOM ),
			'campaign_id'   => $campaign_two['id'],
			'page'          => 1,
			'per_page'      => 10,
		)
	),
	200
);
promoguard_smoke_assert( 2 === $cached_campaign_analytics['items'][0]['redemptions'], 'Campaign analytics page was not served from cache.' );
do_action( 'promoguard_analytics_changed' );
$invalidated_analytics = promoguard_smoke_expect_status(
	promoguard_smoke_request(
		'GET',
		'/promoguard/v1/analytics/summary',
		array(
			'starts_at_gmt' => $period_start->format( DATE_ATOM ),
			'ends_at_gmt'   => $period_end->format( DATE_ATOM ),
			'campaign_id'   => $campaign_two['id'],
		)
	),
	200
);
promoguard_smoke_assert( 3 === $invalidated_analytics['totals']['redemptions'], 'Analytics cache invalidation did not expose the new usage.' );
$invalidated_currency_totals = array_column( $invalidated_analytics['currencies'], null, 'currency' );
promoguard_smoke_assert( '120' === $invalidated_currency_totals['USD']['revenue_amount'], 'Invalidated USD revenue is incorrect.' );
promoguard_smoke_assert( '60' === $invalidated_currency_totals['USD']['average_order_amount'], 'Invalidated USD average order is incorrect.' );
$invalidated_campaign_analytics = promoguard_smoke_expect_status(
	promoguard_smoke_request(
		'GET',
		'/promoguard/v1/analytics/campaigns',
		array(
			'starts_at_gmt' => $period_start->format( DATE_ATOM ),
			'ends_at_gmt'   => $period_end->format( DATE_ATOM ),
			'campaign_id'   => $campaign_two['id'],
			'page'          => 1,
			'per_page'      => 10,
		)
	),
	200
);
promoguard_smoke_assert( 3 === $invalidated_campaign_analytics['items'][0]['redemptions'], 'Campaign analytics invalidation did not expose the new usage.' );

promoguard_smoke_expect_status(
	promoguard_smoke_request(
		'GET',
		'/promoguard/v1/analytics/summary',
		array(
			'starts_at_gmt' => $period_end->modify( '-400 days' )->format( DATE_ATOM ),
			'ends_at_gmt'   => $period_end->format( DATE_ATOM ),
		)
	),
	400
);
$privacy_exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
promoguard_smoke_assert( isset( $privacy_exporters['promoguard']['callback'] ), 'Privacy exporter is not registered.' );
$privacy_export = call_user_func( $privacy_exporters['promoguard']['callback'], $privacy_email, 1 );
promoguard_smoke_assert( true === $privacy_export['done'], 'Privacy export did not finish its bounded page.' );
promoguard_smoke_assert( count( $privacy_export['data'] ) >= 6, 'Privacy export omitted usage or decision records.' );
promoguard_smoke_assert(
	! str_contains( (string) wp_json_encode( $privacy_export ), $privacy_email_hash ),
	'Privacy export exposed an internal identifier hash.'
);

$privacy_erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
promoguard_smoke_assert( isset( $privacy_erasers['promoguard']['callback'] ), 'Privacy eraser is not registered.' );
$privacy_erasure = call_user_func( $privacy_erasers['promoguard']['callback'], $privacy_email, 1 );
promoguard_smoke_assert( true === $privacy_erasure['items_removed'], 'Privacy eraser did not remove identifiers.' );
promoguard_smoke_assert( true === $privacy_erasure['items_retained'], 'Privacy eraser did not disclose retained accounting records.' );
$identifiers_table = $tables->customer_identifiers();
$usages_table      = $tables->usages();
promoguard_smoke_assert(
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Verifies disposable privacy fixture anonymization.
	0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$identifiers_table} WHERE customer_id = %d", $analytics_customer_id ) ),
	'Privacy eraser left a customer identifier behind.'
);
promoguard_smoke_assert(
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Verifies retained disposable accounting fixtures.
	4 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$usages_table} WHERE customer_id = %d", $analytics_customer_id ) ),
	'Privacy eraser removed anonymous usage accounting records.'
);
promoguard_smoke_expect_status(
	promoguard_smoke_request( 'DELETE', sprintf( '/promoguard/v1/campaigns/%d', $campaign_one['id'] ) ),
	204
);

WP_CLI::success( 'PromoGuard runtime smoke test passed.' );
