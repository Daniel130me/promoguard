<?php
/**
 * User-first customer identity resolution.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Customer;

/** Resolves authenticated users before considering a normalized guest email. */
final class IdentityResolver {
	/**
	 * Initialize the resolver.
	 *
	 * @param CustomerIdentityStore $store      Transactional identity store.
	 * @param EmailNormalizer       $normalizer Conservative email normalizer.
	 * @param IdentifierHasher      $hasher     Non-reversible identifier hasher.
	 */
	public function __construct(
		private readonly CustomerIdentityStore $store,
		private readonly EmailNormalizer $normalizer,
		private readonly IdentifierHasher $hasher
	) {}

	/**
	 * Resolve an authenticated user or guest email.
	 *
	 * @param int|null    $wp_user_id Authoritative WordPress user ID when logged in.
	 * @param string|null $email      Optional billing email.
	 */
	public function resolve( ?int $wp_user_id, ?string $email ): IdentityResolution {
		$normalized_email = null === $email ? null : $this->normalizer->normalize( $email );

		if ( null !== $wp_user_id && $wp_user_id > 0 ) {
			return $this->resolve_authenticated( $wp_user_id, $normalized_email );
		}

		if ( null === $normalized_email ) {
			return new IdentityResolution( null, IdentityResolution::OUTCOME_MISSING );
		}

		return $this->resolve_guest( $normalized_email );
	}

	/**
	 * Resolve an authoritative user and safely incorporate an optional email.
	 *
	 * @param int         $wp_user_id       Authoritative WordPress user ID.
	 * @param string|null $normalized_email Normalized email when available.
	 */
	private function resolve_authenticated( int $wp_user_id, ?string $normalized_email ): IdentityResolution {
		return $this->store->transaction(
			function () use ( $wp_user_id, $normalized_email ): IdentityResolution {
				$customer = $this->store->find_by_user_id( $wp_user_id );
				$outcome  = IdentityResolution::OUTCOME_MATCHED;

				if ( null === $customer ) {
					$customer = $this->store->create( $wp_user_id );
					$outcome  = IdentityResolution::OUTCOME_CREATED;
				}

				$this->ensure_user_identifier( $customer, $wp_user_id );

				if ( null === $normalized_email ) {
					return new IdentityResolution( $customer, $outcome );
				}

				$email_hash  = $this->hasher->hash( CustomerIdentifier::TYPE_EMAIL, $normalized_email );
				$email_owner = $this->store->find_by_identifier( CustomerIdentifier::TYPE_EMAIL, $email_hash );

				if ( null === $email_owner ) {
					$this->store->attach_identifier( $customer->id, CustomerIdentifier::TYPE_EMAIL, $email_hash, true );
					return new IdentityResolution( $customer, $outcome );
				}

				if ( $email_owner->id === $customer->id ) {
					$this->store->touch_identifier( $customer->id, CustomerIdentifier::TYPE_EMAIL, $email_hash );
					return new IdentityResolution( $customer, $outcome );
				}

				// Email alone can merge a guest, but must never merge two authenticated users.
				if ( ! $email_owner->is_guest() ) {
					return new IdentityResolution( $customer, IdentityResolution::OUTCOME_CONFLICT );
				}

				$this->store->merge_guest( $email_owner->id, $customer->id );
				return new IdentityResolution( $customer, IdentityResolution::OUTCOME_MERGED );
			}
		);
	}

	/**
	 * Resolve or create a guest by normalized email hash.
	 *
	 * @param string $normalized_email Normalized email.
	 */
	private function resolve_guest( string $normalized_email ): IdentityResolution {
		return $this->store->transaction(
			function () use ( $normalized_email ): IdentityResolution {
				$email_hash = $this->hasher->hash( CustomerIdentifier::TYPE_EMAIL, $normalized_email );
				$customer   = $this->store->find_by_identifier( CustomerIdentifier::TYPE_EMAIL, $email_hash );

				if ( null !== $customer ) {
					$this->store->touch_identifier( $customer->id, CustomerIdentifier::TYPE_EMAIL, $email_hash );
					return new IdentityResolution( $customer, IdentityResolution::OUTCOME_MATCHED );
				}

				$customer = $this->store->create( null );
				$this->store->attach_identifier( $customer->id, CustomerIdentifier::TYPE_EMAIL, $email_hash, true );

				return new IdentityResolution( $customer, IdentityResolution::OUTCOME_CREATED );
			}
		);
	}

	/**
	 * Ensure every authenticated customer has the domain-separated user identifier.
	 *
	 * @param Customer $customer   Authenticated customer.
	 * @param int      $wp_user_id WordPress user ID.
	 */
	private function ensure_user_identifier( Customer $customer, int $wp_user_id ): void {
		$user_hash = $this->hasher->hash( CustomerIdentifier::TYPE_USER, (string) $wp_user_id );
		$owner     = $this->store->find_by_identifier( CustomerIdentifier::TYPE_USER, $user_hash );

		if ( null === $owner ) {
			$this->store->attach_identifier( $customer->id, CustomerIdentifier::TYPE_USER, $user_hash, true );
		} elseif ( $owner->id === $customer->id ) {
			$this->store->touch_identifier( $customer->id, CustomerIdentifier::TYPE_USER, $user_hash );
		}
	}
}
