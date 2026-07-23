<?php
/**
 * User-first identity resolver tests.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Tests\Unit\Customer;

use PHPUnit\Framework\TestCase;
use PromoGuard\Customer\CustomerIdentityStore;
use PromoGuard\Customer\EmailNormalizer;
use PromoGuard\Customer\IdentifierHasher;
use PromoGuard\Customer\IdentityResolution;
use PromoGuard\Customer\IdentityResolver;
use PromoGuard\Tests\Support\InMemoryCustomerIdentityStore;

/** Covers user precedence, guest matching, safe merge, and conflict behavior. */
final class IdentityResolverTest extends TestCase {
	private const KEY = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

	/** A guest email creates and later matches the same internal customer. */
	public function test_guest_email_is_created_then_matched(): void {
		$store    = new InMemoryCustomerIdentityStore();
		$resolver = $this->resolver( $store );

		$created = $resolver->resolve( null, ' Guest@Example.com ' );
		$matched = $resolver->resolve( null, 'guest@example.com' );

		self::assertNotNull( $created->customer );
		self::assertNotNull( $matched->customer );
		self::assertSame( IdentityResolution::OUTCOME_CREATED, $created->outcome );
		self::assertSame( IdentityResolution::OUTCOME_MATCHED, $matched->outcome );
		self::assertSame( $created->customer->id, $matched->customer->id );
	}

	/** Logging in safely merges an email-owned guest into the authoritative user. */
	public function test_authenticated_user_merges_matching_guest(): void {
		$store    = new InMemoryCustomerIdentityStore();
		$resolver = $this->resolver( $store );
		$guest    = $resolver->resolve( null, 'person@example.com' );
		$user     = $resolver->resolve( 7, 'person@example.com' );

		self::assertNotNull( $guest->customer );
		self::assertNotNull( $user->customer );
		self::assertSame( IdentityResolution::OUTCOME_MERGED, $user->outcome );
		self::assertSame( 7, $user->customer->wp_user_id );
		self::assertArrayHasKey( $guest->customer->id, $store->merged );
		self::assertSame( $user->customer->id, $store->merged[ $guest->customer->id ] );
	}

	/** Email equality never merges two different authenticated WordPress users. */
	public function test_email_only_authenticated_conflict_never_merges_users(): void {
		$store    = new InMemoryCustomerIdentityStore();
		$resolver = $this->resolver( $store );
		$first    = $resolver->resolve( 7, 'shared@example.com' );
		$second   = $resolver->resolve( 8, 'shared@example.com' );

		self::assertNotNull( $first->customer );
		self::assertNotNull( $second->customer );
		self::assertSame( IdentityResolution::OUTCOME_CONFLICT, $second->outcome );
		self::assertSame( 8, $second->customer->wp_user_id );
		self::assertNotSame( $first->customer->id, $second->customer->id );
		self::assertSame( array(), $store->merged );
	}

	/** Missing guest email stays unresolved without creating database state. */
	public function test_guest_without_valid_email_is_missing(): void {
		$store      = new InMemoryCustomerIdentityStore();
		$resolution = $this->resolver( $store )->resolve( null, 'invalid' );

		self::assertSame( IdentityResolution::OUTCOME_MISSING, $resolution->outcome );
		self::assertSame( array(), $store->customers );
	}

	/**
	 * Build the resolver with deterministic test dependencies.
	 *
	 * @param CustomerIdentityStore $store In-memory identity store.
	 */
	private function resolver( CustomerIdentityStore $store ): IdentityResolver {
		return new IdentityResolver( $store, new EmailNormalizer(), new IdentifierHasher( self::KEY ) );
	}
}
