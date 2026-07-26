<?php
/**
 * WooCommerce privacy retention scheduling.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Privacy;

use ActionScheduler;
use DateTimeImmutable;
use DateTimeZone;

/** Schedules and runs bounded decision-log retention. */
final class WooCommerceRetention {
	private const ACTION           = 'promoguard_cleanup_expired_decisions';
	private const GROUP            = 'promoguard';
	private const INTERVAL_SECONDS = DAY_IN_SECONDS;

	/**
	 * Configure retention cleanup.
	 *
	 * @param RetentionService $retention Retention application service.
	 */
	public function __construct( private readonly RetentionService $retention ) {}

	/** Build the production retention adapter for the active site. */
	public static function from_wordpress(): self {
		return new self(
			new RetentionService( new RetentionRepository( \PromoGuard\Support\TableNames::from_wordpress() ) )
		);
	}

	/** Register the worker and recurring-action safeguards. */
	public function register(): void {
		add_action( self::ACTION, array( $this, 'run' ) );
		add_action( 'action_scheduler_init', array( $this, 'ensure_scheduled' ) );
		add_action( 'action_scheduler_ensure_recurring_actions', array( $this, 'ensure_scheduled' ) );
	}

	/** Ensure one daily cleanup exists after Action Scheduler initializes. */
	public function ensure_scheduled(): void {
		if (
			! class_exists( ActionScheduler::class )
			|| ! ActionScheduler::is_initialized()
			|| ! function_exists( 'as_has_scheduled_action' )
			|| ! function_exists( 'as_schedule_recurring_action' )
		) {
			return;
		}

		if ( as_has_scheduled_action( self::ACTION, array(), self::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action(
			time() + self::INTERVAL_SECONDS,
			self::INTERVAL_SECONDS,
			self::ACTION,
			array(),
			self::GROUP,
			true
		);
	}

	/** Run one bounded cleanup batch. */
	public function run(): void {
		$this->retention->cleanup(
			new DateTimeImmutable( current_time( 'mysql', true ), new DateTimeZone( 'UTC' ) )
		);
	}
}
