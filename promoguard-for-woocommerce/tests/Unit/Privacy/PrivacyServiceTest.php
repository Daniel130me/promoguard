<?php
/**
 * Privacy service tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use PromoGuard\Customer\EmailNormalizer;
use PromoGuard\Customer\IdentifierHasher;
use PromoGuard\Privacy\PrivacyService;
use PromoGuard\Privacy\PrivacyStore;
use PromoGuard\Tests\Support\InMemoryPrivacyStore;

/** Covers bounded export formatting and identifier erasure. */
final class PrivacyServiceTest extends TestCase {
	private const KEY = 'abababababababababababababababababababababababababababababababab';

	/** Verify exports are resolved by hash and exclude internal identifier values. */
	public function test_it_exports_bounded_human_readable_activity(): void {
		$store   = new InMemoryPrivacyStore();
		$service = $this->service( $store );

		$result = $service->export_for_identity( ' Customer@Example.COM ', 1, 41 );

		self::assertSame( 41, $store->requested_user_id );
		self::assertSame(
			( new IdentifierHasher( self::KEY ) )->hash( 'email', 'customer@example.com' ),
			$store->requested_email_hash
		);
		self::assertSame( 20, $store->requested_limit );
		self::assertFalse( $result['done'] );
		self::assertCount( 3, $result['data'] );
		self::assertSame( 'promoguard-state-1', $result['data'][0]['item_id'] );
		self::assertSame( 'Spring promotion', $result['data'][0]['data'][0]['value'] );
		$exported_values = array();
		foreach ( $result['data'] as $item ) {
			$exported_values = array_merge( $exported_values, array_column( $item['data'], 'value' ) );
		}
		self::assertNotContains( $store->requested_email_hash, $exported_values, true );
	}

	/** Verify invalid addresses finish without querying customer data. */
	public function test_it_ignores_an_invalid_export_email(): void {
		$store  = new InMemoryPrivacyStore();
		$result = $this->service( $store )->export_for_identity( 'not-an-email', 1, null );

		self::assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			$result
		);
		self::assertNull( $store->requested_email_hash );
	}

	/** Verify erasure unlinks identity while explicitly retaining anonymous facts. */
	public function test_it_anonymizes_identifiers_and_reports_retained_accounting_records(): void {
		$store  = new InMemoryPrivacyStore();
		$result = $this->service( $store )->erase_for_identity( 'customer@example.com', 41 );

		self::assertSame( 7, $store->anonymized_customer_id );
		self::assertTrue( $result['items_removed'] );
		self::assertTrue( $result['items_retained'] );
		self::assertTrue( $result['done'] );
		self::assertCount( 1, $result['messages'] );
	}

	/**
	 * Build the service with deterministic hashing.
	 *
	 * @param PrivacyStore $store Privacy fixture store.
	 */
	private function service( PrivacyStore $store ): PrivacyService {
		return new PrivacyService(
			$store,
			new EmailNormalizer(),
			new IdentifierHasher( self::KEY )
		);
	}
}
