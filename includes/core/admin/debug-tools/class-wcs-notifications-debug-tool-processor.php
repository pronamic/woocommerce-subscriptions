<?php

/**
 * WooCommerce Subscriptions Notifications Debug Tool Processor.
 *
 * @package  WooCommerce Subscriptions
 * @category Class
 * @since    7.7.0
 */
class WCS_Notifications_Debug_Tool_Processor implements WCS_Batch_Processor {

	/**
	 * Option name for the tool state.
	 * This is used to pass the state of the tool between requests.
	 */
	const TOOL_STATE_OPTION_NAME = 'wcs_notifications_debug_tool_state';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'woocommerce_debug_tools', array( $this, 'handle_woocommerce_debug_tools' ), 999, 1 );
	}

	/**
	 * Get the state of the tool.
	 *
	 * @return array {
	 *   @last_offset Last offset processed.
	 * }
	 */
	private function get_tool_state(): array {
		return (array) get_option( self::TOOL_STATE_OPTION_NAME, array() );
	}

	/**
	 * Update the state of the tool.
	 *
	 * @param array $state New state of the tool.
	 */
	private function update_tool_state( $state ) {
		update_option( self::TOOL_STATE_OPTION_NAME, $state );
	}

	/**
	 * Delete the state of the tool.
	 */
	private function delete_tool_state() {
		delete_option( self::TOOL_STATE_OPTION_NAME );
	}

	/**
	 * Get a user-friendly name for this processor.
	 *
	 * @return string Name of the processor.
	 */
	public function get_name(): string {
		return 'wcs_notifications_debug_tool_processor';
	}

	/**
	 * Get a user-friendly description for this processor.
	 *
	 * @return string Description of what this processor does.
	 */
	public function get_description(): string {
		return 'WooCommerce Notifications Debug Tool Processor';
	}

	/**
	 * Get the allowed subscription statuses to process.
	 */
	protected function get_subscription_statuses(): array {
		$allowed_statuses = array(
			'active',
			'pending',
			'on-hold',
		);

		return array_map( 'wcs_sanitize_subscription_status_key', $allowed_statuses );
	}

	/**
	 * Get the total number of pending items that require processing.
	 * Once an item is successfully processed by 'process_batch' it shouldn't be included in this count.
	 *
	 * Note that the once the processor is enqueued the batch processor controller will keep
	 * invoking `get_next_batch_to_process` and `process_batch` repeatedly until this method returns zero.
	 *
	 * In this case, this means total number of subscriptions in allowed statuses - number of processed subscriptions.
	 *
	 * @return int Number of items pending processing.
	 */
	public function get_total_pending_count(): int {
		global $wpdb;

		$allowed_statuses = $this->get_subscription_statuses();
		$placeholders     = implode( ', ', array_fill( 0, count( $allowed_statuses ), '%s' ) );

		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			$total_subscriptions = $wpdb->get_var(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$wpdb->prepare(
					"SELECT 
								COUNT(id)
							FROM {$wpdb->prefix}wc_orders 
							WHERE type='shop_subscription'
							AND status IN ($placeholders)
							",
					...$allowed_statuses
				)
			);
		} else {
			$total_subscriptions = $wpdb->get_var(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$wpdb->prepare(
					"SELECT 
								COUNT(ID)
							FROM {$wpdb->prefix}posts 
							WHERE post_type='shop_subscription'
							AND post_status IN ($placeholders)
							",
					...$allowed_statuses
				)
			);
		}

		$state = $this->get_tool_state();
		if ( isset( $state['last_offset'] ) ) {
			$total_subscriptions -= (int) $state['last_offset'];
		}

		return $total_subscriptions;
	}

	/**
	 * Returns the next batch of items that need to be processed.
	 *
	 * A batch item can be anything needed to identify the actual processing to be done,
	 * but whenever possible items should be numbers (e.g. database record ids)
	 * or at least strings, to ease troubleshooting and logging in case of problems.
	 *
	 * The size of the batch returned can be less than $size if there aren't that
	 * many items pending processing (and it can be zero if there isn't anything to process),
	 * but the size should always be consistent with what 'get_total_pending_count' returns
	 * (i.e. the size of the returned batch shouldn't be larger than the pending items count).
	 *
	 * @param int $size Maximum size of the batch to be returned.
	 *
	 * @return array Batch of items to process, containing $size or less items.
	 */
	public function get_next_batch_to_process( int $size ): array {
		global $wpdb;

		$allowed_statuses = $this->get_subscription_statuses();
		$placeholders     = implode( ', ', array_fill( 0, count( $allowed_statuses ), '%s' ) );
		$state            = $this->get_tool_state();
		$offset           = isset( $state['last_offset'] ) ? (int) $state['last_offset'] : 0;

		$args = array_merge(
			$allowed_statuses,
			array( $size ),
			array( $offset )
		);

		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			$subscriptions_to_process = $wpdb->get_col(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				$wpdb->prepare(
					"SELECT 
								id
							FROM {$wpdb->prefix}wc_orders 
							WHERE type='shop_subscription'
							AND status IN ($placeholders)
							ORDER BY id ASC
							LIMIT %d
							OFFSET %d",
					...$args
				)
			);
		} else {
			$subscriptions_to_process = $wpdb->get_col(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				$wpdb->prepare(
					"SELECT 
								ID
							FROM {$wpdb->prefix}posts 
							WHERE post_type='shop_subscription'
							AND post_status IN ($placeholders)
							ORDER BY ID ASC
							LIMIT %d
							OFFSET %d",
					...$args
				)
			);
		}

		// Reset the tool state if there are no more subscriptions to process.
		if ( empty( $subscriptions_to_process ) ) {
			$this->delete_tool_state();
		}

		return $subscriptions_to_process;
	}

	/**
	 * Process data for the supplied batch.
	 *
	 * This method should be prepared to receive items that don't actually need processing
	 * (because they have been processed before) and ignore them, but if at least
	 * one of the batch items that actually need processing can't be processed, an exception should be thrown.
	 *
	 * Once an item has been processed it shouldn't be counted in 'get_total_pending_count'
	 * nor included in 'get_next_batch_to_process' anymore (unless something happens that causes it
	 * to actually require further processing).
	 *
	 * @throw \Exception Something went wrong while processing the batch.
	 *
	 * @param array $batch Batch to process, as returned by 'get_next_batch_to_process'.
	 */
	public function process_batch( array $batch ): void {

		$subscriptions_notifications = WC_Subscriptions_Core_Plugin::instance()->notifications_scheduler;

		foreach ( $batch as $subscription_id ) {
			$subscription = wcs_get_subscription( $subscription_id );

			if ( ! $subscription ) {
				continue;
			}

			if ( WC_Subscriptions_Email_Notifications::notifications_globally_enabled() ) {
				$subscriptions_notifications->update_status( $subscription, $subscription->get_status(), null );
			} else {
				$subscriptions_notifications->unschedule_all_notifications( $subscription );
			}

			// Update the subscription's update time to mark it as updated.
			$subscription->set_date_modified( time() );
			$subscription->save();
		}

		// Update tool state.
		$state                = $this->get_tool_state();
		$state['last_offset'] = isset( $state['last_offset'] ) ? absint( $state['last_offset'] ) + count( $batch ) : count( $batch );
		$this->update_tool_state( $state );
	}

	/**
	 * Default (preferred) batch size to pass to 'get_next_batch_to_process'.
	 * The controller will pass this size unless it's externally configured
	 * to use a different size.
	 *
	 * @return int Default batch size.
	 */
	public function get_default_batch_size(): int {
		return 20;
	}

	/**
	 * Start the background process for batch processing subscription notifications updates.
	 *
	 * @return string Informative string to show after the tool is triggered in UI.
	 */
	public function enqueue(): string {
		$batch_processor = WCS_Batch_Processing_Controller::instance();
		if ( $batch_processor->is_enqueued( self::class ) ) {
			return __( 'Background process for updating subscription notifications already started, nothing done.', 'woocommerce-subscriptions' );
		}

		$batch_processor->enqueue_processor( self::class );

		return __( 'Background process for updating subscription notifications started', 'woocommerce-subscriptions' );
	}

	/**
	 * Stop the background process for batch processing subscription notifications updates.
	 *
	 * @return string Informative string to show after the tool is triggered in UI.
	 */
	public function dequeue(): string {
		$batch_processor = WCS_Batch_Processing_Controller::instance();
		if ( ! $batch_processor->is_enqueued( self::class ) ) {
			return __( 'Background process for updating subscription notifications not started, nothing done.', 'woocommerce-subscriptions' );
		}

		$batch_processor->remove_processor( self::class );
		return __( 'Background process for updating subscription notifications stopped', 'woocommerce-subscriptions' );
	}

	/**
	 * Add the tool to start or stop the background process that manages notification batch processing.
	 *
	 * @param array $tools Old tools array.
	 * @return array Updated tools array.
	 */
	public function handle_woocommerce_debug_tools( array $tools ): array {

		if ( ! WC_Subscriptions_Email_Notifications::notifications_globally_enabled() ) {

			$tools['start_add_subscription_notifications'] = array(
				'name'             => __( 'Regenerate subscription notifications', 'woocommerce-subscriptions' ),
				'button'           => __( 'Regenerate notifications', 'woocommerce-subscriptions' ),
				'disabled'         => true,
				'desc'             => sprintf(
					'%1$s<br/><strong class="red">%2$s</strong> %3$s <a href="%4$s">%5$s</a>',
					__( 'This tool will add notifications to pending, active, and on-hold subscriptions. These updates will occur gradually in the background using Action Scheduler.', 'woocommerce-subscriptions' ),
					__( 'Note:', 'woocommerce-subscriptions' ),
					__( 'Notifications are currently turned off. To activate them, check the "Enable customer renewal reminder notification emails." option (via WooCommerce > Settings > Subscriptions > Customer Notifications).', 'woocommerce-subscriptions' ),
					esc_url( admin_url( 'admin.php?page=wc-settings&tab=subscriptions' ) ),
					__( 'Manage settings.', 'woocommerce-subscriptions' )
				),
				'requires_refresh' => true,
			);
			return $tools;
		}

		$batch_processor = WCS_Batch_Processing_Controller::instance();

		if ( $batch_processor->is_enqueued( self::class ) ) {

			$pending_count                                = $this->get_total_pending_count();
			$tools['stop_add_subscription_notifications'] = array(
				'name'             => __( 'Regenerate subscription notifications', 'woocommerce-subscriptions' ),
				'button'           => __( 'Stop regenerating notifications', 'woocommerce-subscriptions' ),
				'desc'             =>
					/* translators: %1$d=count of total entries needing conversion */
					sprintf( __( 'Stopping this will halt the background process that adds notifications to pending, active, and on-hold subscriptions. %1$d subscriptions remain to be processed.', 'woocommerce-subscriptions' ), $pending_count ),
				'callback'         => array( $this, 'dequeue' ),
				'requires_refresh' => true,
			);
		} else {
			$tools['start_add_subscription_notifications'] = array(
				'name'             => __( 'Regenerate subscription notifications', 'woocommerce-subscriptions' ),
				'button'           => __( 'Regenerate notifications', 'woocommerce-subscriptions' ),
				'desc'             => __( 'This tool will regenerate notifications to pending, active, and on-hold subscriptions. These updates will occur gradually in the background using Action Scheduler.', 'woocommerce-subscriptions' ),
				'callback'         => array( $this, 'enqueue' ),
				'requires_refresh' => true,
			);
		}

		return $tools;
	}
}
