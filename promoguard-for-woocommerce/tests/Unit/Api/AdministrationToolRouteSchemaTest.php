<?php
/**
 * Administration tool route-schema tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use PromoGuard\Api\AdministrationToolRouteSchema;
use PromoGuard\Indexing\IndexingJob;

/** Covers closed settings and bounded historical-indexing inputs. */
final class AdministrationToolRouteSchemaTest extends TestCase {
	/** Settings accept only implemented explicit uninstall consent. */
	public function test_settings_require_explicit_cleanup_consent(): void {
		$schema = AdministrationToolRouteSchema::settings();

		self::assertSame( array( 'delete_data_on_uninstall' ), array_keys( $schema ) );
		self::assertTrue( $schema['delete_data_on_uninstall']['required'] );
		self::assertSame( 'boolean', $schema['delete_data_on_uninstall']['type'] );
	}

	/** Indexing inputs share domain caps and positive identifier constraints. */
	public function test_indexing_start_is_bounded_by_job_limits(): void {
		$schema = AdministrationToolRouteSchema::indexing_start();

		self::assertSame( IndexingJob::MAXIMUM_TARGETS, $schema['order_ids']['maxItems'] );
		self::assertSame( 1, $schema['order_ids']['items']['minimum'] );
		self::assertSame( IndexingJob::DEFAULT_BATCH_SIZE, $schema['batch_size']['default'] );
		self::assertSame( IndexingJob::MAXIMUM_BATCH_SIZE, $schema['batch_size']['maximum'] );
	}
}
