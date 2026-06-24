<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Telemetry;

use WP_Term;


/**
 * Data relating to product-level and storewide subscription plans.
 *
 * This incorporates data-points previously collected by the standalone APFS extension.
 */
class Subscription_Plans {
	/**
	 * Gets APFS settings.
	 *
	 * @return array
	 */
	public function get_settings(): array {
		$cart_level_schemes = get_option( 'wcsatt_subscribe_to_cart_schemes', array() );

		return array(
			'cart_plans'                    => ! empty( $cart_level_schemes ) && is_array( $cart_level_schemes ) ? count( $cart_level_schemes ) : 0,
			'add_products_to_subscriptions' => 'off' === get_option( 'wcsatt_add_product_to_subscription', 'off' ) ? 'off' : 'on',
			'add_cart_to_subscriptions'     => 'off' === get_option( 'wcsatt_add_cart_to_subscription', 'off' ) ? 'off' : 'on',
		);
	}

	/**
	 * Gets APFS product data.
	 *
	 * @return array
	 */
	public function get_product_data(): array {
		global $wpdb;

		// Count products actively using custom plans (override mode).
		// Checks _wcsatt_schemes_status = 'override' first; falls back to legacy detection
		// (_wcsatt_schemes exists without an explicit mode key or storewide mode).
		$products_with_plans = $wpdb->get_results(
			"SELECT DISTINCT products.ID
			FROM   {$wpdb->posts} AS products
			INNER JOIN {$wpdb->postmeta} AS schemes
				ON  products.ID = schemes.post_id
				AND schemes.meta_key = '_wcsatt_schemes'
			LEFT JOIN {$wpdb->postmeta} AS schemes_status
				ON  products.ID = schemes_status.post_id
				AND schemes_status.meta_key = '_wcsatt_schemes_status'
			LEFT JOIN {$wpdb->postmeta} AS storewide_mode
				ON  products.ID = storewide_mode.post_id
				AND storewide_mode.meta_key = '_wcsatt_storewide_selection_mode'
			WHERE products.post_type = 'product'
				AND products.post_status = 'publish'
				AND (
					schemes_status.meta_value = 'override'
					OR ( schemes_status.meta_id IS NULL AND storewide_mode.meta_id IS NULL )
				)",
			ARRAY_A
		);
		$products_with_plans = ! empty( $products_with_plans ) ? wp_list_pluck( $products_with_plans, 'ID' ) : array();
		$products_with_plans = array_map( 'absint', $products_with_plans );
		// This variable adds as many %d placeholders to the query as the IDs. Therefore, we are skipping PHPCS checks for this query.
		$placeholders = implode( ', ', array_fill( 0, count( $products_with_plans ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'products_count'                             => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE `post_type` = 'product' AND `post_status` = 'publish'" ),
			'products_with_plans_count'                  => (int) count( $products_with_plans ),
			'products_with_storewide_plans_count'        => self::get_products_with_storewide_plans_count(),
			'products_with_forced_plan_count'            => empty( $products_with_plans ) ? 0 : (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->posts}` AS posts INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id AND postmeta.meta_key = '_wcsatt_force_subscription' WHERE postmeta.meta_value = 'yes' AND posts.post_type = 'product' AND posts.ID IN ( {$placeholders} ) AND posts.post_status = 'publish'", ...$products_with_plans ) ),
			'products_with_grouped_layout_count'         => empty( $products_with_plans ) ? 0 : (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->posts}` AS posts INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id AND postmeta.meta_key = '_wcsatt_layout' WHERE postmeta.meta_value = 'grouped' AND posts.post_type = 'product' AND posts.ID IN ( {$placeholders} ) AND posts.post_status = 'publish'", ...$products_with_plans ) ),
			'products_with_subscriptions_disabled_count' => self::get_products_with_subscriptions_disabled_count(),
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Counts products using storewide subscription plans (inherit mode).
	 *
	 * Checks both _wcsatt_schemes_status = 'inherit' (new) and _wcsatt_storewide_selection_mode
	 * without an explicit mode key (legacy).
	 *
	 * @since 9.0.0
	 *
	 * @return int Count of products using storewide plans.
	 */
	private static function get_products_with_storewide_plans_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT products.ID)
			FROM   {$wpdb->posts} AS products

			LEFT JOIN {$wpdb->postmeta} AS schemes_status
				ON  products.ID = schemes_status.post_id
				AND schemes_status.meta_key = '_wcsatt_schemes_status'
				AND schemes_status.meta_value = 'inherit'

			LEFT JOIN {$wpdb->postmeta} AS storewide_mode
				ON  products.ID = storewide_mode.post_id
				AND storewide_mode.meta_key = '_wcsatt_storewide_selection_mode'

			WHERE products.post_type = 'product'
				AND products.post_status = 'publish'
				AND (
					-- New: explicit inherit mode via _wcsatt_schemes_status.
					schemes_status.meta_id IS NOT NULL
					-- Legacy: _wcsatt_storewide_selection_mode without an explicit mode key.
					OR ( storewide_mode.meta_id IS NOT NULL AND NOT EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = products.ID AND meta_key = '_wcsatt_schemes_status'
					))
				)"
		);
	}

	/**
	 * Counts non-subscription products that have opted out of storewide subscription plans.
	 *
	 * These are products that are "forcibly sold individually" — they cannot be purchased as subscriptions
	 * because they have explicitly disabled subscription plans.
	 *
	 * @since 9.0.0
	 *
	 * @return int Count of products with subscriptions disabled.
	 */
	private function get_products_with_subscriptions_disabled_count(): int {
		global $wpdb;

		$subscription_term_ids = array();

		// Get the term IDs for legacy subscription product types.
		$subscription_term          = get_term_by( 'slug', 'subscription', 'product_type' );
		$variable_subscription_term = get_term_by( 'slug', 'variable-subscription', 'product_type' );

		if ( $subscription_term instanceof WP_Term ) {
			$subscription_term_ids[] = (int) $subscription_term->term_id;
		}

		if ( $variable_subscription_term instanceof WP_Term ) {
			$subscription_term_ids[] = (int) $variable_subscription_term->term_id;
		}

		// Build the query to find products with subscriptions disabled.
		// Checks both _wcsatt_schemes_status = 'disable' (new) and _wcsatt_disabled = 'yes' (legacy).
		// Exclude legacy subscription product types (they're inherently subscription products).
		$query = "
			SELECT COUNT(DISTINCT products.ID)
			FROM   {$wpdb->posts} AS products

			LEFT JOIN {$wpdb->postmeta} AS schemes_status
			    ON  products.ID = schemes_status.post_id
				AND schemes_status.meta_key = '_wcsatt_schemes_status'
				AND schemes_status.meta_value = 'disable'

			LEFT JOIN {$wpdb->postmeta} AS plans_disabled
			    ON  products.ID = plans_disabled.post_id
				AND plans_disabled.meta_key = '_wcsatt_disabled'
				AND plans_disabled.meta_value = 'yes'

			LEFT JOIN {$wpdb->term_relationships} AS type_relationships
				ON products.ID = type_relationships.object_id

			LEFT JOIN {$wpdb->term_taxonomy} AS legacy_types
				ON  type_relationships.term_taxonomy_id = legacy_types.term_taxonomy_id
				AND legacy_types.taxonomy = 'product_type'

			WHERE products.post_type = 'product'
				AND products.post_status = 'publish'
				AND ( schemes_status.meta_id IS NOT NULL OR plans_disabled.meta_id IS NOT NULL )
		";

		// Exclude legacy subscription product types.
		if ( ! empty( $subscription_term_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $subscription_term_ids ), '%d' ) );
			$query       .= $wpdb->prepare( " AND ( legacy_types.term_id IS NULL OR legacy_types.term_id NOT IN ( {$placeholders} ) )", ...$subscription_term_ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}

		return (int) $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
