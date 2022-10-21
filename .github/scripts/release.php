<?php

function line( $text = '' ) {
	echo $text, PHP_EOL;
}

function run( $command, &$result_code = null ) {
	line( $command );

	$last_line = system( $command, $result_code );

	line();

	return $last_line;
}

/**
 * WooCommerce Helper API authentication.
 * 
 * @link https://github.com/woocommerce/woocommerce/blob/ca91250b2e17e88c902e460135c0531b3f632d90/plugins/woocommerce/includes/admin/helper/class-wc-helper-api.php#L67-L118
 */
$access_token        = getenv( 'WOOCOMMERCE_HELPER_ACCESS_TOKEN' );
$access_token_secret = getenv( 'WOOCOMMERCE_HELPER_ACCESS_TOKEN_SECRET' );

if ( empty( $access_token ) ) {
	echo 'WooCommerce Helper API acces token not defined in `WOOCOMMERCE_HELPER_ACCESS_TOKEN` environment variable.';

	exit( 1 );
}

if ( empty( $access_token_secret ) ) {
	echo 'WooCommerce Helper API acces token secret not defined in `WOOCOMMERCE_HELPER_ACCESS_TOKEN_SECRET` environment variable.';

	exit( 1 );
}

// Subscriptions.
$url = 'https://woocommerce.com/wp-json/helper/1.0/subscriptions';

$data = array(
	'host'        => parse_url( $url, PHP_URL_HOST ),
	'request_uri' => parse_url( $url, PHP_URL_PATH ),
	'method'      => 'GET',
);

$signature = hash_hmac( 'sha256', json_encode( $data ), $access_token_secret );

$url .= '?' . http_build_query(
	[ 
		'token'     => $access_token,
		'signature' => $signature,
	]
);

$command = "curl -X GET '$url' -H 'Authorization: Bearer $access_token' -H 'X-Woo-Signature: $signature';";

run( $command );

// Check
$payload = [
	27147 => [
		'product_id' => 27147,
		'file_id'    => '',
	],
];

ksort( $payload );

$body = json_encode( array( 'products' => $payload ) );

$url = 'https://woocommerce.com/wp-json/helper/1.0/update-check';

$data = array(
	'host'        => parse_url( $url, PHP_URL_HOST ),
	'request_uri' => parse_url( $url, PHP_URL_PATH ),
	'method'      => 'POST',
	'body'        => $body,
);

$signature = hash_hmac( 'sha256', json_encode( $data ), $access_token_secret );

$url .= '?' . http_build_query(
	[ 
		'token'     => $access_token,
		'signature' => $signature,
	]
);

$command = "curl -d '$body' -X POST '$url' -H 'Authorization: Bearer $access_token' -H 'X-Woo-Signature: $signature';";

run( $command );
