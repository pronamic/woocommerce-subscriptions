<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\CLI\Test_Data;

use WC_Payment_Tokens;
use WCS_Retry_Manager;
use WP_CLI;

/**
 * WP-CLI command for purging test subscriptions created by `wp wc-subs generate`.
 *
 * Local / development use only. Finds every record tagged with `_wcs_test_data` and removes
 * it. Records that were *supplied* to `generate` via --customer or --product are never tagged
 * and therefore never touched — only records the generator itself created.
 *
 * See docs/dev-tools/generate-subscriptions.md.
 *
 * Safety properties enforced by this command:
 *  - Refuses to run unless WP_ENVIRONMENT_TYPE is 'local' or 'development' (overridable only with an explicit flag).
 *  - Defaults to preview mode; actual deletion requires --yes.
 *
 * @since   x.x.x
 * @internal This class may be modified, moved or removed in future releases.
 */
class Purge_Command {

	/**
	 * Purge test data created by the generator.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Actually delete the records. Without this flag, the command prints a summary of
	 * what would be removed and exits.
	 *
	 * [--i-know-what-im-doing]
	 * : Override the environment-type safety guard. Not recommended.
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview what would be deleted.
	 *     wp wc-subs purge-test
	 *
	 *     # Actually delete everything tagged as test data.
	 *     wp wc-subs purge-test --yes
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( $args, $assoc_args ) {
		$this->assert_safe_environment( $assoc_args );

		$subscription_ids = $this->find_orders_of_type( 'shop_subscription' );
		$order_ids        = $this->find_orders_of_type( 'shop_order' );
		$product_ids      = $this->find_products();
		$user_ids         = $this->find_users();
		$retry_ids        = $this->find_retries_for_orders( $order_ids );
		$token_ids        = $this->find_payment_tokens();
		$action_ids       = $this->find_scheduled_actions_for_subscriptions( $subscription_ids );

		$counts = array(
			'subscriptions' => count( $subscription_ids ),
			'orders'        => count( $order_ids ),
			'products'      => count( $product_ids ),
			'users'         => count( $user_ids ),
			'retries'       => count( $retry_ids ),
			'tokens'        => count( $token_ids ),
			'actions'       => count( $action_ids ),
		);

		if ( 0 === array_sum( $counts ) ) {
			WP_CLI::success( 'No test data found.' );
			return;
		}

		if ( empty( $assoc_args['yes'] ) ) {
			$this->print_summary( $counts, 'would remove' );
			WP_CLI::log( '' );
			WP_CLI::log( 'Re-run with --yes to actually delete.' );
			return;
		}

		$this->purge_retries( $retry_ids );
		$this->purge_tokens( $token_ids );
		$this->purge_scheduled_actions( $subscription_ids );
		$this->purge_orders( $subscription_ids );
		$this->purge_orders( $order_ids );
		$this->purge_products( $product_ids );
		$this->purge_users( $user_ids );

		$this->print_summary( $counts, 'removed' );

		if ( $counts['tokens'] > 0 ) {
			WP_CLI::log( 'Note: Stripe test customers and PaymentMethods created via the Stripe API were not removed. Clean up your Stripe test dashboard manually if needed.' );
		}

		WP_CLI::success( 'Test data purged.' );
	}

	/**
	 * Abort unless the current WP environment is suitable.
	 *
	 * @param array $assoc_args
	 */
	private function assert_safe_environment( array $assoc_args ) {
		$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

		if ( in_array( $env, array( 'local', 'development' ), true ) ) {
			return;
		}

		if ( ! empty( $assoc_args['i-know-what-im-doing'] ) ) {
			WP_CLI::warning( sprintf( "WARNING: Running purge in '%s' environment. This will permanently delete all records tagged as test data.", $env ) );
			WP_CLI::confirm( 'Are you sure you want to proceed?' );
			return;
		}

		WP_CLI::error(
			sprintf(
				"Refusing to run in environment '%s'. This command is only supported when WP_ENVIRONMENT_TYPE is 'local' or 'development'.",
				$env
			)
		);
	}

	/**
	 * Find orders of the given WC order type that carry the test-data tag.
	 * Works with both legacy post and HPOS datastores via wc_get_orders().
	 *
	 * @param string $type
	 * @return int[]
	 */
	private function find_orders_of_type( $type ) {
		$ids = wc_get_orders(
			array(
				'type'       => $type,
				'limit'      => -1,
				'status'     => 'any',
				'return'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => Generator::TEST_META_KEY,
						'value' => '1',
					),
				),
			)
		);

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * @return int[]
	 */
	private function find_products() {
		return get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'any',
				'meta_key'         => Generator::TEST_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'suppress_filters' => true,
			)
		);
	}

	/**
	 * @return int[]
	 */
	private function find_users() {
		$ids = get_users(
			array(
				'meta_key'   => Generator::TEST_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'     => 'ID',
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * @param int[] $order_ids
	 * @return int[]
	 */
	private function find_retries_for_orders( array $order_ids ) {
		if ( empty( $order_ids ) ) {
			return array();
		}

		// The retry store only exists once the table has been created. If it hasn't (e.g. no
		// failure scenarios have ever been generated on this site), there are no retries to clean up.
		if ( ! WCS_Retry_Manager::retry_table_exists() ) {
			return array();
		}

		$retry_ids = array();
		$store     = WCS_Retry_Manager::store();
		foreach ( $order_ids as $id ) {
			$retry_ids = array_merge( $retry_ids, $store->get_retry_ids_for_order( $id ) );
		}
		return array_map( 'intval', $retry_ids );
	}

	/**
	 * @param int[] $retry_ids
	 */
	private function purge_retries( array $retry_ids ) {
		if ( empty( $retry_ids ) ) {
			return;
		}
		$store = WCS_Retry_Manager::store();
		foreach ( $retry_ids as $id ) {
			if ( ! $store->delete_retry( $id ) ) {
				WP_CLI::warning( sprintf( 'Failed to delete retry %d.', $id ) );
			}
		}
	}

	/**
	 * Force-delete each order. Subscriptions are WC orders under the hood, so the same loop
	 * handles both — call with subscriptions first, then regular orders.
	 *
	 * @param int[] $ids
	 */
	private function purge_orders( array $ids ) {
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order ) {
				$order->delete( true );
			}
		}
	}

	/**
	 * @param int[] $ids
	 */
	private function purge_products( array $ids ) {
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}

	/**
	 * @param int[] $ids
	 */
	private function purge_users( array $ids ) {
		if ( empty( $ids ) ) {
			return;
		}

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		foreach ( $ids as $id ) {
			wp_delete_user( $id );
		}
	}

	/**
	 * Find payment tokens tagged as test data. Queries the token meta table
	 * directly because the WC Payment Tokens API does not support meta-based
	 * lookups, and tokens may belong to non-test users supplied via --customer
	 * (who are not themselves tagged with _wcs_test_data).
	 *
	 * @return int[]
	 */
	private function find_payment_tokens() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No WC API supports meta-based token lookup; dev-only purge tool.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT payment_token_id FROM {$wpdb->prefix}woocommerce_payment_tokenmeta WHERE meta_key = %s AND meta_value = %s",
				Generator::TEST_META_KEY,
				'1'
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * @param int[] $ids
	 */
	private function purge_tokens( array $ids ) {
		foreach ( $ids as $id ) {
			WC_Payment_Tokens::delete( $id );
		}
	}

	/**
	 * Find Action Scheduler renewal hooks for all test-tagged subscriptions.
	 * Queries all statuses to match what purge_scheduled_actions() removes via
	 * as_unschedule_all_actions(). Currently only S2b schedules this hook, but
	 * all test subscriptions are checked so future cases are covered.
	 *
	 * @param int[] $subscription_ids All test-tagged subscription ids.
	 * @return int[]
	 */
	private function find_scheduled_actions_for_subscriptions( array $subscription_ids ) {
		if ( empty( $subscription_ids ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array();
		}

		$action_ids = array();
		foreach ( $subscription_ids as $subscription_id ) {
			$ids = as_get_scheduled_actions(
				array(
					'hook'     => Generator::HOOK_RENEWAL_PAYMENT,
					'args'     => array( 'subscription_id' => (int) $subscription_id ),
					'per_page' => -1,
				),
				'ids'
			);

			$action_ids = array_merge( $action_ids, array_map( 'intval', (array) $ids ) );
		}

		return array_values( array_unique( $action_ids ) );
	}

	/**
	 * Unschedule all Action Scheduler renewal hooks for test-tagged
	 * subscriptions before the subscriptions themselves are deleted.
	 *
	 * @param int[] $subscription_ids All test-tagged subscription ids.
	 */
	private function purge_scheduled_actions( array $subscription_ids ) {
		if ( empty( $subscription_ids ) || ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		foreach ( $subscription_ids as $subscription_id ) {
			as_unschedule_all_actions(
				Generator::HOOK_RENEWAL_PAYMENT,
				array( 'subscription_id' => (int) $subscription_id )
			);
		}
	}

	/**
	 * Render the counts as a small table with the given verb ('would remove' | 'removed').
	 *
	 * @param array  $counts
	 * @param string $verb
	 */
	private function print_summary( array $counts, $verb ) {
		WP_CLI::log( sprintf( 'Test data %s:', $verb ) );
		$rows = array();
		foreach ( $counts as $kind => $count ) {
			$rows[] = array(
				'kind'  => $kind,
				'count' => $count,
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'kind', 'count' ) );
	}
}
