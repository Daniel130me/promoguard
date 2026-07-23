<?php
/**
 * Eligibility application service.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Application;

use DateTimeImmutable;
use PromoGuard\Campaign\Campaign;
use PromoGuard\Customer\CustomerRepository;
use PromoGuard\Customer\EmailNormalizer;
use PromoGuard\Customer\IdentifierHasher;
use PromoGuard\Customer\IdentityResolver;
use PromoGuard\Eligibility\CustomerCampaignStateRepository;
use PromoGuard\Eligibility\EligibilityContext;
use PromoGuard\Eligibility\EligibilityEngine;
use PromoGuard\Support\Options;
use RuntimeException;

/** Coordinates safe identity resolution with the shared campaign policy engine. */
final class EligibilityService implements EligibilityEvaluator {
	/**
	 * Configure identity and policy dependencies.
	 *
	 * @param IdentityResolver  $identities Customer identity resolver.
	 * @param EligibilityEngine $engine     Deterministic policy engine.
	 */
	public function __construct(
		private readonly IdentityResolver $identities,
		private readonly EligibilityEngine $engine
	) {}

	/**
	 * Build the production service for the active WordPress site.
	 *
	 * @throws RuntimeException When the persistent HMAC key is unavailable.
	 */
	public static function from_wordpress(): self {
		$hash_key = get_option( Options::HASH_KEY, false );
		if ( ! is_string( $hash_key ) || '' === $hash_key ) {
			throw new RuntimeException( 'PromoGuard customer identity key is unavailable.' );
		}

		$identities = new IdentityResolver(
			CustomerRepository::from_wordpress(),
			new EmailNormalizer(),
			new IdentifierHasher( $hash_key )
		);
		$engine     = new EligibilityEngine( CustomerCampaignStateRepository::from_wordpress() );

		return new self( $identities, $engine );
	}

	/**
	 * Resolve customer identity and evaluate the campaign once.
	 *
	 * @param Campaign          $campaign                      Persisted campaign.
	 * @param int|null          $wp_user_id                    Authenticated WordPress user ID.
	 * @param string|null       $billing_email                 Raw billing email, never persisted.
	 * @param int               $applied_campaign_coupon_count Existing campaign coupon count.
	 * @param DateTimeImmutable $now_gmt                       Current GMT time.
	 * @param bool              $allow_provisional_identity    Whether early validation may defer identity.
	 */
	public function evaluate(
		Campaign $campaign,
		?int $wp_user_id,
		?string $billing_email,
		int $applied_campaign_coupon_count,
		DateTimeImmutable $now_gmt,
		bool $allow_provisional_identity = false
	): EligibilityResult {
		$identity = $this->identities->resolve( $wp_user_id, $billing_email );

		return new EligibilityResult(
			$identity,
			$this->engine->evaluate(
				new EligibilityContext(
					campaign: $campaign,
					identity: $identity,
					is_authenticated: null !== $wp_user_id && $wp_user_id > 0,
					applied_campaign_coupon_count: $applied_campaign_coupon_count,
					now_gmt: $now_gmt,
					allow_provisional_identity: $allow_provisional_identity
				)
			)
		);
	}
}
