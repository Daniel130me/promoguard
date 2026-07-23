<?php
/**
 * Scheduled usage reconciliation.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reconciliation;

use Action_Scheduler;
use DateTimeImmutable;
use DateTimeZone;
use PromoGuard\Support\TableNames;

/** Schedules and chains bounded usage-ledger reconciliation batches. */
final class WooCommerceReconciliation {
	private const ACTION           = 'promoguard_reconcile_usage_state';
	private const GROUP            = 'promoguard';
	private const INTERVAL_SECONDS = 604800;

	/**
	 * Configure the reconciliation application service.
	 *
	 * @param ReconciliationService $reconciliation Aggregate rebuild service.
	 */
	public function __construct( private readonly ReconciliationService $reconciliation ) {}

	/** Build the production reconciliation adapter for the active site. */
	public static function from_wordpress(): self {
		return new self(
			new ReconciliationService(
				new ReconciliationRepository( TableNames::from_wordpress() )
			)
		);
	}

	/** Register the worker and recurring-action safeguards. */
	public function register(): void {
		add_action( self::ACTION, array( $this, 'run' ), 10, 1 );
		add_action( 'action_scheduler_init', array( $this, 'ensure_scheduled' ) );
		add_action( 'action_scheduler_ensure_recurring_actions', array( $this, 'ensure_scheduled' ) );
	}

	/** Ensure one weekly root reconciliation action exists. */
	public function ensure_scheduled(): void {
		if (
			! class_exists( Action_Scheduler::class )
			|| ! Action_Scheduler::is_initialized()
			|| ! function_exists( 'as_has_scheduled_action' )
			|| ! function_exists( 'as_schedule_recurring_action' )
		) {
			return;
		}

		$args = array( 0 );
		if ( as_has_scheduled_action( self::ACTION, $args, self::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action(
			time() + self::INTERVAL_SECONDS,
			self::INTERVAL_SECONDS,
			self::ACTION,
			$args,
			self::GROUP,
			true
		);
	}

	/**
	 * Reconcile one page and enqueue the next cursor when necessary.
	 *
	 * @param int $after_id Last state ID processed.
	 */
	public function run( int $after_id = 0 ): void {
		$batch = $this->reconciliation->reconcile_batch(
			new DateTimeImmutable( current_time( 'mysql', true ), new DateTimeZone( 'UTC' ) ),
			$after_id
		);

		if ( null === $batch->next_cursor || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		as_enqueue_async_action(
			self::ACTION,
			array( $batch->next_cursor ),
			self::GROUP,
			true
		);
	}
}
