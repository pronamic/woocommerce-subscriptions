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

$product_id = 27147;

if ( empty( $access_token ) ) {
	echo 'WooCommerce Helper API acces token not defined in `WOOCOMMERCE_HELPER_ACCESS_TOKEN` environment variable.';

	exit( 1 );
}

if ( empty( $access_token_secret ) ) {
	echo 'WooCommerce Helper API acces token secret not defined in `WOOCOMMERCE_HELPER_ACCESS_TOKEN_SECRET` environment variable.';

	exit( 1 );
}

/**
 * Request info.
 */
line( '::group::Check WooCommerce.com' );

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
	$product_id => [
		'product_id' => $product_id,
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

$data = run(
	sprintf(
		'curl --data %s --request POST %s --header %s --header %s',
		escapeshellarg( $body ),
		escapeshellarg( $url ),
		escapeshellarg( 'Authorization: Bearer ' . $access_token ),
		escapeshellarg( 'X-Woo-Signature: ' . $signature )
	)
);

$result = json_decode( $data );

if ( ! is_object( $result ) ) {
	throw new Exception(
		sprintf(
			'Unknow response from: %s.',
			$url 
		)
	);

	exit( 1 );
}

if ( ! property_exists( $result, $product_id ) ) {
	printf(
		'No update information for product ID: %s.',
		$product_id
	);

	exit( 1 );
}

$update_data = $result->{$product_id};

$version = $update_data->version;
$zip_url = $update_data->package;

line(
	sprintf(
		'WooCommerce Subscriptions Version: %s',
		$version
	)
);

line(
	sprintf(
		'WooCommerce Subscriptions ZIP URL: %s',
		$zip_url
	)
);

line( '::endgroup::' );

/**
 * Files.
 */
$work_dir = tempnam( sys_get_temp_dir(), '' );

unlink( $work_dir );

mkdir( $work_dir );

$archives_dir = $work_dir . '/archives';
$plugins_dir  = $work_dir . '/plugins';

mkdir( $archives_dir );
mkdir( $plugins_dir );

$plugin_dir = $plugins_dir . '/woocommerce-subscriptions';

$zip_file = $archives_dir . '/woocommerce-subscriptions-' . $version . '.zip';

/**
 * Download ZIP.
 */
line( '::group::Download WooCommerce Subscriptions' );

run(
	sprintf(
		'curl %s --output %s',
		escapeshellarg( $zip_url ),
		$zip_file
	)
);

line( '::endgroup::' );

/**
 * Unzip.
 */
line( '::group::Unzip WooCommerce Subscriptions' );

run(
	sprintf(
		'unzip %s -d %s',
		escapeshellarg( $zip_file ),
		escapeshellarg( $plugins_dir )
	)
);

line( '::endgroup::' );

/**
 * Synchronize.
 * 
 * @link http://stackoverflow.com/a/14789400
 * @link http://askubuntu.com/a/476048
 */
line( '::group::Synchronize WooCommerce Subscriptions' );

run(
	sprintf(
		'rsync --archive --delete-before --exclude=%s --exclude=%s --exclude=%s --verbose %s %s',
		escapeshellarg( '.git' ),
		escapeshellarg( '.github' ),
		escapeshellarg( 'composer.json' ),
		escapeshellarg( $plugin_dir . '/' ),
		escapeshellarg( '.' )
	)
);

line( '::endgroup::' );

/**
 * Git user.
 * 
 * @link https://github.com/roots/wordpress/blob/13ba8c17c80f5c832f29cf4c2960b11489949d5f/bin/update-repo.php#L62-L67
 */
run(
	sprintf(
		'git config user.email %s',
		escapeshellarg( 'info@woocommerce.com' )
	)
);

run(
	sprintf(
		'git config user.name %s',
		escapeshellarg( 'WooCommerce' )
	)
);

/**
 * Git commit.
 * 
 * @link https://git-scm.com/docs/git-commit
 */
run( 'git add --all' );

run(
	sprintf(
		'git commit --all -m %s',
		escapeshellarg(
			sprintf(
				'Updates to %s',
				$version
			)
		)
	)
);

run( 'git config --unset user.email' );
run( 'git config --unset user.name' );

run( 'gh auth status' );

run( 'git push origin main' );

/**
 * GitHub release view.
 */
$tag = 'v' . $version;

run(
	sprintf(
		'gh release view %s',
		$tag
	),
	$result_code
);

$release_not_found = ( 1 === $result_code );

/**
 * GitHub release.
 *
 * @link https://cli.github.com/manual/gh_release_create
 */
if ( $release_not_found ) {
	run(
		sprintf(
			'gh release create %s %s --title %s',
			$tag,
			$zip_file,
			$version
		)
	);
}
