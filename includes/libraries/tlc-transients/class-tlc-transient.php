<?php

class TLC_Transient {
	public $key;
	public $raw_key;
	private $lock;
	private $callback;
	private $params;
	private $expiration = 0;
	private $extend_on_fail = 0;
	private $force_background_updates = false;

	public function __construct( $key ) {
		$this->raw_key = $key;
		$this->key     = md5( $key );
	}

	public function get() {
		$data = $this->raw_get();
		if ( false === $data ) {
			// Hard expiration
			if ( $this->force_background_updates ) {
				// In this mode, we never do a just-in-time update
				// We return false, and schedule a fetch on shutdown
				$this->schedule_background_fetch();
				return false;
			}
			else {
				// Bill O'Reilly mode: "We'll do it live!"
				return $this->fetch_and_cache();
			}
		}
		else {
			// Soft expiration
			if ( $data[0] !== 0 && $data[0] < time() )
				$this->schedule_background_fetch();
			return $data[1];
		}
	}

	private function raw_get() {
		return get_transient( 'tlc__' . $this->key );
	}

	private function schedule_background_fetch() {
		if ( ! $this->has_update_lock() ) {
			set_transient( 'tlc_up__' . $this->key, array( $this->new_update_lock(), $this->raw_key, $this->expiration, $this->callback, $this->params, $this->extend_on_fail ), 300 );
			add_action( 'shutdown', array( $this, 'spawn_server' ) );
		}
		return $this;
	}

	private function has_update_lock() {
		return (bool) $this->get_update_lock();
	}

	private function get_update_lock() {
		$lock = get_transient( 'tlc_up__' . $this->key );
		if ( $lock )
			return $lock[0];
		else
			return false;
	}

	private function new_update_lock() {
		$this->lock = uniqid( 'tlc_lock_', true );
		return $this->lock;
	}

	public function fetch_and_cache() {
		// If you don't supply a callback, we can't update it for you!
		if ( empty( $this->callback ) )
			return false;
		if ( $this->has_update_lock() && ! $this->owns_update_lock() )
			return; // Race... let the other process handle it
		try {
			$data = call_user_func_array( $this->callback, $this->params );
			$this->set( $data );
		} catch ( Exception $e ) {
			if ( $this->extend_on_fail > 0 ) {
				$data = $this->raw_get();
				if ( $data ) {
					$data             = $data[1];
					$old_expiration   = $this->expiration;
					$this->expiration = $this->extend_on_fail;
					$this->set( $data );
					$this->expiration = $old_expiration;
				}
			}
			else {
				$data = false;
			}
		}
		$this->release_update_lock();
		return $data;
	}

	private function owns_update_lock() {
		return $this->lock == $this->get_update_lock();
	}

	public function set( $data ) {
		// We set the timeout as part of the transient data.
		// The actual transient has a far-future TTL. This allows for soft expiration.
		$expiration           = ( $this->expiration > 0 ) ? time() + $this->expiration : 0;
		$transient_expiration = ( $this->expiration > 0 ) ? $this->expiration + 31536000 : 0; // 31536000 = 60*60*24*365 ~= one year
		set_transient( 'tlc__' . $this->key, array( $expiration, $data ), $transient_expiration );
		return $this;
	}

	private function release_update_lock() {
		delete_transient( 'tlc_up__' . $this->key );
	}

	public function spawn_server() {
		$server_url = home_url( '/?tlc_transients_request' );
		wp_remote_post( $server_url, array( 'body' => array( '_tlc_update' => $this->lock, 'key' => $this->raw_key ), 'timeout' => 0.01, 'blocking' => false, 'sslverify' => apply_filters( 'https_local_ssl_verify', true ) ) );
	}

	public function updates_with( $callback, $params = array() ) {
		$this->callback = $callback;
		if ( is_array( $params ) )
			$this->params = $params;
		return $this;
	}

	public function expires_in( $seconds ) {
		$this->expiration = (int) $seconds;
		return $this;
	}

	public function extend_on_fail( $seconds ) {
		$this->extend_on_fail = (int) $seconds;
		return $this;
	}

	public function set_lock( $lock ) {
		$this->lock = $lock;
		return $this;
	}

	public function background_only() {
		$this->force_background_updates = true;
		return $this;
	}
}