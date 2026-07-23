<?php
/**
 * Reconciliation batch tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Reconciliation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PromoGuard\Reconciliation\ReconciliationBatch;

/** Verifies reconciliation progress invariants. */
final class ReconciliationBatchTest extends TestCase {
	/**
	 * Invalid progress is rejected.
	 *
	 * @param int      $processed   State rows inspected.
	 * @param int      $changed     State rows corrected.
	 * @param int|null $next_cursor Next state cursor.
	 */
	#[DataProvider( 'invalid_progress' )]
	public function test_invalid_progress_is_rejected( int $processed, int $changed, ?int $next_cursor ): void {
		$this->expectException( InvalidArgumentException::class );
		new ReconciliationBatch( $processed, $changed, $next_cursor );
	}

	/**
	 * Provide inconsistent progress values.
	 *
	 * @return array<string,array{int,int,int|null}>
	 */
	public static function invalid_progress(): array {
		return array(
			'negative processed' => array( -1, 0, null ),
			'negative changed'   => array( 1, -1, null ),
			'too many changes'   => array( 1, 2, null ),
			'invalid cursor'     => array( 1, 1, 0 ),
		);
	}
}
