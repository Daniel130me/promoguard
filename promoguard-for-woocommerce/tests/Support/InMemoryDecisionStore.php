<?php
/**
 * In-memory decision store.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Support;

use PromoGuard\Decision\DecisionStore;
use PromoGuard\Decision\DenialRecord;

/** Captures denial records for application-level tests. */
final class InMemoryDecisionStore implements DecisionStore {
	/**
	 * Persisted denial records.
	 *
	 * @var DenialRecord[]
	 */
	public array $records = array();

	/**
	 * Capture one denial record.
	 *
	 * @param DenialRecord $denial Validated denial.
	 */
	public function record( DenialRecord $denial ): void {
		$this->records[] = $denial;
	}
}
