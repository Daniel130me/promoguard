<?php
/**
 * Administration tool presenter tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Api;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use PromoGuard\Api\AdministrationToolPresenter;
use PromoGuard\Indexing\IndexingJob;
use PromoGuard\Indexing\IndexingStatus;

/** Covers stable settings and historical-indexing response shapes. */
final class AdministrationToolPresenterTest extends TestCase {
	/** Storage health dates are normalized while unknown health remains nullable. */
	public function test_settings_present_read_only_health(): void {
		$result = ( new AdministrationToolPresenter() )->settings(
			array(
				'delete_data_on_uninstall'      => false,
				'storage_engine_supported'      => null,
				'storage_engine_checked_at_gmt' => '2026-07-24 12:00:00',
			)
		);

		self::assertFalse( $result['delete_data_on_uninstall'] );
		self::assertNull( $result['storage_engine_supported'] );
		self::assertSame( '2026-07-24T12:00:00Z', $result['storage_engine_checked_at_gmt'] );
	}

	/** Indexing resources expose bounded progress and ISO GMT dates. */
	public function test_indexing_presents_progress(): void {
		$gmt = new DateTimeZone( 'UTC' );
		$job = new IndexingJob(
			'123e4567-e89b-42d3-a456-426614174000',
			IndexingStatus::RUNNING,
			array( 42 ),
			10,
			2,
			1,
			1,
			0,
			0,
			array(),
			new DateTimeImmutable( '2026-07-24 10:00:00', $gmt ),
			new DateTimeImmutable( '2026-07-24 10:05:00', $gmt )
		);

		$result = ( new AdministrationToolPresenter() )->indexing( $job );

		self::assertIsArray( $result );
		self::assertTrue( $result['targeted'] );
		self::assertSame( 1, $result['processed'] );
		self::assertSame( '2026-07-24T10:05:00Z', $result['updated_at_gmt'] );
	}

	/** No job is represented explicitly rather than as invented zero progress. */
	public function test_indexing_preserves_missing_job(): void {
		self::assertNull( ( new AdministrationToolPresenter() )->indexing( null ) );
	}
}
