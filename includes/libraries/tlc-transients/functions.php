<?php

// API so you don't have to use "new"
if ( !function_exists( 'tlc_transient' ) ) {
	function tlc_transient( $key ) {
		$transient = new TLC_Transient( $key );
		return $transient;
	}
}