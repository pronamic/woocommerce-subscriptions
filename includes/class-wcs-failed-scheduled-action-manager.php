<?php
/**
 * Failed Scheduled Action Manager for subscription events
 *
 * @version   2.2.19
 * @package   WooCommerce Subscriptions
 * @category  Class
 * @author    Prospress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Failed_Scheduled_Action_Manager {

	/**
	 * Action hooks we're interested in tracking.
	 *
	 * @var array
	 */
	protected $tracked_scheduled_actions = array(
		'woocommerce_scheduled_subscription_trial_end'           => 1,
		'woocommerce_scheduled_subscription_payment'             => 1,
		'woocommerce_scheduled_subscription_payment_retry'       => 1,
		'woocommerce_scheduled_subscription_expiration'          => 1,
		'woocommerce_scheduled_subscription_end_of_prepaid_term' => 1,
	);

	/**
	 * WC Logger instance for logging messages.
	 *
	 * @var WC_Logger
	 */
	protected $logger;

	/**
	 * Constructor.
	 *
	 * @param WC_Logger $logger The WC Logger instance.
	 * @since 2.2.19
	 */
	public function __construct( WC_Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Attach callbacks.
	 *
	 * @since 2.2.19
	 */
	public function init() {
		add_action( 'action_scheduler_failed_action', array( $this, 'log_action_scheduler_failure' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'maybe_show_admin_notice' ) );
	}

	/**
	 * Log a message to the failed-scheduled-actions log.
	 *
	 * @param string $message the message to be written to the log.
	 * @since 2.2.19
	 */
	protected function log( $message ) {
		$this->logger->add( 'failed-scheduled-actions', $message );
	}

	/**
	 * When a scheduled action failure is triggered, log information about the failed action to a WC logger.
	 *
	 * @param int $action_id the action which failed.
	 * @param int $timeout the number of seconds an action can run for before timing out.
	 * @since 2.2.19
	 */
	public function log_action_scheduler_failure( $action_id, $timeout ) {
		$action = $this->get_action( $action_id );

		if ( ! isset( $this->tracked_scheduled_actions[ $action->get_hook() ] ) ) {
			return;
		}

		$subscription_action = $this->get_action_hook_label( $action->get_hook() );

		$this->log( sprintf( 'scheduled action %s (%s) failed to finish processing after %s seconds', $action_id, $subscription_action , $timeout ) );
		$this->log( sprintf( 'action args: %s', $this->get_action_args_string( $action->get_args() ) ) );

		// Store information about the scheduled action for displaying an admin notice
		$failed_scheduled_actions = get_option( WC_Subscriptions_Admin::$option_prefix . '_failed_scheduled_actions', array() );

		$failed_scheduled_actions[ $action_id ] = array(
			'args' => $action->get_args(),
			'type' => $subscription_action,
		);

		update_option( WC_Subscriptions_Admin::$option_prefix . '_failed_scheduled_actions', $failed_scheduled_actions );
	}

	/**
	 * Display an admin notice when a scheduled action failure has occurred.
	 *
	 * @since 2.2.19
	 */
	public function maybe_show_admin_notice() {
		$this->maybe_disable_admin_notice();
		$failed_scheduled_actions = get_option( WC_Subscriptions_Admin::$option_prefix . '_failed_scheduled_actions', array() );

		if ( empty( $failed_scheduled_actions ) ) {
			return;
		}

		$affected_subscription_events = $separator = '';

		foreach ( array_slice( $failed_scheduled_actions, -10, 10 ) as $action ) {

			if ( isset( $action['args']['subscription_id'] ) ) {
				$subject = '<a href="' . get_edit_post_link( $action['args']['subscription_id'] ) . '">#' . $action['args']['subscription_id'] . '</a>';
			} elseif ( isset( $action['args']['order_id'] ) ) {
				$subject = '<a href="' . get_edit_post_link( $action['args']['order_id'] ) . '">#' . $action['args']['order_id'] . '</a>';
			} else {
				$subject = 'unknown';
			}

			$affected_subscription_events .= $separator . $action['type'] . ' for ' . $subject;
			$separator = "\n";
		}?>
		<div class="updated error">
			<p><?php
			// translators: $1: Opening previously translated sentence $2,$5,$9 opening link tags $3 closing link tag $4 opening paragraph tag $6 closing paragraph tag $7 list of affected actions wrapped in code tags $8 the log file name $10 div containing a group of buttons/links
			echo sprintf( esc_html__( '%1$s Please %2$sopen a new ticket at WooCommerce Support%3$s immediately to get this resolved.%4$sTo resolve this error a quickly as possible, please include login details for a %5$stemporary administrator account%3$s.%6$sAffected events: %7$s%4$sTo see further details, view the %8$s log file from the %9$sWooCommerce logs screen.%3$s%6$s%10$s', 'woocommerce-subscriptions' ),
				esc_html( _n( 'An error has occurred while processing a recent subscription related event.', 'An error has occurred while processing recent subscription related events.', count( $failed_scheduled_actions ), 'woocommerce-subscriptions' ) ),
				'<a href="https://woocommerce.com/my-account/marketplace-ticket-form/" target="_blank">',
				'</a>',
				'<p>',
				'<a href="https://docs.woocommerce.com/document/create-new-admin-account-wordpress/" target="_blank">',
				'</p>',
				'<code style="display: block; white-space: pre-wrap">' . wp_kses( $affected_subscription_events, array( 'a' => array( 'href' => array() ) ) ) . '</code>',
				'<code>failed-scheduled-actions</code>',
				'<a href="' . esc_url( admin_url( sprintf( 'admin.php?page=wc-status&tab=logs&log_file=%s-%s-log', 'failed-scheduled-actions', sanitize_file_name( wp_hash( 'failed-scheduled-actions' ) ) ) ) )  . '">',
				'<div style="margin: 5px 0;"><a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( 'wcs_scheduled_action_timeout_error_notice', 'ignore' ), 'wcs_scheduled_action_timeout_error_notice', '_wcsnonce' ) ) . '">' . esc_html__( 'Ignore this error (not recommended!)', 'woocommerce-subscriptions' ) . '</a> <a class="button button-primary" href="https://woocommerce.com/my-account/marketplace-ticket-form/">' . esc_html__( 'Open up a ticket now!', 'woocommerce-subscriptions' ) . '</a></div>'
			);?>
			</p>
		</div><?php
	}

	/**
	 * Handle requests to disable the failed scheduled actions admin notice.
	 *
	 * @since 2.2.19
	 */
	protected function maybe_disable_admin_notice() {
		if ( isset( $_GET['_wcsnonce'] ) && wp_verify_nonce( $_GET['_wcsnonce'], 'wcs_scheduled_action_timeout_error_notice' ) && isset( $_GET['wcs_scheduled_action_timeout_error_notice'] ) ) {
			delete_option( WC_Subscriptions_Admin::$option_prefix . '_failed_scheduled_actions' );
		}
	}

	/**
	 * Retrieve a user friendly description of the scheduled action from the action hook.
	 *
	 * @param string $hook the scheduled action hook
	 * @return string
	 * @since 2.2.19
	 */
	protected function get_action_hook_label( $hook ) {
		return str_replace( array( 'woocommerce_scheduled_', '_' ), array( '', ' ' ), $hook );
	}

	/**
	 * Retrieve a list of scheduled action args as a string.
	 *
	 * @param mixed $args the scheduled action args
	 * @return string
	 * @since 2.2.19
	 */
	protected function get_action_args_string( $args ) {
		$args_string = $separator = '';

		foreach ( $args as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$args_string .= $separator . $key . ': ' . $value;
				$separator    = ', ';
			}
		}

		return $args_string;
	}

	/**
	 * Get a scheduled action object
	 *
	 * @param int $action_id the scheduled action ID
	 * @return ActionScheduler_Action
	 * @since 2.2.19
	 */
	protected function get_action( $action_id ) {
		$store = ActionScheduler_Store::instance();
		return $store->fetch_action( $action_id );
	}
}
