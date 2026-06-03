<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\CLI\Test_Data;

use WP_CLI;

/**
 * WP-CLI command for generating health-check test-case subscriptions.
 *
 * Local / development use only. See docs/dev-tools/generate-subscriptions.md for the user-
 * facing reference and docs/health-check/test-cases.md for the canonical specification of
 * each case.
 *
 * Generates one subscription per `--case` invocation, in the exact shape the corresponding
 * RemediationAdvisor case expects.
 *
 * Safety properties enforced by this command:
 *  - Refuses to run unless WP_ENVIRONMENT_TYPE is 'local' or 'development'.
 *  - Short-circuits pre_wp_mail so no outbound mail leaves the process during the command.
 *
 * @since   x.x.x
 * @internal This class may be modified, moved or removed in future releases.
 */
class Generate_Command {

	const VALID_FORMATS = array( 'table', 'ids', 'csv', 'json' );

	/**
	 * Generate test subscriptions matching a Health Check advisor case.
	 *
	 * ## OPTIONS
	 *
	 * --case=<slug>
	 * : Health Check case to generate. Each slug maps 1:1 to a `RemediationAdvisor` case
	 *   constant; see `docs/health-check/test-cases.md` for the full spec. Pass `all` to
	 *   generate `--count` of every supported case.
	 * ---
	 * options:
	 *   - s1a
	 *   - s1b
	 *   - s2a
	 *   - s2b
	 *   - all
	 * ---
	 *
	 * --count=<number>
	 * : Number of subscriptions to generate.
	 *
	 * [--customer=<id_or_email>]
	 * : Existing WP user ID or email to assign all generated subscriptions to.
	 *
	 * [--product=<id>]
	 * : Existing subscription product ID. A test product is created when omitted.
	 *
	 * --payment-method=<gateway>
	 * : Registered payment gateway ID (e.g. `stripe`, `bacs`).
	 *
	 * [--dry-run]
	 * : Print the resolved configuration without writing anything.
	 *
	 * [--format=<fmt>]
	 * : Output format for the summary.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - ids
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # One stuck-on-manual sub for the Eligible-for-automatic-renewal tab.
	 *     wp wc-subs generate --case=s1a --count=1 --payment-method=stripe
	 *
	 *     # Stuck-on-manual with a failed renewal order.
	 *     wp wc-subs generate --case=s1b --count=1 --payment-method=stripe
	 *
	 *     # Active sub with no next-payment date and no end date.
	 *     wp wc-subs generate --case=s2a --count=3 --payment-method=stripe
	 *
	 *     # Past-due sub with no matching renewal order.
	 *     wp wc-subs generate --case=s2b --count=1 --payment-method=stripe --customer=qa@example.test
	 *
	 *     # One subscription of every supported case.
	 *     wp wc-subs generate --case=all --count=1 --payment-method=stripe
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( $args, $assoc_args ) {
		$this->assert_safe_environment();
		$this->suppress_mail();

		$config = $this->parse_args( $assoc_args );

		if ( $config['dry_run'] ) {
			$this->print_dry_run( $config );
			return;
		}

		$this->run_generation( $config );
	}

	/**
	 * Instantiate the generator and drive it count times, then render the summary.
	 *
	 * @param array $config Normalised config from parse_args().
	 */
	private function run_generation( array $config ) {
		$cases   = 'all' === $config['case'] ? Generator::SUPPORTED_CASES : array( $config['case'] );
		$results = array();

		$progress = \WP_CLI\Utils\make_progress_bar( 'Generating subscriptions', $config['count'] * count( $cases ) );
		foreach ( $cases as $case ) {
			$generator = new Generator( array_merge( $config, array( 'case' => $case ) ) );
			for ( $i = 0; $i < $config['count']; $i++ ) {
				$results[] = $generator->generate_one();
				$progress->tick();
			}
		}
		$progress->finish();

		$this->render_results( $results, $config['format'] );
	}

	/**
	 * Format the list of generated records for output.
	 *
	 * @param array  $results List of per-subscription result rows.
	 * @param string $format  One of table, ids, csv, json.
	 */
	private function render_results( array $results, $format ) {
		if ( empty( $results ) ) {
			return;
		}

		if ( 'ids' === $format ) {
			WP_CLI::log( implode( ' ', wp_list_pluck( $results, 'subscription_id' ) ) );
			return;
		}

		$fields = array_keys( $results[0] );
		\WP_CLI\Utils\format_items( $format, $results, $fields );
	}

	/**
	 * Abort unless the current WP environment is suitable for running the command.
	 */
	private function assert_safe_environment() {
		$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

		if ( in_array( $env, array( 'local', 'development' ), true ) ) {
			return;
		}

		WP_CLI::error(
			sprintf(
				"Refusing to run in environment '%s'. This command is only supported when WP_ENVIRONMENT_TYPE is 'local' or 'development'.",
				$env
			)
		);
	}

	/**
	 * Neutralise outbound mail for the duration of this CLI invocation.
	 *
	 * Short-circuits pre_wp_mail with PHP_INT_MAX priority so nothing can override it from a later filter.
	 * No restoration is needed: WP-CLI commands run in a short-lived PHP process.
	 */
	private function suppress_mail() {
		add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
	}

	/**
	 * Parse, validate, and normalise the CLI flags into a config array.
	 *
	 * @param array $assoc_args Associative CLI arguments.
	 * @return array Normalised config.
	 */
	private function parse_args( array $assoc_args ) {
		$valid_cases = array_merge( Generator::SUPPORTED_CASES, array( 'all' ) );
		if ( empty( $assoc_args['case'] ) ) {
			WP_CLI::error( '--case is required. Valid slugs: ' . implode( ', ', $valid_cases ) );
		}
		$case = strtolower( (string) $assoc_args['case'] );
		if ( ! in_array( $case, $valid_cases, true ) ) {
			WP_CLI::error(
				sprintf(
					'Invalid --case "%s". Valid slugs: %s.',
					$assoc_args['case'],
					implode( ', ', $valid_cases )
				)
			);
		}

		$count = isset( $assoc_args['count'] ) ? (int) $assoc_args['count'] : 0;
		if ( $count < 1 ) {
			WP_CLI::error( '--count must be a positive integer.' );
		}

		$customer = isset( $assoc_args['customer'] ) ? $assoc_args['customer'] : null;

		$product = null;
		if ( isset( $assoc_args['product'] ) ) {
			$product = (int) $assoc_args['product'];
			if ( $product < 1 ) {
				WP_CLI::error( '--product must be a positive integer product ID.' );
			}
		}

		if ( empty( $assoc_args['payment-method'] ) ) {
			WP_CLI::error( '--payment-method is required. Pass a registered gateway ID (e.g. stripe, bacs).' );
		}
		$payment_method = $assoc_args['payment-method'];

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		if ( ! in_array( $format, self::VALID_FORMATS, true ) ) {
			WP_CLI::error( sprintf( 'Invalid --format "%s". Valid values: %s.', $format, implode( ', ', self::VALID_FORMATS ) ) );
		}

		return array(
			'case'           => $case,
			'count'          => $count,
			'customer'       => $customer,
			'product'        => $product,
			'payment_method' => $payment_method,
			'dry_run'        => ! empty( $assoc_args['dry-run'] ),
			'format'         => $format,
		);
	}

	/**
	 * Print the resolved configuration without generating any data.
	 *
	 * @param array $config Normalised config from parse_args().
	 */
	private function print_dry_run( array $config ) {
		WP_CLI::log( 'Dry run — no data will be written.' );

		$rows = array();
		foreach ( $config as $key => $value ) {
			if ( null === $value ) {
				$display = '(auto)';
			} elseif ( is_bool( $value ) ) {
				$display = $value ? 'yes' : 'no';
			} else {
				$display = (string) $value;
			}

			$rows[] = array(
				'option' => $key,
				'value'  => $display,
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'option', 'value' ) );
	}
}
