<?php
/**
 * Standalone signup-bonus campaign rules.
 *
 * @package PromoGuard
 */

namespace PromoGuard\SignupBonus;

use InvalidArgumentException;
use PromoGuard\Credit\CreditAmount;

/** Validates customer and vendor award timing without coupling to coupon campaigns. */
final class SignupBonusRules {
	public const AUDIENCE_CUSTOMER  = 'customer';
	public const AUDIENCE_VENDOR    = 'vendor';
	public const EVENT_DISABLED     = 'disabled';
	public const EVENT_REGISTRATION = 'registration';
	public const EVENT_APPROVAL     = 'approval';

	private const DEFAULT_AMOUNT = '10';

	/**
	 * Configure validated signup campaign rules.
	 *
	 * @param string $customer_event  Customer award event.
	 * @param string $customer_amount Customer credit amount.
	 * @param string $vendor_event    Vendor award event.
	 * @param string $vendor_amount   Vendor credit amount.
	 */
	private function __construct(
		public readonly string $customer_event,
		public readonly string $customer_amount,
		public readonly string $vendor_event,
		public readonly string $vendor_amount
	) {}

	/** Return safe defaults for a new installation. */
	public static function defaults(): self {
		return new self(
			self::EVENT_REGISTRATION,
			self::DEFAULT_AMOUNT,
			self::EVENT_APPROVAL,
			self::DEFAULT_AMOUNT
		);
	}

	/**
	 * Build rules from one closed administrator-owned settings object.
	 *
	 * @param array<string,mixed> $settings Stored settings.
	 * @throws InvalidArgumentException When a key, event, or amount is invalid.
	 */
	public static function from_array( array $settings ): self {
		$defaults = self::defaults()->to_array();
		if ( array() !== array_diff_key( $settings, $defaults ) ) {
			throw new InvalidArgumentException( 'Signup-bonus settings contain unsupported fields.' );
		}

		$settings       = array_replace( $defaults, $settings );
		$customer_event = self::event( $settings['customer_event'], array( self::EVENT_REGISTRATION, self::EVENT_DISABLED ) );
		$vendor_event   = self::event( $settings['vendor_event'], array( self::EVENT_REGISTRATION, self::EVENT_APPROVAL, self::EVENT_DISABLED ) );

		return new self(
			$customer_event,
			self::amount( $settings['customer_amount'] ),
			$vendor_event,
			self::amount( $settings['vendor_amount'] )
		);
	}

	/**
	 * Return the configured amount only when an audience's event matches.
	 *
	 * @param string $audience Customer or vendor audience.
	 * @param string $event    Registration or approval event.
	 */
	public function amount_for( string $audience, string $event ): ?string {
		if ( self::AUDIENCE_CUSTOMER === $audience && $this->customer_event === $event ) {
			return $this->customer_amount;
		}
		if ( self::AUDIENCE_VENDOR === $audience && $this->vendor_event === $event ) {
			return $this->vendor_amount;
		}

		return null;
	}

	/**
	 * Return the transport-safe rule object.
	 *
	 * @return array{customer_event:string,customer_amount:string,vendor_event:string,vendor_amount:string}
	 */
	public function to_array(): array {
		return array(
			'customer_event'  => $this->customer_event,
			'customer_amount' => $this->customer_amount,
			'vendor_event'    => $this->vendor_event,
			'vendor_amount'   => $this->vendor_amount,
		);
	}

	/**
	 * Validate an event against its audience-specific allowlist.
	 *
	 * @param mixed    $event   Candidate event.
	 * @param string[] $allowed Supported events.
	 * @throws InvalidArgumentException When the event is unsupported.
	 */
	private static function event( mixed $event, array $allowed ): string {
		if ( ! is_string( $event ) || ! in_array( $event, $allowed, true ) ) {
			throw new InvalidArgumentException( 'Signup-bonus award event is invalid.' );
		}

		return $event;
	}

	/**
	 * Validate one configurable non-negative amount.
	 *
	 * @param mixed $amount Candidate amount.
	 * @throws InvalidArgumentException When the amount is not a decimal string.
	 */
	private static function amount( mixed $amount ): string {
		if ( ! is_string( $amount ) ) {
			throw new InvalidArgumentException( 'Signup-bonus amount must be a decimal string.' );
		}

		return CreditAmount::normalize( $amount );
	}
}
