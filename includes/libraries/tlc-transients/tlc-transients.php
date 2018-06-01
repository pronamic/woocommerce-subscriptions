<?php

if ( ! class_exists( 'TLC_Transient_Update_Server' ) )
	require_once dirname( __FILE__ ) . '/class-tlc-transient-update-server.php';

new TLC_Transient_Update_Server;

if ( ! class_exists( 'TLC_Transient' ) )
	require_once dirname( __FILE__ ) . '/class-tlc-transient.php';

require_once dirname( __FILE__ ) . '/functions.php';

// Example:
/*
function sample_fetch_and_append( $url, $append ) {
	$f  = wp_remote_retrieve_body( wp_remote_get( $url, array( 'timeout' => 30 ) ) );
	$f .= $append;
	return $f;
}

function test_tlc_transient() {
	$t = tlc_transient( 'foo' )
		->expires_in( 30 )
		->background_only()
		->updates_with( 'sample_fetch_and_append', array( 'http://coveredwebservices.com/tools/long-running-request.php', ' appendfooparam ' ) )
		->get();
	var_dump( $t );
	if ( !$t )
		echo "The request is false, because it isn't yet in the cache. It'll be there in about 10 seconds. Keep refreshing!";
}

add_action( 'wp_footer', 'test_tlc_transient' );
*/
