<?php
/**
 * Typed value object representing subscription price data.
 *
 * Flows through the Price_Calculator pipeline: populated during extract,
 * enriched during calculate, consumed during render.
 *
 * @package WooCommerce Subscriptions
 */

namespace Automattic\WooCommerce_Subscriptions\Internal\Pricing;

/**
 * Price_Context carries subscription price data through the pipeline.
 *
 * Uses typed properties (PHP 7.4+) for core fields, providing IDE
 * autocompletion, clear documentation, and type safety. Designed for
 * extensibility via subclassing (APFS can extend with scheme-specific
 * fields) and an $extra array for ad-hoc customizations by third parties.
 *
 * @internal This class may be modified, moved or removed in future releases.
 * @since 8.5.0
 */
class Price_Context {

	/**
	 * ------------------------------------------------
	 * Raw data (populated by extract)
	 * ------------------------------------------------
	 */

	/**
	 * Original recurring price before tax adjustment.
	 *
	 * @var float
	 */
	public float $base_recurring_price = 0.0;

	/**
	 * Original sign-up fee before tax adjustment.
	 *
	 * @var float
	 */
	public float $base_sign_up_fee = 0.0;

	/**
	 * Billing period: 'day', 'week', 'month', 'year'.
	 *
	 * @var string
	 */
	public string $billing_period = 'month';

	/**
	 * Billing interval (e.g. 1 = every month, 3 = every 3 months).
	 *
	 * @var int
	 */
	public int $billing_interval = 1;

	/**
	 * Subscription length in billing periods. 0 = unlimited.
	 *
	 * @var int
	 */
	public int $subscription_length = 0;

	/**
	 * Trial length. 0 = no trial.
	 *
	 * @var int
	 */
	public int $trial_length = 0;

	/**
	 * Trial period: 'day', 'week', 'month', 'year'.
	 *
	 * @var string
	 */
	public string $trial_period = '';

	/**
	 * Whether the subscription is synced to a specific day.
	 *
	 * @var bool
	 */
	public bool $is_synced = false;

	/**
	 * Payment day for synced subscriptions.
	 *
	 * int for week (1-7) and month (1-28), array('month'=>string,'day'=>string) for year.
	 *
	 * @var int|array
	 */
	public $payment_day = 0;

	/**
	 * ------------------------------------------------
	 * Tax-adjusted data (populated by calculate)
	 * ------------------------------------------------
	 */

	/**
	 * Tax-adjusted recurring price.
	 *
	 * @var float
	 */
	public float $recurring_price = 0.0;

	/**
	 * Tax-adjusted sign-up fee.
	 *
	 * @var float
	 */
	public float $sign_up_fee = 0.0;

	/**
	 * Tax-adjusted initial payment amount.
	 *
	 * For product display: equals $recurring_price when upfront payment is needed, 0 otherwise.
	 * For cart/checkout (future): the actual prorated amount for synced subscriptions.
	 *
	 * The renderer checks this value to decide whether to show an initial payment
	 * prefix (e.g. "$10 now, and ...") and chooses the appropriate label text.
	 * A value of 0 means no initial payment display.
	 *
	 * @var float
	 */
	public float $initial_amount = 0.0;

	/**
	 * ------------------------------------------------
	 * Extensibility
	 * ------------------------------------------------
	 */

	/**
	 * Extra data for third-party or future use.
	 *
	 * Allows extensions to attach additional context without subclassing.
	 * APFS consolidation will subclass Price_Context for scheme-specific
	 * fields; this array serves lighter-weight customizations.
	 *
	 * @var array
	 */
	public array $extra = array();

	/**
	 * Create a Price_Context from an associative array.
	 *
	 * @since 8.5.0
	 *
	 * @param array $data Key-value pairs matching property names.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$context = new self();

		foreach ( $data as $key => $value ) {
			if ( property_exists( $context, $key ) ) {
				$context->$key = $value;
			}
		}

		return $context;
	}

	/**
	 * Export to associative array (useful for cache keys and debugging).
	 *
	 * @since 8.5.0
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'base_recurring_price' => $this->base_recurring_price,
			'base_sign_up_fee'     => $this->base_sign_up_fee,
			'billing_period'       => $this->billing_period,
			'billing_interval'     => $this->billing_interval,
			'subscription_length'  => $this->subscription_length,
			'trial_length'         => $this->trial_length,
			'trial_period'         => $this->trial_period,
			'is_synced'            => $this->is_synced,
			'payment_day'          => $this->payment_day,
			'recurring_price'      => $this->recurring_price,
			'sign_up_fee'          => $this->sign_up_fee,
			'initial_amount'       => $this->initial_amount,
			'extra'                => $this->extra,
		);
	}
}
