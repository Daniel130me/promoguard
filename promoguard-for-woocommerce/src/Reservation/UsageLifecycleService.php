<?php
/**
 * Usage lifecycle application service.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Reservation;

/** Maps validated campaign rules and order statuses to atomic usage transitions. */
final class UsageLifecycleService {
	public const CONSUMED  = 'consumed';
	public const RELEASED  = 'released';
	public const UNCHANGED = 'unchanged';

	/**
	 * Configure lifecycle persistence.
	 *
	 * @param UsageLifecycleStore $store Atomic usage lifecycle persistence.
	 */
	public function __construct( private readonly UsageLifecycleStore $store ) {}

	/**
	 * Apply the configured lifecycle policy for one order/campaign usage.
	 *
	 * @param UsageTransition     $transition Validated order transition.
	 * @param array<string,mixed> $usage_rules Validated campaign usage rules.
	 */
	public function transition( UsageTransition $transition, array $usage_rules ): string {
		$counted_statuses = $usage_rules['counted_statuses'];

		if ( is_array( $counted_statuses ) && in_array( $transition->order_status, $counted_statuses, true ) ) {
			return $this->store->consume( $transition ) ? self::CONSUMED : self::UNCHANGED;
		}

		$release = (
			'failed' === $transition->order_status
			&& true === $usage_rules['release_on_failure']
		) || (
			'cancelled' === $transition->order_status
			&& true === $usage_rules['release_on_cancellation']
		);

		if ( $release ) {
			return $this->store->release( $transition ) ? self::RELEASED : self::UNCHANGED;
		}

		return self::UNCHANGED;
	}
}
