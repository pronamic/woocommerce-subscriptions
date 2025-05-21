<?php
/**
 * Failed Scheduled Action Manager for subscription events
 *
 * @version   1.0.0 - Migrated from WooCommerce Subscriptions v2.2.19
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
		'woocommerce_scheduled_subscription_trial_end'     => 1,
		'woocommerce_scheduled_subscription_payment'       => 1,
		'woocommerce_scheduled_subscription_payment_retry' => 1,
		'woocommerce_scheduled_subscription_expiration'    => 1,
		'woocommerce_scheduled_subscription_end_of_prepaid_term' => 1,
	);

	/**
	 * WC Logger instance for logging messages.
	 *
	 * @var WC_Logger
	 */
	protected $logger;

	/**
	 * Exceptions caught by WC while this class is listening to the `woocommerce_caught_exception` action.
	 *
	 * @var Exception[]
	 */
	protected $exceptions = [];

	/**
	 * Constructor.
	 *
	 * @param WC_Logger_Interface $logger The WC Logger instance.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.19
	 */
	public function __construct( WC_Logger_Interface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Attach callbacks.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.19
	 */
	public function init() {
		add_action( 'action_scheduler_failed_action', array( $this, 'log_action_scheduler_failure' ), 10, 2 );
		add_action( 'action_scheduler_failed_execution', array( $this, 'log_action_scheduler_failure' ), 10, 2 );
		add_action( 'action_scheduler_unexpected_shutdown', array( $this, 'log_action_scheduler_failure' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'maybe_show_admin_notice' ) );
		add_action( 'action_scheduler_begin_execute', array( $this, 'maybe_attach_exception_listener' ) );
	}

	/**
	 * Log a message to the failed-scheduled-actions log.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.19
	 *
	 * @param string $message the message to be written to the log.
	 * @param array  $context the context to be included in the log. Optional. Default is an empty array.
	 */
	protected function log( $message, $context = [] ) {
		$context['source'] = 'failed-scheduled-actions';
		wc_get_logger()->error( $message, $context );
	}

	/**
	 * When a scheduled action failure is triggered, log information about the failed action to a WC logger.
	 *
	 * @param int                 $action_id The ID of the action which failed.
	 * @param int|Exception|array $error The number of seconds an action timeouts out after or the exception/error that caused the error/shutdown.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.19
	 */
	public function log_action_scheduler_failure( $action_id, $error ) {
		$action = $this->get_action( $action_id );

		if ( ! isset( $this->tracked_scheduled_actions[ $action->get_hook() ] ) ) {
			return;
		}

		$subscription_action = $this->get_action_hook_label( $action->get_hook() );
		$context             = $this->get_context_from_action_error( $action, $error );

		switch ( current_filter() ) {
			case 'action_scheduler_failed_action':
				$this->log( sprintf( 'scheduled action %s (%s) failed to finish processing after %s seconds', $action_id, $subscription_action, absint( $error ) ), $context );
				break;
			case 'action_scheduler_failed_execution':
				$this->log( sprintf( 'scheduled action %s (%s) failed to finish processing due to the following exception: %s', $action_id, $subscription_action, $this->get_message_from_exception( $error ) ), $context );
				break;
			case 'action_scheduler_unexpected_shutdown':
				$this->log( sprintf( 'scheduled action %s (%s) failed to finish processing due to the following error: %s', $action_id, $subscription_action, $this->get_message_from_error( $error ) ), $context );
				break;
		}

		// Prior to WC 8.6 the logger didn't display the context inline with the message so on those versions we log the context separately.
		if ( wcs_is_woocommerce_pre( '8.6' ) ) {
			foreach ( $context as $key => $value ) {
				if ( is_array( $value ) ) {
					$value = implode( PHP_EOL, $value );
				}

				$this->log( "{$key}: {$value}" );
			}
		}

		// Log any exceptions caught by the exception listener in action logs.
		if ( ! empty( $context['exceptions'] ) ) {
			foreach ( $context['exceptions'] as $exception_message ) {
				ActionScheduler_Logger::instance()->log( $action_id, $exception_message );
			}
		}

		// Now that we've logged the exceptions, we can detach the exception listener.
		$this->clear_exceptions_and_detach_listener();

		// Store information about the scheduled action for displaying an admin notice
		$failed_scheduled_actions = get_option( WC_Subscriptions_Admin::$option_prefix . '_failed_scheduled_actions', array() );

		$failed_scheduled_actions[ $action_id ] = array(
			'args' => $action->get_args(),
			'type' => $subscription_action,
		);

		update_option( WC_Subscriptions_Admin::$option_prefix . '_failed_scheduled_actions', $failed_scheduled_actions );
	}

	/**
	 * Creates a new exception listener when processing subscription-related scheduled actions.
	 *
	 * @param int $action_id The ID of the scheduled action being ran.
	 */
	public function maybe_attach_exception_listener( $action_id ) {
		$action = $this->get_action( $action_id );

		if ( ! $action || ! isset( $this->tracked_scheduled_actions[ $action->get_hook() ] ) ) {
			return;
		}

		// Add an action to detach the exception listener and clear the caught exceptions after the scheduled action has been executed.
		add_action( 'action_scheduler_after_execute', [ $this, 'clear_exceptions_and_detach_listener' ] );

		// Attach the exception listener.
		add_action( 'woocommerce_caught_exception', [ $this, 'handle_exception' ] );
	}

	/**
	 * Adds an exception to the list of exceptions caught by WC.
	 *
	 * @param Exception $exception The exception that was caught.
	 */
	public function handle_exception( $exception ) {
		$this->exceptions[] = $exception;
	}

	/**
	 * Clears the list of exceptions caught by WC and detaches the listener.
	 *
	 * This function is called directly and attached to an action that runs after a scheduled action has finished being executed.
	 */
	public function clear_exceptions_and_detach_listener() {
		$this->exceptions = [];
		remove_action( 'woocommerce_caught_exception', [ $this, 'handle_exception' ] );
	}

	/**
	 * Display an admin notice when a scheduled action failure has occurred.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.19
	 */
	public function maybe_show_admin_notice() {

		// Responding to this notice requires investigating subscriptions and scheduled actions so only display it to users who can manage woocommerce.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$this->maybe_disable_admin_notice();
		$failed_scheduled_actions = get_option( WC_Subscriptions_Admin::$option_prefix . '_failed_scheduled_actions', array() );

		if ( empty( $failed_scheduled_actions ) ) {
			return;
		}

		$affected_subscription_events = $separator = '';

		foreach ( array_slice( $failed_scheduled_actions, -10, 10 ) as $action ) {
			$id = false;

			if ( isset( $action['args']['subscription_id'] ) && wcs_is_subscription( $action['args']['subscription_id'] ) ) {
				$id = $action['args']['subscription_id'];
			} elseif ( isset( $action['args']['order_id'] ) && wc_get_order( $action['args']['order_id'] ) ) {
				$id = $action['args']['order_id'];
			}

			if ( $id ) {
				$subject = '<a href="' . wcs_get_edit_post_link( $id ) . '">#' . $id . '</a>';
			} else {
				$subject = 'unknown';
			}

			$affected_subscription_events .= $separator . $action['type'] . ' for ' . $subject;
			$separator = "\n";
		}

		$notice = new WCS_Admin_Notice( 'error' );
		$notice->set_content_template( 'html-failed-scheduled-action-notice.php', WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'templates/admin/' ), array(
			'failed_scheduled_actions'     => $failed_scheduled_actions,
			'affected_subscription_events' => $affected_subscription_events,
		) );
		$notice->set_actions( array(
			array(
				'name'  => __( 'Ignore this error', 'woocommerce-subscriptions' ),
				'url'   => wp_nonce_url( add_query_arg( 'wcs_scheduled_action_timeout_error_notice', 'ignore' ), 'wcs_scheduled_action_timeout_error_notice', '_wcsnonce' ),
				'class' => 'button',
			),
			array(
				'name'  => __( 'Learn more', 'woocommerce-subscriptions' ),
				'url'   => 'https://woocommerce.com/document/subscriptions/scheduled-action-errors/',
				'class' => 'button button-primary',
			),
		) );
		$notice->display();
	}

	/**
	 * Handle requests to disable the failed scheduled actions admin notice.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.19
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
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.19
	 */
	protected function get_action_hook_label( $hook ) {
		return str_replace( array( 'woocommerce_scheduled_', '_' ), array( '', ' ' ), $hook );
	}

	/**
	 * Retrieve a list of scheduled action args as a string.
	 *
	 * @param mixed $args the scheduled action args
	 * @return string
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.19
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
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.19
	 */
	protected function get_action( $action_id ) {
		$store = ActionScheduler_Store::instance();
		return $store->fetch_action( $action_id );
	}

	/**
	 * Generates a message from an exception.
	 *
	 * @param Exception $exception The exception to generate a message from.
	 * @return string The message.
	 */
	protected function get_message_from_exception( $exception ) {
		// When Action Scheduler throws an exception, it wraps the original exception in a new Exception. Information about the actual error is stored in the previous exception.
		$previous  = $exception->getPrevious();
		$exception = $previous ? $previous : $exception;

		return $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine();
	}

	/**
	 * Generates a message from an error array.
	 *
	 * The $error variable is obtained from get_last_error() and has standard keys message, file and line.
	 *
	 * @param array $error The error data to generate a message from.
	 * @return string The message including the file and line number if available.s
	 */
	protected function get_message_from_error( $error ) {
		$message = $error['message'];

		if ( isset( $error['file'] ) ) {
			$message .= " in {$error['file']}";

			if ( isset( $error['line'] ) ) {
				$message .= ":{$error['line']}";
			}
		}

		return $message;
	}

	/**
	 * Generates the additional context data that will be recorded with the error log entry.
	 * The context includes the action args, a backtrace and any exception messages caught.
	 *
	 * @param ActionScheduler_Action $action The ActionScheduler_Action that failed.
	 * @param int|Exception|array    $error  The error data that caused the failure.
	 */
	protected function get_context_from_action_error( $action, $error ) {
		$context = [
			'action_args' => $this->get_action_args_string( $action->get_args() ),
		];

		if ( is_a( $error, 'Exception' ) ) {
			// Action scheduler has a nested a try-catch block and so the original caught exception is stored in the previous exception.
			$previous_exception     = $error->getPrevious();
			$context['error_trace'] = $previous_exception ? $previous_exception->getTraceAsString() : $error->getTraceAsString();
		}

		// If there is an exception listener and it has caught exceptions, log them for additional debugging.
		if ( ! empty( $this->exceptions ) ) {
			foreach ( $this->exceptions as $exception ) {
				$context['exceptions'][] = $this->get_message_from_exception( $exception );
			}
		}

		return $context;
	}
}
