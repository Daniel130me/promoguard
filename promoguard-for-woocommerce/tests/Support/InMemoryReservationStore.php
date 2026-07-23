<?php
/**
 * In-memory reservation store test double.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Support;

use PromoGuard\Reservation\ReservationRequest;
use PromoGuard\Reservation\ReservationResult;
use PromoGuard\Reservation\ReservationStore;
use RuntimeException;

/** Records reservation attempts and yields configured outcomes. */
final class InMemoryReservationStore implements ReservationStore {
	/**
	 * Ordered persistence outcomes.
	 *
	 * @var array<int,ReservationResult|RuntimeException>
	 */
	public array $outcomes;

	/**
	 * Number of attempted reservations.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * Configure ordered persistence outcomes.
	 *
	 * @param array<int,ReservationResult|RuntimeException> $outcomes Results or failures.
	 */
	public function __construct( array $outcomes ) {
		$this->outcomes = array_values( $outcomes );
	}

	/**
	 * Return or throw the next configured outcome.
	 *
	 * @param ReservationRequest $request Reservation facts.
	 * @throws RuntimeException When the configured outcome is a failure or absent.
	 */
	public function reserve( ReservationRequest $request ): ReservationResult {
		unset( $request );
		$outcome = $this->outcomes[ $this->calls ] ?? null;
		++$this->calls;

		if ( $outcome instanceof RuntimeException ) {
			throw $outcome;
		}

		if ( ! $outcome instanceof ReservationResult ) {
			throw new RuntimeException( 'No reservation test outcome was configured.' );
		}

		return $outcome;
	}
}
