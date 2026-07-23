<?php
/**
 * Deterministic campaign eligibility engine.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Eligibility;

use PromoGuard\Campaign\CampaignStatus;

/** Applies the same ordered campaign policy at every integration point. */
final class EligibilityEngine {
	/**
	 * Configure bounded customer state access.
	 *
	 * @param CustomerCampaignStateStore $states Customer campaign counters.
	 */
	public function __construct( private readonly CustomerCampaignStateStore $states ) {}

	/**
	 * Evaluate a campaign and stop at the first denial.
	 *
	 * @param EligibilityContext $context Validated request facts.
	 */
	public function evaluate( EligibilityContext $context ): EligibilityDecision {
		$status_decision = $this->evaluate_status( $context );
		if ( null !== $status_decision ) {
			return $status_decision;
		}

		$settings = $context->campaign->configuration->settings();
		if ( true === $settings['login_required'] && ! $context->is_authenticated ) {
			return $this->deny(
				EligibilityDecision::LOGIN_REQUIRED,
				'Please log in to use this promotion.',
				'The campaign requires an authenticated WordPress customer.'
			);
		}

		if ( $context->identity->has_conflict() ) {
			return $this->deny(
				EligibilityDecision::IDENTITY_CONFLICT,
				'This promotion cannot be verified for the supplied account.',
				'The supplied email belongs to a different authenticated customer.'
			);
		}

		$customer = $context->identity->customer;
		if ( null === $customer ) {
			if ( $context->allow_provisional_identity ) {
				return new EligibilityDecision(
					true,
					true,
					EligibilityDecision::PROVISIONAL_IDENTITY_REQUIRED,
					'Eligibility will be confirmed after your billing details are entered.',
					'This early validation context does not yet have a stable customer identity.'
				);
			}

			return $this->deny(
				EligibilityDecision::CUSTOMER_IDENTITY_MISSING,
				'Enter a valid billing email to use this promotion.',
				'No authenticated user or valid normalized guest email was available.'
			);
		}

		if ( null === $context->campaign->id ) {
			return $this->deny(
				EligibilityDecision::INVALID_CONFIGURATION,
				'This promotion is temporarily unavailable.',
				'Eligibility cannot evaluate an unpersisted campaign.'
			);
		}

		$state          = $this->states->find( $context->campaign->id, $customer->id );
		$usage_rules    = $context->campaign->configuration->usage_rules();
		$maximum_uses   = (int) $usage_rules['maximum_uses'];
		$committed_uses = null === $state ? 0 : $state->committed_count();

		if ( $committed_uses >= $maximum_uses ) {
			return $this->deny(
				EligibilityDecision::CUSTOMER_LIMIT_REACHED,
				'You have already used the maximum allowed for this promotion.',
				'Consumed and active reserved usage meets or exceeds the customer limit.'
			);
		}

		$conflict_rules = $context->campaign->configuration->conflict_rules();
		if ( $context->applied_campaign_coupon_count >= (int) $conflict_rules['maximum_campaign_coupons_per_order'] ) {
			return $this->deny(
				EligibilityDecision::CAMPAIGN_COUPON_ALREADY_APPLIED,
				'Only one promotion from this campaign can be used per order.',
				'The order already contains the campaign coupon limit.'
			);
		}

		return new EligibilityDecision(
			true,
			false,
			EligibilityDecision::ALLOWED,
			'',
			'All campaign eligibility policies passed.'
		);
	}

	/**
	 * Evaluate stored and schedule-derived campaign status.
	 *
	 * @param EligibilityContext $context Validated request facts.
	 */
	private function evaluate_status( EligibilityContext $context ): ?EligibilityDecision {
		$status = $context->campaign->effective_status( $context->now_gmt );
		$map    = array(
			CampaignStatus::DRAFT     => array( EligibilityDecision::CAMPAIGN_DRAFT, 'This promotion is not available.', 'The campaign is still a draft.' ),
			CampaignStatus::PAUSED    => array( EligibilityDecision::CAMPAIGN_PAUSED, 'This promotion is currently paused.', 'The campaign is paused.' ),
			CampaignStatus::ARCHIVED  => array( EligibilityDecision::CAMPAIGN_ARCHIVED, 'This promotion is no longer available.', 'The campaign is archived.' ),
			CampaignStatus::SCHEDULED => array( EligibilityDecision::CAMPAIGN_NOT_STARTED, 'This promotion has not started yet.', 'The campaign start boundary has not been reached.' ),
			CampaignStatus::COMPLETED => array( EligibilityDecision::CAMPAIGN_EXPIRED, 'This promotion has ended.', 'The campaign is completed or past its end boundary.' ),
		);

		if ( ! isset( $map[ $status ] ) ) {
			return null;
		}

		return $this->deny( $map[ $status ][0], $map[ $status ][1], $map[ $status ][2] );
	}

	/**
	 * Build one final denial.
	 *
	 * @param string $reason            Stable reason code.
	 * @param string $customer_message  Safe storefront message.
	 * @param string $admin_explanation Administrative explanation.
	 */
	private function deny( string $reason, string $customer_message, string $admin_explanation ): EligibilityDecision {
		return new EligibilityDecision( false, false, $reason, $customer_message, $admin_explanation );
	}
}
