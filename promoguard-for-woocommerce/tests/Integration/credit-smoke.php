<?php
/**
 * Disposable store-credit reservation and ledger smoke test.
 *
 * Run with: wp-env run cli wp eval-file tests/Integration/credit-smoke.php
 *
 * @package PromoGuard
 */

use PromoGuard\Credit\CreditAccountViewRepository;
use PromoGuard\Credit\CreditRepository;
use PromoGuard\Credit\CreditRedemptionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'This smoke test must run inside WordPress.' );
}

/**
 * Fail with a focused CLI-only diagnostic.
 *
 * @param bool   $condition Assertion result.
 * @param string $message   Failure message.
 * @throws RuntimeException When the assertion fails.
 */
function promoguard_credit_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI-only diagnostic.
		throw new RuntimeException( $message );
	}
}

/**
 * Create one disposable HPOS/CRUD-compatible customer order.
 *
 * @param int    $user_id  Disposable customer ID.
 * @param string $currency Three-letter order currency.
 */
function promoguard_credit_order( int $user_id, string $currency ): WC_Order {
	$order = wc_create_order( array( 'customer_id' => $user_id ) );
	promoguard_credit_assert( $order instanceof WC_Order, 'WooCommerce order creation failed.' );
	$order->set_currency( $currency );
	$order->set_status( 'pending' );
	$order->save();
	return $order;
}

$run     = strtolower( wp_generate_password( 10, false, false ) );
$user_id = wp_insert_user(
	array(
		'user_login' => 'promoguard-credit-' . $run,
		'user_email' => 'promoguard-credit-' . $run . '@example.test',
		'user_pass'  => wp_generate_password( 24, true, true ),
		'role'       => 'customer',
	)
);
promoguard_credit_assert( is_int( $user_id ) && $user_id > 0, 'Disposable customer creation failed.' );

$credits     = CreditRepository::from_wordpress();
$redemptions = CreditRedemptionRepository::from_wordpress();
$accounts    = CreditAccountViewRepository::from_wordpress();

$usd_grant = $credits->grant(
	$user_id,
	'100',
	'USD',
	'test_credit',
	'credit_smoke',
	'usd:' . $run,
	'Disposable USD credit smoke fixture.'
);
$eur_grant = $credits->grant(
	$user_id,
	'25',
	'EUR',
	'test_credit',
	'credit_smoke',
	'eur:' . $run,
	'Disposable EUR credit smoke fixture.'
);
promoguard_credit_assert( $usd_grant->created && '100' === $usd_grant->balance, 'USD grant was not posted.' );
promoguard_credit_assert( $eur_grant->created && '25' === $eur_grant->balance, 'EUR grant was not posted.' );

$paid_order  = promoguard_credit_order( $user_id, 'USD' );
$reservation = $redemptions->reserve( $user_id, $paid_order->get_id(), '60', 'USD' );
promoguard_credit_assert( null !== $reservation && '60' === $reservation->amount, 'USD reservation failed.' );
$repeated_reservation = $redemptions->reserve( $user_id, $paid_order->get_id(), '90', 'USD' );
promoguard_credit_assert(
	null !== $repeated_reservation && $reservation->uuid === $repeated_reservation->uuid,
	'Retried checkout created a second reservation.'
);
promoguard_credit_assert( '40' === $redemptions->available_balance( $user_id, 'USD' ), 'Active reservation was not deducted from availability.' );
promoguard_credit_assert( '25' === $redemptions->available_balance( $user_id, 'EUR' ), 'USD reservation changed the EUR account.' );

$consumed = $redemptions->consume( $paid_order->get_id() );
promoguard_credit_assert( null !== $consumed && 'consumed' === $consumed->status, 'Paid reservation was not consumed.' );
$consumed_again = $redemptions->consume( $paid_order->get_id() );
promoguard_credit_assert(
	null !== $consumed_again && $consumed->consumption_transaction_id === $consumed_again->consumption_transaction_id,
	'Retried payment created a second debit.'
);
promoguard_credit_assert( '40' === $credits->balance( $user_id, 'USD' ), 'Consumed credit did not debit the USD balance.' );

$first_restore = $redemptions->restore( $paid_order->get_id(), $paid_order->get_id() + 1000000, '20' );
promoguard_credit_assert( $first_restore->created && '20' === $first_restore->amount, 'Partial refund credit was not restored.' );
$duplicate_restore = $redemptions->restore( $paid_order->get_id(), $paid_order->get_id() + 1000000, '20' );
promoguard_credit_assert( ! $duplicate_restore->created, 'Retried refund restored credit twice.' );
$capped_restore = $redemptions->restore( $paid_order->get_id(), $paid_order->get_id() + 2000000, '100' );
promoguard_credit_assert( $capped_restore->created && '40' === $capped_restore->amount, 'Refund restoration was not capped to consumed credit.' );
promoguard_credit_assert( '100' === $credits->balance( $user_id, 'USD' ), 'Refunds restored more or less than the consumed USD credit.' );

$cancelled_order = promoguard_credit_order( $user_id, 'USD' );
$released        = $redemptions->reserve( $user_id, $cancelled_order->get_id(), '30', 'USD' );
promoguard_credit_assert( null !== $released, 'Cancellation fixture could not reserve credit.' );
$released = $redemptions->release( $cancelled_order->get_id() );
promoguard_credit_assert( null !== $released && 'released' === $released->status, 'Cancelled reservation was not released.' );
$released_again = $redemptions->release( $cancelled_order->get_id() );
promoguard_credit_assert( null !== $released_again && 'released' === $released_again->status, 'Repeated release was not idempotent.' );
promoguard_credit_assert( '100' === $redemptions->available_balance( $user_id, 'USD' ), 'Released credit did not become available.' );

$mismatch_order = promoguard_credit_order( $user_id, 'GBP' );
promoguard_credit_assert(
	null === $redemptions->reserve( $user_id, $mismatch_order->get_id(), '10', 'GBP' ),
	'Currency mismatch unexpectedly reserved another account currency.'
);

$balances = $accounts->balances( $user_id );
promoguard_credit_assert( 2 === count( $balances ), 'My Account balance read did not preserve the two currencies.' );
$ledger = $accounts->ledger( $user_id, 1 );
promoguard_credit_assert( count( $ledger['entries'] ) >= 5, 'My Account ledger omitted credit lifecycle entries.' );

WP_CLI::success( 'PromoGuard store-credit smoke test passed.' );
