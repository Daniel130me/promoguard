<?php
/**
 * Historical indexing processor tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Indexing;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use PromoGuard\Customer\EmailNormalizer;
use PromoGuard\Customer\IdentifierHasher;
use PromoGuard\Customer\IdentityResolver;
use PromoGuard\Indexing\HistoricalCampaign;
use PromoGuard\Indexing\HistoricalCoupon;
use PromoGuard\Indexing\HistoricalIndexingProcessor;
use PromoGuard\Indexing\HistoricalOrder;
use PromoGuard\Indexing\HistoricalOrderPage;
use PromoGuard\Indexing\IndexingJob;
use PromoGuard\Indexing\IndexingStatus;
use PromoGuard\Tests\Support\InMemoryCustomerIdentityStore;
use PromoGuard\Tests\Support\InMemoryHistoricalImportStore;
use PromoGuard\Tests\Support\InMemoryHistoricalOrderSource;

/** Verifies bounded lookup, safe identity, and idempotent import behavior. */
final class HistoricalIndexingProcessorTest extends TestCase {
	/** Related counted orders import once while unrelated orders skip cheaply. */
	public function test_import_is_idempotent_and_uses_one_campaign_lookup_per_page(): void {
		$imports                         = new InMemoryHistoricalImportStore();
		$imports->campaigns['welcome10'] = new HistoricalCampaign( 4, 8, 12, array( 'completed' ), 'restore' );
		$page                            = new HistoricalOrderPage(
			array(
				$this->order( 30, 'welcome10', 'completed', 'buyer@example.com' ),
				$this->order( 31, 'other', 'completed', null ),
			),
			array(),
			false
		);
		$processor                       = new HistoricalIndexingProcessor(
			new InMemoryHistoricalOrderSource( $page ),
			$imports,
			$this->identities()
		);

		$first  = $processor->process( $this->job() );
		$second = $processor->process( $this->job() );

		self::assertSame( 1, $first->imported );
		self::assertSame( 1, $first->skipped );
		self::assertSame( 0, $second->imported );
		self::assertSame( 2, $second->skipped );
		self::assertCount( 1, $imports->usages );
		self::assertSame( 2, $imports->lookups );
	}

	/** Uncounted orders do not create identities or usage rows. */
	public function test_uncounted_order_skips_before_identity_resolution(): void {
		$identity_store            = new InMemoryCustomerIdentityStore();
		$imports                   = new InMemoryHistoricalImportStore();
		$imports->campaigns['new'] = new HistoricalCampaign( 2, 3, null, array( 'completed' ), 'keep_consumed' );
		$processor                 = new HistoricalIndexingProcessor(
			new InMemoryHistoricalOrderSource(
				new HistoricalOrderPage( array( $this->order( 40, 'new', 'pending', 'guest@example.com' ) ), array(), false )
			),
			$imports,
			$this->identities( $identity_store )
		);

		$result = $processor->process( $this->job() );

		self::assertSame( 1, $result->skipped );
		self::assertSame( array(), $identity_store->customers );
		self::assertSame( array(), $imports->usages );
	}

	/** Missing identity becomes a safe per-order error without aborting the page. */
	public function test_missing_identity_is_recorded_as_safe_error(): void {
		$imports                   = new InMemoryHistoricalImportStore();
		$imports->campaigns['new'] = new HistoricalCampaign( 2, 3, null, array( 'completed' ), 'manual_review' );
		$processor                 = new HistoricalIndexingProcessor(
			new InMemoryHistoricalOrderSource(
				new HistoricalOrderPage( array( $this->order( 50, 'new', 'completed', null ) ), array(), false )
			),
			$imports,
			$this->identities()
		);

		$result = $processor->process( $this->job() );

		self::assertSame( 1, $result->processed );
		self::assertCount( 1, $result->errors );
		self::assertSame( 'Customer identity could not be resolved safely.', $result->errors[0]['message'] );
	}

	/**
	 * Build one order fixture.
	 *
	 * @param int         $id     Order ID.
	 * @param string      $code   Coupon code.
	 * @param string      $status Order status.
	 * @param string|null $email  Billing email.
	 */
	private function order( int $id, string $code, string $status, ?string $email ): HistoricalOrder {
		return new HistoricalOrder(
			$id,
			null,
			$email,
			$status,
			'USD',
			$this->time(),
			array( new HistoricalCoupon( $id + 100, strtoupper( $code ), $code, '10.00000000' ) )
		);
	}

	/** Build one claimed job fixture. */
	private function job(): IndexingJob {
		return new IndexingJob(
			'123e4567-e89b-42d3-a456-426614174000',
			IndexingStatus::RUNNING,
			array(),
			50,
			1,
			0,
			0,
			0,
			0,
			array(),
			$this->time(),
			$this->time()
		);
	}

	/**
	 * Build the real identity resolver over in-memory persistence.
	 *
	 * @param InMemoryCustomerIdentityStore|null $store Optional identity fixture store.
	 */
	private function identities( ?InMemoryCustomerIdentityStore $store = null ): IdentityResolver {
		return new IdentityResolver(
			$store ?? new InMemoryCustomerIdentityStore(),
			new EmailNormalizer(),
			new IdentifierHasher( str_repeat( 'a', 64 ) )
		);
	}

	/** Build one deterministic GMT timestamp. */
	private function time(): DateTimeImmutable {
		return new DateTimeImmutable( '2026-07-23 10:00:00', new DateTimeZone( 'UTC' ) );
	}
}
