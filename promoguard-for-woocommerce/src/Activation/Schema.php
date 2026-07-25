<?php
/**
 * PromoGuard database schema definitions.
 *
 * @package PromoGuard
 */

namespace PromoGuard\Activation;

use PromoGuard\Support\TableNames;

/**
 * Produces dbDelta-compatible SQL for plugin-owned tables.
 */
final class Schema {
	/**
	 * Build the complete schema without executing database queries.
	 *
	 * @param TableNames $tables          Site-specific table names.
	 * @param string     $charset_collate WordPress charset/collation clause.
	 * @return string[]
	 */
	public function statements( TableNames $tables, string $charset_collate ): array {
		$campaigns                = $tables->campaigns();
		$campaign_promotions      = $tables->campaign_promotions();
		$customers                = $tables->customers();
		$customer_identifiers     = $tables->customer_identifiers();
		$customer_campaign_state  = $tables->customer_campaign_state();
		$usages                   = $tables->usages();
		$decisions                = $tables->decisions();
		$engine_and_character_set = 'ENGINE=InnoDB ' . trim( $charset_collate );

		return array(
			"CREATE TABLE {$campaigns} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				uuid char(36) NOT NULL,
				name varchar(191) NOT NULL,
				slug varchar(191) NOT NULL,
				description longtext NULL,
				goal varchar(191) NULL,
				status varchar(20) NOT NULL DEFAULT 'draft',
				priority int(11) NOT NULL DEFAULT 0,
				starts_at_gmt datetime NULL,
				ends_at_gmt datetime NULL,
				usage_rules longtext NOT NULL,
				conflict_rules longtext NOT NULL,
				settings longtext NOT NULL,
				created_by bigint(20) unsigned NULL,
				created_at_gmt datetime NOT NULL,
				updated_at_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				UNIQUE KEY slug (slug),
				KEY status_schedule (status, starts_at_gmt, ends_at_gmt),
				KEY priority (priority)
			) {$engine_and_character_set};",
			"CREATE TABLE {$campaign_promotions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				uuid char(36) NOT NULL,
				campaign_id bigint(20) unsigned NOT NULL,
				source varchar(50) NOT NULL,
				source_type varchar(50) NOT NULL,
				external_id varchar(191) NOT NULL,
				external_code varchar(255) NULL,
				channel varchar(50) NULL,
				label varchar(191) NULL,
				sort_order int(11) NOT NULL DEFAULT 0,
				settings longtext NOT NULL,
				created_at_gmt datetime NOT NULL,
				updated_at_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				UNIQUE KEY source_assignment (source, source_type, external_id),
				KEY campaign_id (campaign_id),
				KEY external_code (external_code)
			) {$engine_and_character_set};",
			"CREATE TABLE {$customers} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				wp_user_id bigint(20) unsigned NULL,
				merged_into_customer_id bigint(20) unsigned NULL,
				created_at_gmt datetime NOT NULL,
				updated_at_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY wp_user_id (wp_user_id),
				KEY merged_into_customer_id (merged_into_customer_id)
			) {$engine_and_character_set};",
			"CREATE TABLE {$customer_identifiers} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				customer_id bigint(20) unsigned NOT NULL,
				identifier_type varchar(20) NOT NULL,
				identifier_hash char(64) NOT NULL,
				is_primary tinyint(1) NOT NULL DEFAULT 0,
				first_seen_at_gmt datetime NOT NULL,
				last_seen_at_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY type_hash (identifier_type, identifier_hash),
				KEY customer_type (customer_id, identifier_type)
			) {$engine_and_character_set};",
			"CREATE TABLE {$customer_campaign_state} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				campaign_id bigint(20) unsigned NOT NULL,
				customer_id bigint(20) unsigned NOT NULL,
				consumed_count int(10) unsigned NOT NULL DEFAULT 0,
				reserved_count int(10) unsigned NOT NULL DEFAULT 0,
				total_discount decimal(26,8) NOT NULL DEFAULT 0,
				first_consumed_at_gmt datetime NULL,
				last_consumed_at_gmt datetime NULL,
				last_order_id bigint(20) unsigned NULL,
				lock_version bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at_gmt datetime NOT NULL,
				updated_at_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY campaign_customer (campaign_id, customer_id),
				KEY customer_id (customer_id),
				KEY campaign_last_consumed (campaign_id, last_consumed_at_gmt)
			) {$engine_and_character_set};",
			"CREATE TABLE {$usages} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				uuid char(36) NOT NULL,
				campaign_id bigint(20) unsigned NOT NULL,
				promotion_id bigint(20) unsigned NULL,
				customer_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				order_item_id bigint(20) unsigned NULL,
				coupon_id bigint(20) unsigned NULL,
				coupon_code varchar(255) NULL,
				status varchar(20) NOT NULL,
				order_status varchar(20) NULL,
				discount_amount decimal(26,8) NOT NULL DEFAULT 0,
				currency char(3) NOT NULL,
				reservation_key char(64) NOT NULL,
				reserved_until_gmt datetime NULL,
				reserved_at_gmt datetime NULL,
				consumed_at_gmt datetime NULL,
				released_at_gmt datetime NULL,
				restored_at_gmt datetime NULL,
				created_at_gmt datetime NOT NULL,
				updated_at_gmt datetime NOT NULL,
				metadata longtext NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				UNIQUE KEY reservation_key (reservation_key),
				UNIQUE KEY order_campaign (order_id, campaign_id),
				KEY campaign_customer_status (campaign_id, customer_id, status),
				KEY customer_status (customer_id, status),
				KEY status_expiry (status, reserved_until_gmt),
				KEY campaign_consumed (campaign_id, consumed_at_gmt),
				KEY consumed_at_gmt (consumed_at_gmt)
			) {$engine_and_character_set};",
			"CREATE TABLE {$decisions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				request_id varchar(64) NOT NULL,
				campaign_id bigint(20) unsigned NULL,
				promotion_id bigint(20) unsigned NULL,
				customer_id bigint(20) unsigned NULL,
				order_id bigint(20) unsigned NULL,
				coupon_id bigint(20) unsigned NULL,
				coupon_code varchar(255) NULL,
				context varchar(32) NOT NULL,
				decision varchar(20) NOT NULL,
				reason varchar(64) NOT NULL,
				customer_message text NULL,
				admin_explanation text NULL,
				metadata longtext NOT NULL,
				created_at_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY request_id (request_id),
				KEY campaign_created (campaign_id, created_at_gmt),
				KEY created_at_gmt (created_at_gmt),
				KEY customer_created (customer_id, created_at_gmt),
				KEY decision_reason (decision, reason),
				KEY order_id (order_id)
			) {$engine_and_character_set};",
		);
	}
}
