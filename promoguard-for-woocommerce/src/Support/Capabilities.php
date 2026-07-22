<?php
/**
 * PromoGuard role capabilities.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Support;

/**
 * Installs and removes the plugin's explicit capability set.
 */
final class Capabilities {
	public const MANAGE_CAMPAIGNS = 'manage_promoguard';
	public const VIEW_REPORTS     = 'view_promoguard_reports';
	public const MANAGE_SETTINGS  = 'manage_promoguard_settings';
	public const RUN_TOOLS        = 'run_promoguard_tools';

	/**
	 * Capabilities granted to administrators.
	 *
	 * @return string[]
	 */
	public static function administrator(): array {
		return array(
			self::MANAGE_CAMPAIGNS,
			self::VIEW_REPORTS,
			self::MANAGE_SETTINGS,
			self::RUN_TOOLS,
		);
	}

	/**
	 * Shop Managers may operate campaigns but not privacy/debug settings.
	 *
	 * @return string[]
	 */
	public static function shop_manager(): array {
		return array(
			self::MANAGE_CAMPAIGNS,
			self::VIEW_REPORTS,
			self::RUN_TOOLS,
		);
	}

	/** Install capabilities idempotently on supported roles. */
	public static function install(): void {
		self::add_to_role( 'administrator', self::administrator() );
		self::add_to_role( 'shop_manager', self::shop_manager() );
	}

	/** Remove every PromoGuard capability during opted-in uninstall. */
	public static function remove(): void {
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );

			if ( null === $role ) {
				continue;
			}

			foreach ( self::administrator() as $capability ) {
				$role->remove_cap( $capability );
			}
		}
	}

	/**
	 * Grant a capability set to a WordPress role when it exists.
	 *
	 * @param string   $role_name    WordPress role slug.
	 * @param string[] $capabilities Capabilities to grant.
	 */
	private static function add_to_role( string $role_name, array $capabilities ): void {
		$role = get_role( $role_name );

		if ( null === $role ) {
			return;
		}

		foreach ( $capabilities as $capability ) {
			if ( ! $role->has_cap( $capability ) ) {
				$role->add_cap( $capability );
			}
		}
	}
}
