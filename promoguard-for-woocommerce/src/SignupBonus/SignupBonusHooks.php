<?php
/**
 * WordPress and Dokan signup-bonus event adapter.
 *
 * @package PromoGuard
 */

namespace PromoGuard\SignupBonus;

use Closure;
use PromoGuard\Credit\CreditRepository;
use Throwable;

/** Registers customer registration and Dokan vendor lifecycle hooks. */
final class SignupBonusHooks {
	/**
	 * Configure event orchestration and environment adapters.
	 *
	 * @param SignupBonusService $service       Award application service.
	 * @param Closure            $currency      Current store-currency provider.
	 * @param Closure            $vendor_lookup Dokan vendor classifier.
	 */
	public function __construct(
		private readonly SignupBonusService $service,
		private readonly Closure $currency,
		private readonly Closure $vendor_lookup
	) {}

	/** Build hooks for the active WordPress and WooCommerce site. */
	public static function from_wordpress(): self {
		return new self(
			new SignupBonusService( new WordPressSignupBonusRuleStore(), CreditRepository::from_wordpress() ),
			static fn (): string => get_woocommerce_currency(),
			static function ( int $user_id ): bool {
				if ( function_exists( 'dokan_is_user_seller' ) && dokan_is_user_seller( $user_id ) ) {
					return true;
				}

				$user = get_userdata( $user_id );
				// phpcs:ignore WordPress.WP.Capabilities.Unknown -- dokandar is registered by Dokan when active.
				return false !== $user && ( in_array( 'seller', $user->roles, true ) || user_can( $user, 'dokandar' ) );
			}
		);
	}

	/** Register late customer classification and Dokan-specific lifecycle events. */
	public function register(): void {
		add_action( 'woocommerce_created_customer', array( $this, 'customer_registered' ), 100 );
		add_action( 'dokan_new_seller_created', array( $this, 'vendor_registered' ), 100 );
		add_action( 'dokan_vendor_enabled', array( $this, 'vendor_approved' ), 100 );
	}

	/**
	 * Award a non-vendor customer after WooCommerce registration completes.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public function customer_registered( int $user_id ): void {
		if ( ( $this->vendor_lookup )( $user_id ) ) {
			return;
		}

		$this->award_safely( $user_id, SignupBonusRules::AUDIENCE_CUSTOMER, SignupBonusRules::EVENT_REGISTRATION );
	}

	/**
	 * Award a vendor immediately only when administrators select registration.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public function vendor_registered( int $user_id ): void {
		$this->award_safely( $user_id, SignupBonusRules::AUDIENCE_VENDOR, SignupBonusRules::EVENT_REGISTRATION );
	}

	/**
	 * Award a vendor when Dokan reports that selling was enabled.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public function vendor_approved( int $user_id ): void {
		$this->award_safely( $user_id, SignupBonusRules::AUDIENCE_VENDOR, SignupBonusRules::EVENT_APPROVAL );
	}

	/**
	 * Run one hook without allowing a credit failure to break account lifecycle.
	 *
	 * @param int    $user_id  WordPress user ID.
	 * @param string $audience Signup audience.
	 * @param string $event    Lifecycle event.
	 */
	private function award_safely( int $user_id, string $audience, string $event ): void {
		try {
			$this->service->award( $user_id, $audience, $event, ( $this->currency )() );
		} catch ( Throwable $exception ) {
			do_action( 'promoguard_signup_bonus_error', $exception, $user_id, $audience, $event );
		}
	}
}
