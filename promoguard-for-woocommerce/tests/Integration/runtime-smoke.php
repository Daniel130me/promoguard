<?php
/**
 * Disposable wp-env runtime smoke test.
 *
 * Run with: wp-env run cli wp eval-file tests/Integration/runtime-smoke.php
 *
 * @package PromoGuard
 */

use PromoGuard\Activation\Migrator;
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
	$request = new WP_REST_Request( $method, $route );

	if ( array() !== $body ) {
		$request->set_body_params( $body );
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
	promoguard_smoke_assert(
		$status === $response->get_status(),
		sprintf( 'Expected REST status %d, received %d.', $status, $response->get_status() )
	);

	return $response->get_data();
}

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

promoguard_smoke_expect_status(
	promoguard_smoke_request( 'DELETE', sprintf( '/promoguard/v1/campaigns/%d', $campaign_one['id'] ) ),
	204
);

WP_CLI::success( 'PromoGuard runtime smoke test passed.' );
