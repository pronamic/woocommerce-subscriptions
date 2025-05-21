<?php

/**
 * WooCommerce Subscriptions Notifications Batch Processor.
 *
 * This batch processor is used to process subscriptions whenever global settings get updated
 * (global on/off for notifications or time offset).
 *
 * It will only process subscriptions whose update time is before the time when the settings got updated.
 * To ensure all subscription end up having correct notifications, the hook
 * WCS_Action_Scheduler_Customer_Notifications::update_notifications will update any notifications
 * whose update time is before the settings got updated. The rest of subscriptions should be updated by this
 * batch processor.
 *
 * In addition to this batch processor which runs ad-hoc, there's also a debug tool to regenerate notifications for
 * all subscriptions: WCS_Notifications_Debug_Tool_Processor.
 *
 * @package  WooCommerce Subscriptions
 * @category Class
 * @since    7.7.0
 */
class WCS_Notifications_Batch_Processor implements WCS_Batch_Processor {

	/**
	 * Get a user-friendly name for this processor.
	 *
	 * @return string Name of the processor.
	 */
	public function get_name(): string {
		return 'wcs_notifications_batch_processor';
	}

	/**
	 * Get a user-friendly description for this processor.
	 *
	 * @return string Description of what this processor does.
	 */
	public function get_description(): string {
		return 'WooCommerce Notifications Batch Processor';
	}

	/**
	 * Get the subscription statuses that should be processed.
	 *
	 * @return array Subscription statuses that should be processed.
	 */
	protected function get_subscription_statuses() {
		$allowed_statuses = array(
			'active',
			'pending',
			'on-hold',
			'pending-cancel',
		);

		return array_map( 'wcs_sanitize_subscription_status_key', $allowed_statuses );
	}

	/**
	 * Get the timestamp of the last time the notification settings were updated.
	 *
	 * @return string Datetime of the last time the notification settings were updated.
	 */
	public function get_notification_settings_update_time() {
		$notification_settings_update_timestamp = get_option( 'wcs_notification_settings_update_time', 0 );
		if ( 0 === $notification_settings_update_timestamp ) {
			return '';
		}

		$notification_settings_update_time = new DateTime( "@$notification_settings_update_timestamp", new DateTimeZone( 'UTC' ) );
		return $notification_settings_update_time->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Get the total number of pending items that require processing.
	 * Once an item is successfully processed by 'process_batch' it shouldn't be included in this count.
	 *
	 * Note that once the processor is enqueued the batch processor controller will keep
	 * invoking `get_next_batch_to_process` and `process_batch` repeatedly until this method returns zero.
	 *
	 * Since this batch processor updates only subscriptions older than the settings update,
	 * it only selects subscriptions updated before the settings update time.
	 *
	 * @return int Number of items pending processing.
	 */
	public function get_total_pending_count(): int {
		global $wpdb;

		if ( empty( $this->get_notification_settings_update_time() ) ) {
			return 0;
		}

		$allowed_statuses = $this->get_subscription_statuses();
		$placeholders     = implode( ', ', array_fill( 0, count( $allowed_statuses ), '%s' ) );

		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			return $wpdb->get_var(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				$wpdb->prepare(
					"SELECT 
						COUNT(*) 
					FROM {$wpdb->prefix}wc_orders 
					WHERE type='shop_subscription'
					AND date_updated_gmt < %s
					AND status IN ($placeholders)
					",
					$this->get_notification_settings_update_time(),
					...$allowed_statuses
				)
			);
		} else {
			return $wpdb->get_var(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				$wpdb->prepare(
					"SELECT 
						COUNT(*) 
					FROM {$wpdb->prefix}posts 
					WHERE post_type='shop_subscription'
					AND post_modified_gmt < %s
					AND post_status IN ($placeholders)
					",
					$this->get_notification_settings_update_time(),
					...$allowed_statuses
				)
			);
		}
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

		if ( empty( $this->get_notification_settings_update_time() ) ) {
			return [];
		}

		$allowed_statuses = $this->get_subscription_statuses();
		$placeholders     = implode( ', ', array_fill( 0, count( $allowed_statuses ), '%s' ) );

		$args = array_merge(
			array( $this->get_notification_settings_update_time() ),
			$allowed_statuses,
			array( $size )
		);

		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			return $wpdb->get_col(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				$wpdb->prepare(
					"SELECT 
						id
					FROM {$wpdb->prefix}wc_orders 
					WHERE type='shop_subscription'
					AND date_updated_gmt < %s
					AND status IN ($placeholders)
					ORDER BY id ASC
					LIMIT %d",
					...$args
				)
			);
		} else {
			return $wpdb->get_col(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				$wpdb->prepare(
					"SELECT 
						ID
					FROM {$wpdb->prefix}posts 
					WHERE post_type='shop_subscription'
					AND post_modified_gmt < %s
					AND post_status IN ($placeholders)
					ORDER BY ID ASC
					LIMIT %d",
					...$args
				)
			);
		}
	}

	/**
	 * Process data for the supplied batch: update all notifications for given batch of subscriptions.
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
		// Instantiating this again would hook another set of hooks for update_status and update_date. No bueno.
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
	 * Start the background process for updating notifications.
	 *
	 * @return string Informative string to show after the tool is triggered in UI.
	 */
	public static function enqueue(): string {
		$batch_processor = WCS_Batch_Processing_Controller::instance();
		if ( $batch_processor->is_enqueued( self::class ) ) {
			return __( 'Background process for updating subscription notifications already started, nothing done.', 'woocommerce-subscriptions' );
		}

		$batch_processor->enqueue_processor( self::class );
		return __( 'Background process for updating subscription notifications started.', 'woocommerce-subscriptions' );
	}

	/**
	 * Stop the background process for updating notifications.
	 *
	 * @return string Informative string to show after the tool is triggered in UI.
	 */
	public static function dequeue(): string {
		$batch_processor = WCS_Batch_Processing_Controller::instance();
		if ( ! $batch_processor->is_enqueued( self::class ) ) {
			return __( 'Background process for updating subscription notifications not started, nothing done.', 'woocommerce-subscriptions' );
		}

		$batch_processor->remove_processor( self::class );
		return __( 'Background process for updating subscription notifications stopped.', 'woocommerce-subscriptions' );
	}
}
