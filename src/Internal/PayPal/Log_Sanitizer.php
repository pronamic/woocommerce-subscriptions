<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\PayPal;

/**
 * Reduces PayPal IPN messages and PayPal NVP API payloads to the fields that are useful when diagnosing a
 * subscription problem, so that the diagnostic logs do not also become a record of who bought what, where they
 * live, and which keys authorize acting on their orders.
 *
 * The field lists are allowlists: anything PayPal sends that is not named here is dropped. Only the field's name
 * is kept, so a payload carrying something new is visible in the log without its value being written there.
 *
 * @since 9.2.0
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Log_Sanitizer {

	/**
	 * The key under which the names of the omitted fields are listed.
	 */
	const OMITTED_KEY = '[omitted]';

	/**
	 * IPN message fields that can be logged as they were received.
	 *
	 * Compared against the normalized form of the field name, so a numbered variant such as 'amount3' or
	 * 'mc_gross_1' is covered by its base name.
	 *
	 * @link https://developer.paypal.com/docs/classic/ipn/integration-guide/IPNandPDTVariables/
	 *
	 * @var string[]
	 */
	private static $ipn_fields = array(
		// Message identity.
		'txn_type',
		'txn_id',
		'parent_txn_id',
		'ipn_track_id',
		'notify_version',
		'test_ipn',
		'charset',
		'receipt_id',

		// Payment outcome.
		'payment_status',
		'payment_type',
		'payment_date',
		'pending_reason',
		'reason_code',
		'transaction_entity',
		'protection_eligibility',
		'payer_status',

		// Money.
		'mc_currency',
		'mc_gross',
		'mc_fee',
		'mc_handling',
		'mc_shipping',
		'mc_amount',
		'amount',
		'currency_code',
		'settle_amount',
		'settle_currency',
		'exchange_rate',
		'payment_gross',
		'payment_fee',
		'tax',
		'shipping',
		'handling_amount',
		'discount',

		// The subscription profile.
		'subscr_id',
		'subscr_date',
		'subscr_effective',
		'recurring_payment_id',
		'recurring',
		'reattempt',
		'retry_at',
		'period',
		'recur_times',
		'next_payment_date',
		'outstanding_balance',
		'initial_payment_status',
		'initial_payment_txn_id',
		'initial_payment_amount',
		'time_created',
		'profile_status',
		'product_type',
		'product_name',

		// What was bought, and the store's own reference for it.
		'invoice',
		'rp_invoice_id',
		'item_name',
		'item_number',
		'quantity',

		// The receiving account. This is the store's own PayPal address, not the buyer's.
		'business',
		'receiver_email',
		'receiver_id',
	);

	/**
	 * IPN fields replaced by a fingerprint: needed to tell one buyer's messages from another's, but not
	 * something the log should spell out.
	 *
	 * @var string[]
	 */
	private static $ipn_fingerprint_fields = array(
		'payer_id',
		'payer_email',
	);

	/**
	 * IPN fields holding the JSON payload the store sent to PayPal, which is summarized rather than logged.
	 *
	 * @var string[]
	 */
	private static $ipn_custom_fields = array(
		'custom',
	);

	/**
	 * NVP API fields that can be logged as they were sent or received.
	 *
	 * Compared against the normalized form of the field name, so the list-style ('L_ERRORCODE0') and
	 * per-payment ('PAYMENTREQUEST_0_AMT') variants are covered by their base names.
	 *
	 * @link https://developer.paypal.com/docs/classic/api/NVPAPIOverview/
	 *
	 * @var string[]
	 */
	private static $api_fields = array(
		// Call identity and outcome. CORRELATIONID is the reference PayPal's own support asks for.
		'ack',
		'version',
		'build',
		'timestamp',
		'correlationid',
		'method',
		'action',

		// Errors.
		'errorcode',
		'shortmessage',
		'longmessage',
		'severitycode',

		// Payment outcome.
		'transactionid',
		'parenttransactionid',
		'transactiontype',
		'paymenttype',
		'paymentstatus',
		'pendingreason',
		'reasoncode',
		'ordertime',
		'protectioneligibility',
		'protectioneligibilitytype',
		'successpageredirectrequested',

		// Money.
		'amt',
		'currencycode',
		'feeamt',
		'taxamt',
		'itemamt',
		'shippingamt',
		'handlingamt',
		'insuranceamt',
		'shipdiscamt',
		'settleamt',
		'exchangerate',
		'maxamt',

		// The billing agreement or profile being acted on.
		'billingagreementid',
		'referenceid',
		'profileid',
		'profilestatus',
		'checkoutstatus',
		'billingtype',
		'billingperiod',
		'billingfrequency',
		'totalbillingcycles',
		'nextbillingdate',
		'numcyclescompleted',
		'numcyclesremaining',

		// What was bought, and the store's own reference for it. Note that a per-item DESC is built from the
		// order's item meta, so buyer-entered fields (a gift message, a product add-on) can appear in it. It is
		// kept deliberately: it is the only store-side record of what each request actually transmitted, which is
		// what settles whether an item's details were dropped or truncated on their way to PayPal.
		'invnum',
		'desc',
		'billingagreementdescription',
		'name',
		'number',
		'qty',
		'itemurl',

		// How the call was configured.
		'paymentaction',
		'paymentrequestid',
		'buttonsource',
		'noshipping',
		'landingpage',
		'brandname',
		'returnfmfdetails',
		'pagestyle',
	);

	/**
	 * NVP API fields replaced by a fingerprint: needed to follow one checkout across several calls, but not
	 * something the log should spell out.
	 *
	 * @var string[]
	 */
	private static $api_fingerprint_fields = array(
		'payerid',
		'email',
		'token',
	);

	/**
	 * NVP API fields holding the JSON payload the store sent to PayPal, which is summarized rather than logged.
	 *
	 * @var string[]
	 */
	private static $api_custom_fields = array(
		'custom',
		'billingagreementcustom',
	);

	/**
	 * NVP API fields holding a URL under the store's control, which is reduced to the endpoint it points at.
	 *
	 * NOTIFYURL is the store's IPN endpoint, a common cause of missing IPNs. RETURNURL and CANCELURL stay off
	 * this list deliberately: their query strings carry the order key and a nonce, and their endpoints have not
	 * earned a place in the log — if support ever needs one, adding it here is the whole change.
	 *
	 * @var string[]
	 */
	private static $api_url_fields = array(
		'notifyurl',
	);

	/**
	 * Fields kept when summarizing a 'custom' payload. Everything else it carries, the order key and the
	 * subscription key included, is dropped.
	 *
	 * @var string[]
	 */
	private static $custom_fields = array(
		'order_id',
		'subscription_id',
	);

	/**
	 * Reduce a PayPal IPN message to the fields that are safe to log.
	 *
	 * @since 9.2.0
	 *
	 * @param array $message The IPN message as received from PayPal.
	 * @return array
	 */
	public static function sanitize_ipn_message( $message ) {
		return self::sanitize( $message, self::$ipn_fields, self::$ipn_fingerprint_fields, self::$ipn_custom_fields );
	}

	/**
	 * Reduce a PayPal NVP API request or response to the fields that are safe to log.
	 *
	 * @since 9.2.0
	 *
	 * @param array $parameters The parsed NVP parameters.
	 * @return array
	 */
	public static function sanitize_api_parameters( $parameters ) {
		return self::sanitize( $parameters, self::$api_fields, self::$api_fingerprint_fields, self::$api_custom_fields, self::$api_url_fields );
	}

	/**
	 * Encode a sanitized payload for writing to the log.
	 *
	 * Fails closed: a JSON encoding failure yields '{}' rather than an empty string, so a consumer treating a
	 * falsy body as absent — WCS_SV_API_Base::broadcast_request() replaces one with a placeholder — still logs
	 * the sanitized payload.
	 *
	 * @since 9.2.0
	 *
	 * @param mixed $sanitized The already-sanitized payload.
	 * @return string
	 */
	public static function to_json( $sanitized ) {
		$json = wp_json_encode( $sanitized );

		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * Build a short, stable stand-in for a value, so two log entries about the same buyer or checkout can still
	 * be tied together without the value itself being written to the log.
	 *
	 * The site's secret keys are mixed in — under a scheme of this class's own, so a value published in a log is
	 * not derived from the salt protecting nonces or cookies — and the result cannot be matched back to a guessed
	 * email address.
	 *
	 * @since 9.2.0
	 *
	 * @param mixed $value The value to fingerprint.
	 * @return string
	 */
	public static function fingerprint( $value ) {
		if ( ! is_scalar( $value ) ) {
			return 'fingerprint:none';
		}

		return 'fingerprint:' . substr( wp_hash( (string) $value, 'woocommerce_subscriptions_paypal_log', 'sha256' ), 0, 12 );
	}

	/**
	 * Apply an allowlist to a payload.
	 *
	 * @since 9.2.0
	 *
	 * @param mixed    $data         The payload. Anything other than an array is treated as an empty payload.
	 * @param string[] $allowed      Normalized names of the fields to keep.
	 * @param string[] $fingerprints Normalized names of the fields to replace with a fingerprint.
	 * @param string[] $custom       Normalized names of the fields holding a 'custom' payload.
	 * @param string[] $urls         Normalized names of the fields holding a store-controlled URL.
	 * @return array
	 */
	private static function sanitize( $data, $allowed, $fingerprints, $custom, $urls = array() ) {
		if ( ! is_array( $data ) ) {
			return array();
		}

		$safe    = array();
		$omitted = array();

		foreach ( $data as $key => $value ) {
			$name = self::normalize_field_name( $key );

			if ( in_array( $name, $custom, true ) ) {
				$safe[ $key ] = self::summarize_custom_payload( $value );
			} elseif ( in_array( $name, $urls, true ) ) {
				$safe[ $key ] = self::summarize_url( $value );
			} elseif ( in_array( $name, $fingerprints, true ) ) {
				$safe[ $key ] = self::fingerprint( $value );
			} elseif ( is_scalar( $value ) && in_array( $name, $allowed, true ) ) {
				$safe[ $key ] = $value;
			} else {
				$omitted[] = (string) $key;
			}
		}

		if ( ! empty( $omitted ) ) {
			sort( $omitted );
			$safe[ self::OMITTED_KEY ] = implode( ', ', $omitted );
		}

		return $safe;
	}

	/**
	 * Reduce a field name to the form the allowlists are written in.
	 *
	 * PayPal repeats a field by numbering it ('L_ERRORCODE0', 'PAYMENTREQUEST_0_AMT', 'amount3', 'item_name1'),
	 * so the numbering is removed and the base name is what gets matched.
	 *
	 * @since 9.2.0
	 *
	 * @param string|int $key The field name as PayPal sent it.
	 * @return string
	 */
	private static function normalize_field_name( $key ) {
		$name = preg_replace( '/^L_/', '', (string) $key );
		$name = preg_replace( '/^PAYMENT(?:REQUEST|INFO)_\d+_/', '', $name );
		$name = preg_replace( '/_?\d+$/', '', $name );

		return strtolower( $name );
	}

	/**
	 * Summarize the JSON payload the store asked PayPal to hand back.
	 *
	 * It carries the order and subscription IDs, which are what support needs, alongside the order and
	 * subscription keys, which authorize acting on them. Only the IDs are kept.
	 *
	 * @since 9.2.0
	 *
	 * @param mixed $value The raw 'custom' value.
	 * @return array|string
	 */
	private static function summarize_custom_payload( $value ) {
		if ( ! is_scalar( $value ) ) {
			return self::OMITTED_KEY;
		}

		$value = (string) $value;

		// Before WooCommerce 2.3.11 'custom' was simply the order ID.
		if ( is_numeric( $value ) ) {
			return $value;
		}

		$decoded = json_decode( $value, true );

		if ( ! is_array( $decoded ) ) {
			return self::fingerprint( $value );
		}

		$summary = array();

		foreach ( self::$custom_fields as $field ) {
			if ( isset( $decoded[ $field ] ) && is_scalar( $decoded[ $field ] ) ) {
				$summary[ $field ] = $decoded[ $field ];
			}
		}

		$omitted = array_diff( array_keys( $decoded ), array_keys( $summary ) );

		if ( ! empty( $omitted ) ) {
			sort( $omitted );
			$summary[ self::OMITTED_KEY ] = implode( ', ', $omitted );
		}

		return $summary;
	}

	/**
	 * Summarize a URL the store sent to PayPal.
	 *
	 * The diagnostic these fields answer is whether the store handed PayPal the right endpoint, and that survives
	 * the query string being dropped. The 'wc-api' argument is the endpoint on stores without pretty permalinks,
	 * so it alone is kept; anything else appended to the URL — an access token a firewall wants, for instance —
	 * is dropped, and named the way every other omitted field is.
	 *
	 * @since 9.2.0
	 *
	 * @param mixed $value The raw URL.
	 * @return array|string
	 */
	private static function summarize_url( $value ) {
		if ( ! is_scalar( $value ) ) {
			return self::OMITTED_KEY;
		}

		$parts = explode( '#', (string) $value, 2 );
		$parts = explode( '?', $parts[0], 2 );
		$url   = $parts[0];

		parse_str( isset( $parts[1] ) ? $parts[1] : '', $arguments );

		if ( isset( $arguments['wc-api'] ) && is_scalar( $arguments['wc-api'] ) ) {
			$url = add_query_arg( 'wc-api', rawurlencode( (string) $arguments['wc-api'] ), $url );
			unset( $arguments['wc-api'] );
		}

		if ( empty( $arguments ) ) {
			return $url;
		}

		$omitted = array_map( 'strval', array_keys( $arguments ) );
		sort( $omitted );

		return array(
			'url'             => $url,
			self::OMITTED_KEY => implode( ', ', $omitted ),
		);
	}
}
