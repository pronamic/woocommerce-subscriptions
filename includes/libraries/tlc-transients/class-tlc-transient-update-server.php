<?php

class TLC_Transient_Update_Server {
	public function __construct() {
		add_action( 'init', array( $this, 'init' ), 9999 );
	}

	public function init() {
		if ( isset( $_POST['_tlc_update'] )
				&& ( 0 === strpos( $_POST['_tlc_update'], 'tlc_lock_' ) )
				&& isset( $_POST['key'] )
		) {
			$update = get_transient( 'tlc_up__' . md5( $_POST['key'] ) );
			if ( $update && $update[0] == $_POST['_tlc_update'] ) {
				tlc_transient( $update[1] )
						->expires_in( $update[2] )
						->extend_on_fail( $update[5] )
						->updates_with( $update[3], (array) $update[4] )
						->set_lock( $update[0] )
						->fetch_and_cache();
			}
			exit();
		}
	}
}