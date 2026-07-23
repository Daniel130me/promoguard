<?php
/**
 * Immutable eligibility decision.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Eligibility;

use InvalidArgumentException;

/** Carries one stable policy outcome for storefront and administrative consumers. */
final class EligibilityDecision {
	public const ALLOWED                         = 'allowed';
	public const PROVISIONAL_IDENTITY_REQUIRED   = 'provisional_identity_required';
	public const CAMPAIGN_DRAFT                  = 'campaign_draft';
	public const CAMPAIGN_PAUSED                 = 'campaign_paused';
	public const CAMPAIGN_ARCHIVED               = 'campaign_archived';
	public const CAMPAIGN_NOT_STARTED            = 'campaign_not_started';
	public const CAMPAIGN_EXPIRED                = 'campaign_expired';
	public const LOGIN_REQUIRED                  = 'login_required';
	public const CUSTOMER_IDENTITY_MISSING       = 'customer_identity_missing';
	public const CUSTOMER_LIMIT_REACHED          = 'customer_limit_reached';
	public const CAMPAIGN_COUPON_ALREADY_APPLIED = 'campaign_coupon_already_applied';
	public const INVALID_CONFIGURATION           = 'invalid_configuration';
	public const IDENTITY_CONFLICT               = 'identity_conflict';

	/**
	 * Create a validated decision.
	 *
	 * @param bool   $allowed             Whether the campaign policy permits use.
	 * @param bool   $provisional         Whether final identity is still required.
	 * @param string $reason              Stable reason code.
	 * @param string $customer_message    Safe storefront message.
	 * @param string $admin_explanation   Detailed administrative explanation.
	 * @throws InvalidArgumentException When decision flags or reason are inconsistent.
	 */
	public function __construct(
		public readonly bool $allowed,
		public readonly bool $provisional,
		public readonly string $reason,
		public readonly string $customer_message,
		public readonly string $admin_explanation
	) {
		if ( ! in_array( $reason, self::reasons(), true ) ) {
			throw new InvalidArgumentException( 'Eligibility reason is unsupported.' );
		}

		if ( $provisional && ( ! $allowed || self::PROVISIONAL_IDENTITY_REQUIRED !== $reason ) ) {
			throw new InvalidArgumentException( 'Only an allowed identity decision can be provisional.' );
		}

		if ( self::ALLOWED === $reason && ( ! $allowed || $provisional ) ) {
			throw new InvalidArgumentException( 'Allowed decisions require final approval flags.' );
		}

		if ( $allowed && ! $provisional && self::ALLOWED !== $reason ) {
			throw new InvalidArgumentException( 'Final approval requires the allowed reason.' );
		}
	}

	/**
	 * Return every policy reason supported by this version.
	 *
	 * @return string[]
	 */
	private static function reasons(): array {
		return array(
			self::ALLOWED,
			self::PROVISIONAL_IDENTITY_REQUIRED,
			self::CAMPAIGN_DRAFT,
			self::CAMPAIGN_PAUSED,
			self::CAMPAIGN_ARCHIVED,
			self::CAMPAIGN_NOT_STARTED,
			self::CAMPAIGN_EXPIRED,
			self::LOGIN_REQUIRED,
			self::CUSTOMER_IDENTITY_MISSING,
			self::CUSTOMER_LIMIT_REACHED,
			self::CAMPAIGN_COUPON_ALREADY_APPLIED,
			self::INVALID_CONFIGURATION,
			self::IDENTITY_CONFLICT,
		);
	}
}
