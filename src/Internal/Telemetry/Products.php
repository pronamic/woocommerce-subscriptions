<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Telemetry;

use WCS_Plugin_Upgrade_9_2_0;
use WCSG_Admin;
use WP_Query;
use WP_Term;

/**
 * Provides high-level information, primarily intended for use with WC Tracker, about the number and range of
 * subscription products.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Products {
	/**
	 * Counts the total number of active subscription products that are eligible for gifting.
	 *
	 * If the gifting feature is disabled, this will be zero.
	 *
	 * Otherwise a product counts when it carries an explicit `enabled` value. Until the store is settled - the 9.2.0
	 * gifting migration has not finished materializing the legacy storewide default onto every product, or has not
	 * run yet - products without a usable value still follow that default, so they count too on an "enabled for all
	 * products" store. This mirrors
	 * {@see \WC_Subscriptions_Product::is_gifting_enabled_for_product()}: the two must agree, or telemetry reports a
	 * catalog the storefront does not have.
	 *
	 * @return int
	 */
	public function get_active_giftable_products_count(): int {
		global $wpdb;

		if ( ! WCSG_Admin::is_gifting_enabled() ) {
			return 0;
		}

		$subscription_product_term_ids = $this->get_subscription_product_type_term_ids();

		// Under unusual conditions, we may be missing one or both product type term IDs.
		if ( false === $subscription_product_term_ids ) {
			return 0;
		}

		$follows_storewide_default = ! WCS_Plugin_Upgrade_9_2_0::has_gifting_migration_settled() && WCSG_Admin::is_gifting_enabled_for_all_products();

		if ( $follows_storewide_default ) {
			// Only 'enabled' and 'disabled' are a per-product choice. Everything else - no row at all, an empty
			// string from the legacy "use global setting" option, or a stray value - is not one, so it follows
			// the storewide default. NULL is spelled out because SQL's NOT IN never matches it.
			$atomic_gifting_condition = "(
				gifting_setting.meta_value = 'enabled'
				OR gifting_setting.meta_value IS NULL
				OR gifting_setting.meta_value NOT IN ( 'enabled', 'disabled' )
			)";
		} else {
			$atomic_gifting_condition = "gifting_setting.meta_value = 'enabled'";
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"
					SELECT     COUNT(*)
					FROM       %i AS product
					-- Reduce the result set to products of the `subscription` and `variable_subscription` product types.
					INNER JOIN (
						SELECT     product_post.ID,
								   product_type.term_taxonomy_id,
								   product_post.post_status
						FROM       %i AS product_post
						INNER JOIN %i AS product_type ON (
							product_type.object_id = product_post.ID
							AND product_type.term_taxonomy_id IN ( %d, %d )
						)
					) p ON (
					    -- For variable subscription products, the relevant taxonomy term is applied to the parent post.
						( product.ID = p.ID AND p.term_taxonomy_id = %d )
						OR ( product.post_parent = p.ID AND p.term_taxonomy_id = %d )
					)
					-- Get the product's own gifting value, if it has one.
					LEFT JOIN %i AS gifting_setting ON (
						gifting_setting.post_id = product.ID
						AND gifting_setting.meta_key = '_subscription_gifting'
					)
					WHERE product.post_type IN ( 'product', 'product_variation' )
						  AND product.post_status = 'publish'
						  AND p.post_status = 'publish'
						  AND $atomic_gifting_condition
				",
				$wpdb->posts,
				$wpdb->posts,
				$wpdb->term_relationships,
				$subscription_product_term_ids['subscription'],
				$subscription_product_term_ids['variable_subscription'],
				$subscription_product_term_ids['subscription'],
				$subscription_product_term_ids['variable_subscription'],
				$wpdb->postmeta
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Supplies meta query arguments, for use inside a larger set of WP_Query args, that limits results to products
	 * where gifting is individually enabled or disabled, depending on the value of $enabled.
	 *
	 * @param bool $enabled If we are interested in products where gifting is enabled, or excluding those that are disabled.
	 *
	 * @return array[]
	 */
	private function build_gifting_meta_query( bool $enabled ): array {
		$meta_query = array(
			array(
				'key'     => '_subscription_gifting',
				'value'   => $enabled ? 'enabled' : 'disabled',
				'compare' => $enabled ? '=' : '!=',
			),
		);

		if ( ! $enabled ) {
			$meta_query['relation'] = 'OR';
			$meta_query[]           = array(
				'key'     => '_subscription_gifting',
				'compare' => 'NOT EXISTS',
			);
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		return array( 'meta_query' => $meta_query );
	}

	/**
	 * Returns an associative array detailing the number of published subscription products by frequency.
	 *
	 * The return value is an associative array keyed by "{period}_{interval}" with the count as the value:
	 *
	 *     [
	 *         'month_1' => 100,
	 *         'month_2' => 300,
	 *         ...
	 *     ]
	 *
	 * @return array
	 */
	public function get_product_frequencies(): array {
		global $wpdb;

		$subscription_product_term_ids = $this->get_subscription_product_type_term_ids();

		// Under unusual conditions, we may be missing one or both product type term IDs.
		if ( false === $subscription_product_term_ids ) {
			return array();
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"
					-- Count the number of subscription products by billing period and billing interval.
					SELECT     billing_period.meta_value AS period,
					           billing_interval.meta_value AS `interval`,
					           COUNT(*) AS count
					FROM       %i AS product
					-- Reduce the result set to products of the `subscription` and `variable_subscription` product types.
					INNER JOIN (
					    SELECT     product_post.ID,
					               product_type.term_taxonomy_id,
					               product_post.post_status
					    FROM       %i AS product_post
					    INNER JOIN %i AS product_type ON (
					        product_type.object_id = product_post.ID
					        AND product_type.term_taxonomy_id IN ( %d, %d )
					    )
					) subscription_product ON (
					    -- For variable subscription products, the relevant taxonomy term is applied to the parent post.
					    ( product.ID = subscription_product.ID AND subscription_product.term_taxonomy_id = %d )
					    OR ( product.post_parent = subscription_product.ID AND subscription_product.term_taxonomy_id = %d )
					)
					-- Obtain the billing period interval (usually an integer, or string representation of an integer).
					LEFT JOIN %i AS billing_interval ON (
					    billing_interval.post_id = product.ID
					    AND billing_interval.meta_key = '_subscription_period_interval'
					)
					-- Obtain the billing period (usually one of 'day', 'week', 'month', 'year').
					LEFT JOIN %i AS billing_period ON (
					    billing_period.post_id = product.ID
					    AND billing_period.meta_key = '_subscription_period'
					)
					-- We are only interested in active (published) products and product variations.
					WHERE     product.post_type IN ( 'product', 'product_variation' )
					          AND product.post_status = 'publish'
					          AND subscription_product.post_status = 'publish'
					GROUP BY  billing_period.meta_value,
					          billing_interval.meta_value
					ORDER BY  count DESC,
					          billing_period.meta_value ASC,
					          billing_interval.meta_value DESC
				",
				$wpdb->posts,
				$wpdb->posts,
				$wpdb->term_relationships,
				$subscription_product_term_ids['subscription'],
				$subscription_product_term_ids['variable_subscription'],
				$subscription_product_term_ids['subscription'],
				$subscription_product_term_ids['variable_subscription'],
				$wpdb->postmeta,
				$wpdb->postmeta,
			)
		);

		$formatted = array();

		foreach ( $results as $result_set ) {
			if ( empty( $result_set->period ) || empty( $result_set->interval ) ) {
				continue;
			}

			$key               = (string) $result_set->period . '_' . (int) $result_set->interval;
			$formatted[ $key ] = (int) $result_set->count;
		}

		return $formatted;
	}

	/**
	 * Returns the term IDs for the subscription and variable subscription product types.
	 *
	 * This will be presented as a keyed array of integers, as follows (unless one or both terms are missing, in which
	 * case bool false will be returned):
	 *
	 *     [
	 *         'subscription' => int,
	 *         'variable_subscription' => int
	 *     ]
	 *
	 * @return int[]|false The term IDs for the subscription and variable subscription product types, else false.
	 */
	private function get_subscription_product_type_term_ids() {
		$subscription_product_term_ids = array();

		$subscription_term          = get_term_by( 'slug', 'subscription', 'product_type' );
		$variable_subscription_term = get_term_by( 'slug', 'variable-subscription', 'product_type' );

		if ( ! is_a( $subscription_term, WP_Term::class ) || ! is_a( $variable_subscription_term, WP_Term::class ) ) {
			return false;
		}

		return array(
			'subscription'          => (int) $subscription_term->term_id,
			'variable_subscription' => (int) $variable_subscription_term->term_id,
		);
	}
}
