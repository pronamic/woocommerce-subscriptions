<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Utilities;

/**
 * Utilities for handling request-related operations, including test-safe redirects and exits.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Request {
	/**
	 * Cached POST input variables.
	 *
	 * @var array|null
	 */
	private static ?array $post_vars = null;

	/**
	 * Cached GET input variables.
	 *
	 * @var array|null
	 */
	private static ?array $url_vars = null;

	/**
	 * Exit unless running in test environment.
	 *
	 * This method allows tests to run without terminating the PHP process,
	 * enabling proper code coverage collection and test assertions.
	 * In production, this behaves exactly like exit().
	 *
	 * @since 8.3.0
	 * @return void
	 */
	public static function exit() {
		if ( defined( 'WCS_ENVIRONMENT_TYPE' ) && 'tests' === WCS_ENVIRONMENT_TYPE ) {
			return;
		}
		exit();
	}

	/**
	 * Safe redirect that works in test environment.
	 *
	 * In production, this performs a normal wp_safe_redirect() and exits by default.
	 * In tests, it stores the redirect information in a global variable for assertions
	 * instead of attempting to send headers (which would fail).
	 *
	 * @since 8.3.0
	 * @param string $location    The URL to redirect to.
	 * @param int    $status      HTTP status code (default 302).
	 * @param bool   $should_exit Whether to exit after setting redirect headers (default true).
	 * @return void
	 */
	public static function redirect( $location, $status = 302, $should_exit = true ) {
		if ( defined( 'WCS_ENVIRONMENT_TYPE' ) && 'tests' === WCS_ENVIRONMENT_TYPE ) {
			// Store redirect info for test assertions but don't actually redirect
			$GLOBALS['wcs_test_redirect'] = array(
				'location' => $location,
				'status'   => $status,
			);
			return;
		}
		wp_safe_redirect( $location, $status );

		if ( $should_exit ) {
			self::exit();
		}
	}

	/**
	 * Best-effort decouple the PHP process from the client connection so subsequent work can run after the
	 * response has been sent. Useful in shutdown-time handlers and other "respond fast, then keep working"
	 * patterns (for example, the external-trigger endpoint dispatches its queue run after calling this).
	 *
	 * Layered behaviour:
	 *
	 *  - {@see ignore_user_abort()} is set unconditionally so the PHP process keeps running even if the
	 *    client times out and disconnects. This is the most important guarantee for our use cases — the
	 *    work runs to completion regardless of what the client does.
	 *  - {@see session_write_close()} is called unconditionally to release the session lock (if any was
	 *    held). No-op when no session is active; defensive coverage for the case where a plugin has
	 *    silently started one.
	 *  - {@see fastcgi_finish_request()} (FPM) or {@see litespeed_finish_request()} (LSAPI) — preferred,
	 *    cleanly closes the FastCGI / LSAPI connection while leaving the PHP process alive.
	 *  - mod_php / CGI fallback — flush whatever's in the output buffer. Some setups (reverse proxy, gzip
	 *    compression, etc.) will continue to buffer; we can't fix those from a shutdown handler. The work
	 *    runs to completion regardless thanks to ignore_user_abort() above.
	 *
	 * @since 8.8.0
	 *
	 * @return void
	 */
	public static function release_client(): void {
		ignore_user_abort( true );
		session_write_close();

		// Production-side-effects path. Skipped under test to avoid (a) the fallback flush closing
		// PHPUnit's own output buffers, which trips the "did not close its own buffers" risky-test
		// flag, and (b) interfering with whatever SAPI / response flow the test harness is using.
		// Matches the gating pattern in {@see exit()} and {@see redirect()}.
		if ( defined( 'WCS_ENVIRONMENT_TYPE' ) && 'tests' === WCS_ENVIRONMENT_TYPE ) {
			return;
		}

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
			return;
		}

		if ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
			return;
		}

		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		flush();
	}

	/**
	 * Supplies the value of the POST or URL query parameter matching $key, or else returns $default.
	 *
	 * Essentially, this is an alternative to inspecting the $_REQUEST super-global and is intended for cases where we
	 * are interested in a key:value pair, regardless of whether it was sent as a post var or URL query var.
	 *
	 * Its advantages are that it only ever examines the POST and GET inputs (POST taking priority, if both contain the
	 * same key): cookies are always ignored. It also looks directly at the inputs, instead of using the $_POST or $_GET
	 * superglobals (which can be manipulated).
	 *
	 * @since 8.3.0
	 *
	 * @param string $key           The key to look up.
	 * @param mixed  $default_value The value to return if the key is not found. Defaults to null.
	 *
	 * @return mixed
	 */
	public static function get_var( string $key, $default_value = null ) {
		if ( null === self::$post_vars ) {
			self::$post_vars = filter_input_array( INPUT_POST ) ?? array();
			self::$url_vars  = filter_input_array( INPUT_GET ) ?? array();
		}

		if ( isset( self::$post_vars[ $key ] ) ) {
			return self::$post_vars[ $key ];
		} elseif ( isset( self::$url_vars[ $key ] ) ) {
			return self::$url_vars[ $key ];
		}

		return $default_value;
	}
}
