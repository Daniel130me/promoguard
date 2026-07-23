<?php
/**
 * In-memory usage lifecycle test double.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Support;

use PromoGuard\Reservation\UsageLifecycleStore;
use PromoGuard\Reservation\UsageTransition;

/** Records lifecycle operations without database persistence. */
final class InMemoryUsageLifecycleStore implements UsageLifecycleStore {
	/**
	 * Recorded operation names.
	 *
	 * @var string[]
	 */
	public array $operations = array();

	/**
	 * Whether the next persistence operation changes state.
	 *
	 * @var bool
	 */
	public bool $changes_state = true;

	/**
	 * Record consumption.
	 *
	 * @param UsageTransition $transition Validated transition facts.
	 */
	public function consume( UsageTransition $transition ): bool {
		unset( $transition );
		$this->operations[] = 'consume';
		return $this->changes_state;
	}

	/**
	 * Record release.
	 *
	 * @param UsageTransition $transition Validated transition facts.
	 */
	public function release( UsageTransition $transition ): bool {
		unset( $transition );
		$this->operations[] = 'release';
		return $this->changes_state;
	}
}
