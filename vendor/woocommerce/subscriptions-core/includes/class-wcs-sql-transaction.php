<?php
/**
 * A SQL Transaction Handler to assist with starting, commiting and rolling back transactions.
 * This class also closes off an active transaction before shutdown to allow for shutdown processes to write to the database.
 *
 * @package  WooCommerce Subscriptions
 * @category Class
 * @author   Automattic
 * @since    3.1.0
 */

defined( 'ABSPATH' ) || exit;

class WCS_SQL_Transaction {

	/**
	 * The query to run when a fatal shutdown occurs.
	 *
	 * @var string
	 */
	public $on_fatal = '';

	/**
	 * The query to run if the PHP request ends without error.
	 *
	 * @var string
	 */
	public $on_shutdown = '';

	/**
	 * Whether there's an active MYSQL transaction.
	 *
	 * @var bool
	 */
	public $active_transaction = false;

	/**
	 * Constructor
	 *
	 * @since 3.1.0
	 *
	 * @param string $on_fatal    Optional. The type of query to run on fatal shutdown if this transaction is still active. Can be 'rollback' or 'commit'. Default is 'rollback'.
	 * @param string $on_shutdown Optional. The type of query to run if a non-error shutdown occurs but there's still an active transaction. Can be 'rollback' or 'commit'. Default is 'commit'.
	 */
	public function __construct( $on_fatal = 'rollback', $on_shutdown = 'commit' ) {

		// Validate the $on_fatal and $on_shutdown parameters.
		if ( 'commit' !== $on_fatal && 'rollback' !== $on_fatal ) {
			wcs_doing_it_wrong( __METHOD__, 'This method was called with an invalid parameter. The first argument ($on_fatal) should be "rollback" or "commit"', '3.0.10' );
		}

		if ( 'commit' !== $on_shutdown && 'rollback' !== $on_shutdown ) {
			wcs_doing_it_wrong( __METHOD__, 'This method was called with an invalid parameter. The second argument ($on_shutdown) should be "rollback" or "commit"', '3.0.10' );
		}

		$this->on_fatal    = $on_fatal;
		$this->on_shutdown = $on_shutdown;

		// Ensure we close off this transaction on shutdown to allow other shutdown processes to save changes to the DB.
		add_action( 'shutdown', array( $this, 'handle_shutdown' ), -100 );
	}

	/**
	 * Starts a MYSQL Transction.
	 *
	 * @since 3.1.0
	 */
	public function start() {
		wc_transaction_query( 'start' );
		$this->active_transaction = true;
	}

	/**
	 * Commits the MYSQL Transction.
	 *
	 * @since 3.1.0
	 */
	public function commit() {
		wc_transaction_query( 'commit' );
		$this->active_transaction = false;
	}

	/**
	 * Rolls back any changes made during the MYSQL Transction.
	 *
	 * @since 3.1.0
	 */
	public function rollback() {
		wc_transaction_query( 'rollback' );
		$this->active_transaction = false;
	}

	/**
	 * Closes out an active transaction depending on the type of shutdown.
	 *
	 * Shutdowns caused by a fatal will be rolledback or commited @see $this->on_fatal.
	 * Shutdowns caused by a natural PHP termination (no error) will be rolledback or commited. @see $this->on_shutdown.
	 *
	 * @since 3.1.0
	 */
	public function handle_shutdown() {

		if ( ! $this->active_transaction ) {
			return;
		}

		$error = error_get_last();

		if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			$this->{$this->on_fatal}();
		} else {
			$this->{$this->on_shutdown}();
		}
	}
}
