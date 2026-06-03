<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\CLI\Test_Data;

use WC_Order;
use WC_Payment_Token_CC;
use WC_Product_Subscription;
use WC_Subscription;
use WC_Subscriptions_Product;
use WP_CLI;

/**
 * Test subscription data generator — health-check-case driven.
 *
 * Creates one subscription (and its supporting customer, product, and parent order) per call,
 * in the exact shape that the corresponding RemediationAdvisor case expects. Local /
 * development use only — driven exclusively by Generate_Command, which enforces the
 * environment guard and mail suppression before ever instantiating this class.
 *
 * Each `--case=<slug>` value maps 1:1 to a case in `docs/health-check/test-cases.md`. The
 * canonical specification of what each case looks like (statuses, schedule meta, related
 * orders, retries, AS rows) lives in that doc; this class is the executable transcription.
 *
 * @since   x.x.x
 * @internal This class may be modified, moved or removed in future releases.
 */
class Generator {

	/**
	 * Meta key stamped on every record this class creates, so purge-test can find them.
	 */
	const TEST_META_KEY = '_wcs_test_data';

	/**
	 * Health-check case slugs the generator can produce. Each entry maps 1:1 to a case
	 * in docs/health-check/test-cases.md and to a CASE_* constant on RemediationAdvisor.
	 */
	const SUPPORTED_CASES = array( 's1a', 's1b', 's2a', 's2b' );

	/**
	 * Cases that need a payment gateway declaring `supports( 'subscriptions' )` to be a
	 * realistic match for the detector. The generator validates the gateway during
	 * config resolution to avoid producing rows the detector would reject.
	 */
	const SUPPORTS_SUBS_GATEWAY_CASES = array( 's1a', 's1b' );

	/**
	 * Cases where Stripe must return a real PaymentMethod token. A fake token is
	 * not reliable for these shapes because the Stripe gateway can filter invalid
	 * tokens before the Health Check detector sees them.
	 */
	const REQUIRES_REAL_STRIPE_TOKEN_CASES = array( 's1a', 's1b' );

	/**
	 * AS hook constant for the renewal-payment action. Used by S2b to force-schedule
	 * an AS row so the sub doesn't get flagged for a missing hook instead.
	 */
	const HOOK_RENEWAL_PAYMENT = 'woocommerce_scheduled_subscription_payment';

	/**
	 * Normalised config from Generate_Command::parse_args().
	 *
	 * @var array
	 */
	private $config;

	/**
	 * Resolved shared customer ID when --customer was supplied, null otherwise.
	 *
	 * @var int|null
	 */
	private $shared_customer_id = null;

	/**
	 * Resolved shared product ID. Always set on first use — either from --product or by
	 * creating a reusable test product for the whole invocation.
	 *
	 * @var int|null
	 */
	private $shared_product_id = null;

	/**
	 * Cached result of resolve_payment_method() — gateway picks are stable per invocation.
	 *
	 * @var string|null
	 */
	private $resolved_payment_method = null;

	/**
	 * @param array $config Normalised CLI config.
	 */
	public function __construct( array $config ) {
		$this->assert_safe_environment();
		$this->config = $config;
		$this->assert_action_scheduler_available();
	}

	/**
	 * Abort unless the current WP environment is suitable for generating test data.
	 *
	 * Mirrors Generate_Command::assert_safe_environment() as a defence-in-depth measure
	 * so that direct callers of this class (outside the CLI command) cannot bypass the
	 * environment guard.
	 */
	private function assert_safe_environment() {
		$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

		if ( ! in_array( $env, array( 'local', 'development' ), true ) ) {
			WP_CLI::error(
				sprintf(
					"Refusing to run in environment '%s'. Generator is only supported when WP_ENVIRONMENT_TYPE is 'local' or 'development'.",
					$env
				)
			);
		}
	}

	/**
	 * Abort if Action Scheduler is not available and the configured case requires it.
	 *
	 * S2b depends on a scheduled AS renewal-payment row for the Health Check detector
	 * to classify it correctly. Without AS, the generated subscription would be silently
	 * invisible to the health check — defeating the tool's purpose.
	 */
	private function assert_action_scheduler_available() {
		if ( 's2b' !== $this->config['case'] ) {
			return;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			WP_CLI::error( 'Action Scheduler is not available. The S2b case requires a scheduled renewal-payment action to be detectable by Health Check.' );
		}
	}

	/**
	 * Create one subscription matching the configured case and return a row describing it.
	 *
	 * @return array
	 */
	public function generate_one() {
		$case = $this->config['case'];

		$customer_id    = $this->resolve_customer();
		$product_id     = $this->resolve_product();
		$payment_method = $this->resolve_payment_method();

		$dispatch = array(
			's1a' => 'build_s1a',
			's1b' => 'build_s1b',
			's2a' => 'build_s2a',
			's2b' => 'build_s2b',
		);

		$method       = $dispatch[ $case ];
		$subscription = $this->{$method}( $customer_id, $product_id, $payment_method );

		return array(
			'subscription_id' => $subscription->get_id(),
			'case'            => 'TC-' . strtoupper( $case ),
			'status'          => $subscription->get_status(),
			'customer_id'     => $customer_id,
			'product_id'      => $product_id,
		);
	}

	//
	// ───── Case builders ──────────────────────────────────────────────────
	//
	// Each builder produces the exact shape its TC-X case expects.
	// `docs/health-check/test-cases.md` is the source of truth for the
	// shape; these methods are the executable transcription. Anything
	// the detector classifier reads (status, meta, AS rows, related
	// orders) is set explicitly here.
	//

	/**
	 * TC-S1a — manual flag stuck, customer has saved token, gateway supports subs.
	 */
	private function build_s1a( $customer_id, $product_id, $gateway_id ) {
		$pm_token = $this->create_test_payment_token( $customer_id, $gateway_id );

		$sub = $this->create_base_subscription(
			$customer_id,
			$product_id,
			$gateway_id,
			'active',
			array( 'next_payment' => $this->date_string( 30 ) )
		);
		$sub->set_requires_manual_renewal( true );
		$sub->save();

		$this->maybe_set_stripe_meta( $sub, $customer_id, $pm_token );

		return $sub;
	}

	/**
	 * TC-S1b — TC-S1a plus a `failed` renewal as the latest related order.
	 */
	private function build_s1b( $customer_id, $product_id, $gateway_id ) {
		$sub = $this->build_s1a( $customer_id, $product_id, $gateway_id );
		$this->create_renewal_order( $sub, 'failed', $gateway_id, Failure_Reasons::default_slug(), null );
		return $sub;
	}

	/**
	 * TC-S2a — active sub with no scheduled next-payment AND no future end date.
	 */
	private function build_s2a( $customer_id, $product_id, $gateway_id ) {
		$sub = $this->create_base_subscription( $customer_id, $product_id, $gateway_id, 'active', array() );
		$sub->set_requires_manual_renewal( false );
		$sub->update_dates(
			array(
				'next_payment' => 0,
				'end'          => 0,
			)
		);
		$sub->save();

		$this->provision_gateway_credentials( $sub, $customer_id, $gateway_id );

		return $sub;
	}

	/**
	 * TC-S2b — active sub with a stale next-payment date (past tolerance) and no
	 * matching renewal order.
	 *
	 * The detector's tolerance is filterable but defaults to 24h, so seven days
	 * past comfortably qualifies. Start date is back-dated 30 days so
	 * `set_next_payment_date()` accepts the past timestamp.
	 *
	 * Force-schedules an AS row so M1's "no AS hook" classifier doesn't preempt
	 * S2b — keeping the case test-faithful to the doc's resolution-precedence.
	 */
	private function build_s2b( $customer_id, $product_id, $gateway_id ) {
		$sub = $this->create_base_subscription(
			$customer_id,
			$product_id,
			$gateway_id,
			'active',
			array( 'start_date' => $this->date_string( -30 ) )
		);
		$sub->set_requires_manual_renewal( false );
		$sub->update_dates( array( 'next_payment' => $this->date_string( -7 ) ) );
		$sub->save();

		$this->provision_gateway_credentials( $sub, $customer_id, $gateway_id );
		$this->force_schedule_renewal_hook( $sub->get_id() );

		return $sub;
	}

	//
	// ───── Building blocks ────────────────────────────────────────────────
	//

	/**
	 * Create a subscription + parent order in the requested status and apply
	 * the supplied date overrides. Returns the live WC_Subscription object.
	 * Most cases call this once and then layer their case-specific state on
	 * top.
	 *
	 * @param int    $customer_id   WP user id.
	 * @param int    $product_id    Subscription product id.
	 * @param string $gateway_id    Payment gateway id.
	 * @param string $status        Final subscription status.
	 * @param array  $date_overrides Date keys to apply via `update_dates()`
	 *                              after status transition. May include
	 *                              `start_date` (consumed at create time
	 *                              instead of via update_dates).
	 *
	 * @return WC_Subscription
	 */
	private function create_base_subscription( $customer_id, $product_id, $gateway_id, $status, array $date_overrides ) {
		$start_date = $date_overrides['start_date'] ?? gmdate( 'Y-m-d H:i:s' );
		unset( $date_overrides['start_date'] );

		$parent_order = $this->create_parent_order( $customer_id, $product_id, $gateway_id, $start_date );

		$sub = wcs_create_subscription(
			array(
				'order_id'         => $parent_order->get_id(),
				'customer_id'      => $customer_id,
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'start_date'       => $start_date,
				'date_created'     => $start_date,
			)
		);
		if ( is_wp_error( $sub ) ) {
			WP_CLI::error( 'Failed to create subscription: ' . $sub->get_error_message() );
		}

		$sub->add_product( wc_get_product( $product_id ), 1 );
		$sub->set_address( $this->get_test_address( $customer_id ), 'billing' );
		$sub->set_payment_method( $gateway_id );
		$sub->calculate_totals();
		$sub->update_meta_data( self::TEST_META_KEY, 1 );
		$sub->save();

		if ( 'active' === $status ) {
			$sub->update_status( 'active', 'Generated by wp wc-subs generate.' );
		}

		if ( ! empty( $date_overrides ) ) {
			$sub->update_dates( $date_overrides );
		}

		$sub->save();

		return $sub;
	}

	/**
	 * Build the parent order. Always `completed` — the cases the generator
	 * produces all assume the initial sign-up succeeded.
	 *
	 * @param int    $customer_id
	 * @param int    $product_id
	 * @param string $gateway_id
	 * @param string $date_created  MySQL UTC datetime.
	 *
	 * @return WC_Order
	 */
	private function create_parent_order( $customer_id, $product_id, $gateway_id, $date_created ) {
		// wc_create_order dereferences $_SERVER['REMOTE_ADDR']; WP-CLI requests have no remote addr.
		$had_remote_addr  = isset( $_SERVER['REMOTE_ADDR'] );
		$orig_remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null;
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		}

		$order = wc_create_order( array( 'customer_id' => $customer_id ) );
		if ( is_wp_error( $order ) ) {
			// Restore $_SERVER before aborting.
			if ( ! $had_remote_addr ) {
				unset( $_SERVER['REMOTE_ADDR'] );
			} else {
				$_SERVER['REMOTE_ADDR'] = $orig_remote_addr;
			}
			WP_CLI::error( 'Failed to create parent order: ' . $order->get_error_message() );
		}

		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->set_address( $this->get_test_address( $customer_id ), 'billing' );
		$order->set_payment_method( $gateway_id );
		$order->calculate_totals();
		$order->update_meta_data( self::TEST_META_KEY, 1 );
		$order->set_date_created( wcs_date_to_time( $date_created ) );
		$order->set_status( 'completed' );
		$order->save();

		// Restore $_SERVER['REMOTE_ADDR'] to its original state.
		if ( ! $had_remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $orig_remote_addr;
		}

		return $order;
	}

	/**
	 * Create a renewal order in the given status, optionally stamping a gateway
	 * error code on it (used by D3 to drive the advisor's card-expired upgrade
	 * path).
	 *
	 * @param WC_Subscription $sub
	 * @param string          $status         Final renewal-order status.
	 * @param string          $gateway_id     Payment method id.
	 * @param string          $failure_reason Failure-reason slug (note text).
	 * @param string|null     $stripe_decline_code Optional gateway error code
	 *                                             stamped via the Stripe
	 *                                             decline-code meta key the
	 *                                             advisor samples.
	 *
	 * @return WC_Order
	 */
	private function create_renewal_order( WC_Subscription $sub, $status, $gateway_id, $failure_reason, $stripe_decline_code ) {
		$renewal = wcs_create_renewal_order( $sub );
		if ( is_wp_error( $renewal ) ) {
			WP_CLI::error( 'Failed to create renewal order: ' . $renewal->get_error_message() );
		}

		$renewal->set_payment_method( $gateway_id );
		$renewal->update_meta_data( self::TEST_META_KEY, 1 );

		if ( '' !== $failure_reason ) {
			$renewal->add_order_note( Failure_Reasons::get_note( $failure_reason ) );
		}

		if ( null !== $stripe_decline_code ) {
			// Detector::gateway_error_code_for() samples this meta key first;
			// stamping it here is what triggers the F2 → D3 upgrade in the
			// advisor's builder.
			$renewal->update_meta_data( '_stripe_charge_decline_code', $stripe_decline_code );
		}

		$renewal->set_status( $status );
		$renewal->save();

		return $renewal;
	}

	/**
	 * Resolve the customer for this subscription. When --customer was supplied, the same user is
	 * reused across the whole invocation; otherwise each call creates a fresh test user.
	 *
	 * @return int
	 */
	private function resolve_customer() {
		if ( ! empty( $this->config['customer'] ) ) {
			if ( null === $this->shared_customer_id ) {
				$this->shared_customer_id = $this->find_or_fail_customer( $this->config['customer'] );
			}
			return $this->shared_customer_id;
		}

		return $this->create_test_customer();
	}

	/**
	 * Look up a customer by numeric ID or email; abort if not found.
	 *
	 * @param string|int $id_or_email
	 * @return int
	 */
	private function find_or_fail_customer( $id_or_email ) {
		$user = is_numeric( $id_or_email )
			? get_user_by( 'id', (int) $id_or_email )
			: get_user_by( 'email', (string) $id_or_email );

		if ( ! $user ) {
			WP_CLI::error( sprintf( 'Customer "%s" not found.', $id_or_email ) );
		}

		return (int) $user->ID;
	}

	/**
	 * Create a fresh test user with a collision-resistant login and email.
	 *
	 * @return int
	 */
	private function create_test_customer() {
		$suffix = strtolower( wp_generate_password( 8, false, false ) );

		$user_id = wp_insert_user(
			array(
				'user_login' => 'wcs_test_' . $suffix,
				'user_email' => sprintf( 'wcs-test-%s@example.test', $suffix ),
				'user_pass'  => wp_generate_password( 20 ),
				'first_name' => 'WCS',
				'last_name'  => 'Test',
				'role'       => 'customer',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			WP_CLI::error( 'Failed to create test customer: ' . $user_id->get_error_message() );
		}

		update_user_meta( $user_id, self::TEST_META_KEY, 1 );

		return (int) $user_id;
	}

	/**
	 * Resolve the subscription product. One product is shared across the whole invocation —
	 * either the user-supplied one or a freshly minted test product — to avoid littering the
	 * catalog when --count is large.
	 *
	 * @return int
	 */
	private function resolve_product() {
		if ( null !== $this->shared_product_id ) {
			return $this->shared_product_id;
		}

		if ( ! empty( $this->config['product'] ) ) {
			$this->shared_product_id = $this->find_or_fail_product( (int) $this->config['product'] );
		} else {
			$this->shared_product_id = $this->create_test_product();
		}

		return $this->shared_product_id;
	}

	/**
	 * Verify an existing product ID and confirm it's a subscription product.
	 *
	 * @param int $product_id
	 * @return int
	 */
	private function find_or_fail_product( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			WP_CLI::error( sprintf( 'Product %d not found.', $product_id ) );
		}
		if ( ! WC_Subscriptions_Product::is_subscription( $product ) ) {
			WP_CLI::error( sprintf( 'Product %d is not a subscription product.', $product_id ) );
		}

		return (int) $product_id;
	}

	/**
	 * Create a minimal monthly subscription product priced at 10.00.
	 *
	 * @return int
	 */
	private function create_test_product() {
		$product = new WC_Product_Subscription();
		$product->set_name( 'WCS Test Subscription Product' );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_regular_price( '10.00' );
		$product->set_price( '10.00' );
		$product->update_meta_data( '_subscription_price', '10.00' );
		$product->update_meta_data( '_subscription_period', 'month' );
		$product->update_meta_data( '_subscription_period_interval', '1' );
		$product->update_meta_data( '_subscription_length', '0' );
		$product->update_meta_data( '_subscription_sign_up_fee', '0' );
		$product->update_meta_data( '_subscription_trial_length', '0' );
		$product->update_meta_data( '_subscription_trial_period', 'month' );
		$product->update_meta_data( self::TEST_META_KEY, 1 );

		$id = $product->save();
		if ( ! $id ) {
			WP_CLI::error( 'Failed to create test subscription product.' );
		}

		return (int) $id;
	}

	/**
	 * Resolve and validate the payment method for this run.
	 *
	 * The caller (Generate_Command) enforces --payment-method as required, so
	 * config['payment_method'] is always set. This method validates the gateway
	 * and caches the result.
	 *
	 * @return string Registered payment-gateway id.
	 */
	private function resolve_payment_method() {
		if ( null !== $this->resolved_payment_method ) {
			return $this->resolved_payment_method;
		}

		$gateway_id         = $this->config['payment_method'];
		$needs_subs_gateway = in_array( $this->config['case'], self::SUPPORTS_SUBS_GATEWAY_CASES, true );

		if ( $needs_subs_gateway ) {
			$this->assert_gateway_supports_subscriptions( $gateway_id );
		}

		if ( $this->requires_real_stripe_token( $gateway_id ) ) {
			$this->assert_stripe_test_secret_key_is_configured();
		}

		$this->resolved_payment_method = $gateway_id;
		return $gateway_id;
	}

	/**
	 * Verify that the given gateway exists and declares subscriptions support. Called only
	 * when the active case requires it; a gateway without that support would never survive
	 * the detector's filter.
	 *
	 * @param string $gateway_id
	 */
	private function assert_gateway_supports_subscriptions( $gateway_id ) {
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( ! isset( $gateways[ $gateway_id ] ) ) {
			WP_CLI::error(
				sprintf(
					'--case=%s needs a registered payment gateway; "%s" is not registered. Activate a gateway that declares subscriptions support (e.g. woocommerce-gateway-dummy) or pass --payment-method=<gateway>.',
					$this->config['case'],
					$gateway_id
				)
			);
		}

		$gateway = $gateways[ $gateway_id ];
		if ( ! method_exists( $gateway, 'supports' ) || ! $gateway->supports( 'subscriptions' ) ) {
			WP_CLI::error(
				sprintf(
					'--case=%s needs a gateway that declares supports("subscriptions"); "%s" does not.',
					$this->config['case'],
					$gateway_id
				)
			);
		}
	}

	/**
	 * Fill a plausible billing address for the test customer.
	 *
	 * @param int $customer_id
	 * @return array
	 */
	private function get_test_address( $customer_id ) {
		$user = get_userdata( $customer_id );

		return array(
			'first_name' => 'WCS',
			'last_name'  => 'Test',
			'email'      => $user ? $user->user_email : '',
			'address_1'  => '123 Test St',
			'city'       => 'Testville',
			'state'      => 'CA',
			'postcode'   => '90210',
			'country'    => 'US',
		);
	}

	/**
	 * Create a saved card token for the given customer under the given gateway.
	 * Used by S1a/S1b — those cases only need the existence of a token, so the
	 * card details are decorative.
	 *
	 * When the gateway is Stripe and test-mode API keys are configured, a real
	 * Stripe test customer + PaymentMethod is created via the Stripe API so that
	 * remediation actions (e.g. "switch to automatic and retry") can process an
	 * actual charge against the Stripe test environment. Falls back to a fake
	 * token when the API call fails or keys are not configured.
	 *
	 * @param int    $customer_id
	 * @param string $gateway_id
	 * @return string The payment method token string (PM id or fake token).
	 */
	private function create_test_payment_token( $customer_id, $gateway_id ) {
		$pm_token = null;

		if ( 'stripe' === $gateway_id ) {
			$pm_token = $this->create_stripe_test_token( $customer_id );
			if ( $this->requires_real_stripe_token( $gateway_id ) && ( ! is_string( $pm_token ) || 0 !== strpos( $pm_token, 'pm_' ) ) ) {
				$this->stripe_setup_error(
					'Stripe did not return a usable test PaymentMethod token.'
				);
			}
		}

		$token = new WC_Payment_Token_CC();
		$token->set_token( $pm_token ?? 'wcs_test_token_' . wp_generate_password( 12, false, false ) );
		$token->set_gateway_id( $gateway_id );
		$token->set_user_id( $customer_id );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( (string) ( (int) gmdate( 'Y' ) + 2 ) );
		$token->set_card_type( 'visa' );
		$token->add_meta_data( self::TEST_META_KEY, 1, true );
		$token->save();

		return $token->get_token();
	}

	/**
	 * Create a real Stripe test customer + PaymentMethod via the Stripe API
	 * and store the customer ID in user meta so the gateway can find it.
	 *
	 * @param int $customer_id WP user id.
	 * @return string|null The `pm_...` PaymentMethod ID, or null on failure.
	 */
	private function create_stripe_test_token( $customer_id ) {
		$secret_key = $this->get_stripe_test_secret_key();

		if ( '' === $secret_key ) {
			if ( $this->requires_real_stripe_token( 'stripe' ) ) {
				$this->stripe_setup_error( 'Stripe test secret key is not configured.' );
			}
			WP_CLI::warning( 'Stripe test secret key not configured — falling back to fake token.' );
			return null;
		}

		$stripe_customer_id = $this->get_or_create_stripe_customer( $customer_id, $secret_key );
		if ( null === $stripe_customer_id ) {
			return null;
		}

		// Create a PaymentMethod from the test `tok_visa` token.
		$pm_response = $this->stripe_api_post(
			$secret_key,
			'payment_methods',
			array(
				'type'        => 'card',
				'card[token]' => 'tok_visa',
			)
		);
		$pm_id       = $pm_response['id'] ?? null;
		if ( null === $pm_id ) {
			if ( $this->requires_real_stripe_token( 'stripe' ) ) {
				$this->stripe_setup_error( 'Failed to create a Stripe test PaymentMethod.' );
			}
			WP_CLI::warning( 'Failed to create Stripe PaymentMethod — falling back to fake token.' );
			return null;
		}

		// Attach the PM to the customer.
		$attach_response = $this->stripe_api_post(
			$secret_key,
			"payment_methods/{$pm_id}/attach",
			array(
				'customer' => $stripe_customer_id,
			)
		);
		if ( empty( $attach_response['id'] ) ) {
			if ( $this->requires_real_stripe_token( 'stripe' ) ) {
				$this->stripe_setup_error( 'Failed to attach the Stripe test PaymentMethod to the test customer.' );
			}
			WP_CLI::warning( 'Failed to attach Stripe PaymentMethod — falling back to fake token.' );
			return null;
		}

		return $pm_id;
	}

	/**
	 * Get or create a Stripe test customer for the given WP user. Caches the
	 * Stripe customer ID in user meta (same key the Stripe gateway plugin uses)
	 * so subsequent calls reuse the same customer.
	 *
	 * @param int    $customer_id WP user id.
	 * @param string $secret_key  Stripe test secret key.
	 * @return string|null Stripe customer ID or null on failure.
	 */
	private function get_or_create_stripe_customer( $customer_id, $secret_key ) {
		// Check if user already has a Stripe customer ID (test mode key).
		$existing = get_user_option( '_stripe_customer_id', $customer_id );
		if ( ! empty( $existing ) ) {
			return $existing;
		}

		$user           = get_userdata( $customer_id );
		$is_test_user   = get_user_meta( $customer_id, self::TEST_META_KEY, true );
		$site_hash      = substr( md5( wp_parse_url( home_url(), PHP_URL_HOST ) ), 0, 8 );
		$redacted_email = sprintf( 'wcs-test-%d@%s.example.test', $customer_id, $site_hash );

		$response = $this->stripe_api_post(
			$secret_key,
			'customers',
			array(
				'description' => sprintf( 'WCS test customer (WP user %d)', $customer_id ),
				'email'       => $is_test_user && $user ? $user->user_email : $redacted_email,
			)
		);

		$stripe_customer_id = $response['id'] ?? null;
		if ( null === $stripe_customer_id ) {
			if ( $this->requires_real_stripe_token( 'stripe' ) ) {
				$this->stripe_setup_error( 'Failed to create a Stripe test customer.' );
			}
			WP_CLI::warning( 'Failed to create Stripe customer — falling back to fake token.' );
			return null;
		}

		$is_test_user = get_user_meta( $customer_id, self::TEST_META_KEY, true );
		if ( ! $is_test_user ) {
			WP_CLI::warning(
				sprintf(
					'Writing _stripe_customer_id to existing user %d. This value will not be cleaned up by purge-test.',
					$customer_id
				)
			);
		}

		update_user_option( $customer_id, '_stripe_customer_id', $stripe_customer_id, false );

		return $stripe_customer_id;
	}

	/**
	 * Minimal Stripe API POST helper. Returns the decoded JSON response.
	 *
	 * @param string $secret_key Stripe secret key.
	 * @param string $endpoint   API endpoint path (e.g. 'customers').
	 * @param array  $params     POST parameters.
	 * @return array Decoded response body.
	 */
	private function stripe_api_post( $secret_key, $endpoint, $params ) {
		$response = wp_remote_post(
			'https://api.stripe.com/v1/' . $endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $secret_key . ':' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth header for Stripe API.
				),
				'body'    => $params,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_CLI::warning( 'Stripe API request failed: ' . $response->get_error_message() );
			return array();
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Provision gateway payment credentials (token + subscription meta) so
	 * that remediation actions involving payment processing (retry, process
	 * missed renewal, etc.) can complete a real charge in the test
	 * environment. Called by every builder whose remediation path triggers
	 * a payment — deliberately omitted from D1 (which tests the "no token"
	 * scenario) and S1a/S1b (which provision tokens via their own path
	 * for the manual-flag-stuck signal).
	 *
	 * @param WC_Subscription $sub         Subscription to provision.
	 * @param int             $customer_id WP user id.
	 * @param string          $gateway_id  Payment gateway id.
	 */
	private function provision_gateway_credentials( $sub, $customer_id, $gateway_id ) {
		$pm_token = $this->create_test_payment_token( $customer_id, $gateway_id );
		$this->maybe_set_stripe_meta( $sub, $customer_id, $pm_token );
	}

	/**
	 * Set `_stripe_customer_id` and `_stripe_source_id` on the subscription
	 * when the token is a real Stripe PaymentMethod (`pm_...`). Without these
	 * the Stripe gateway can't resolve which customer/method to charge during
	 * renewal processing.
	 *
	 * @param WC_Subscription $sub         Subscription to update.
	 * @param int             $customer_id WP user id.
	 * @param string          $pm_token    The token string from create_test_payment_token().
	 */
	private function maybe_set_stripe_meta( $sub, $customer_id, $pm_token ) {
		if ( 0 !== strpos( $pm_token, 'pm_' ) ) {
			return;
		}

		$stripe_customer_id = get_user_option( '_stripe_customer_id', $customer_id );
		if ( empty( $stripe_customer_id ) ) {
			return;
		}

		$sub->update_meta_data( '_stripe_customer_id', $stripe_customer_id );
		$sub->update_meta_data( '_stripe_source_id', $pm_token );
		$sub->save();
	}

	/**
	 * Whether the current case must have a real Stripe PaymentMethod to
	 * produce a detector-visible row.
	 *
	 * @param string $gateway_id Resolved payment gateway id.
	 * @return bool
	 */
	private function requires_real_stripe_token( $gateway_id ) {
		return 'stripe' === $gateway_id
			&& in_array( $this->config['case'], self::REQUIRES_REAL_STRIPE_TOKEN_CASES, true );
	}

	/**
	 * Abort early when a Stripe-dependent case cannot produce a reliable
	 * detector-visible token.
	 */
	private function assert_stripe_test_secret_key_is_configured() {
		if ( '' === $this->get_stripe_test_secret_key() ) {
			$this->stripe_setup_error( 'Stripe test secret key is not configured.' );
		}
	}

	/**
	 * Read the Stripe test secret key from the WooCommerce Stripe settings.
	 *
	 * @return string
	 */
	private function get_stripe_test_secret_key() {
		$settings = get_option( 'woocommerce_stripe_settings', array() );
		if ( ! is_array( $settings ) ) {
			return '';
		}

		return isset( $settings['test_secret_key'] ) ? (string) $settings['test_secret_key'] : '';
	}

	/**
	 * Print actionable setup guidance for Stripe token-dependent cases.
	 *
	 * @param string $reason Specific failure reason.
	 */
	private function stripe_setup_error( $reason ) {
		$message  = "%s\n\n";
		$message .= "--payment-method=stripe requires a usable Stripe test configuration for --case=%s.\n\n";
		$message .= 'Set up WooCommerce Stripe Gateway in test mode with a test secret key, then rerun the command. ';
		$message .= "Alternatively, use another registered gateway that supports subscriptions, for example:\n\n%s";

		WP_CLI::error(
			sprintf(
				$message,
				$reason,
				$this->config['case'],
				sprintf(
					'wp wc-subs generate --case=%s --count=%d --payment-method=dummy',
					$this->config['case'],
					(int) $this->config['count']
				)
			)
		);
	}

	/**
	 * Force-schedule a renewal-payment AS row for the sub. Used by S2b so the
	 * M1 classifier's "no AS hook" check doesn't preempt the past-due path.
	 *
	 * @param int $sub_id Subscription id.
	 */
	private function force_schedule_renewal_hook( $sub_id ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + DAY_IN_SECONDS,
				self::HOOK_RENEWAL_PAYMENT,
				array( 'subscription_id' => $sub_id )
			);
		}
	}

	/**
	 * UTC mysql datetime string offset by N days from now. Negative = past, positive = future.
	 *
	 * @param int $offset_days
	 * @return string
	 */
	private function date_string( $offset_days ) {
		return gmdate( 'Y-m-d H:i:s', time() + ( (int) $offset_days * DAY_IN_SECONDS ) );
	}
}
