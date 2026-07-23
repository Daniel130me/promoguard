<?php
/**
 * WooCommerce reservation expiration scheduling.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

use Action_Scheduler;
use DateTimeImmutable;
use DateTimeZone;

/** Schedules and runs bounded expired reservation cleanup. */
final class WooCommerceUsageExpiration {
	private const ACTION           = 'promoguard_release_expired_reservations';
	private const GROUP            = 'promoguard';
	private const INTERVAL_SECONDS = 300;

	/**
	 * Configure the expiration application service.
	 *
	 * @param ExpirationService $expiration Expiration application service.
	 */
	public function __construct( private readonly ExpirationService $expiration ) {}

	/** Build the production expiration adapter for the active site. */
	public static function from_wordpress(): self {
		return new self(
			new ExpirationService( new UsageExpirationRepository( \PromoGuard\Support\TableNames::from_wordpress() ) )
		);
	}

	/** Register the worker and recurring-action safeguards. */
	public function register(): void {
		add_action( self::ACTION, array( $this, 'run' ) );
		add_action( 'action_scheduler_init', array( $this, 'ensure_scheduled' ) );
		add_action( 'action_scheduler_ensure_recurring_actions', array( $this, 'ensure_scheduled' ) );
	}

	/** Ensure one recurring cleanup exists after Action Scheduler initializes. */
	public function ensure_scheduled(): void {
		if (
			! class_exists( Action_Scheduler::class )
			|| ! Action_Scheduler::is_initialized()
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
		$this->expiration->release_batch(
			new DateTimeImmutable( current_time( 'mysql', true ), new DateTimeZone( 'UTC' ) )
		);
	}
}
