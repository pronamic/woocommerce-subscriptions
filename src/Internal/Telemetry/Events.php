<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Telemetry;

use WC_Tracks;

/**
 * Monitors and locally records information about various subscription-related store events. The information is not
 * however 'sent home' unless tracking is enabled (enforced by WC_Tracks).
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Events {
	private $event_recorder;

	/**
	 * Prepares the event monitoring/recording system.
	 *
	 * @param callable|null $event_recorder Optional. The function to use to record events. Defaults to WC_Tracks::record_event().
	 */
	public function __construct( ?callable $event_recorder = null ) {
		$this->event_recorder = $event_recorder ?? function ( string $event_name, array $event_properties = array() ) {
			// Note that the following method ensures nothing is sent home unless tracking is enabled.
			WC_Tracks::record_event( $event_name, $event_properties );
		};
	}

	/**
	 * Adds listeners for events where we want to send a corresponding Tracks event.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_action( 'woocommerce_new_subscription', array( $this, 'track_new_subscriptions' ) );
	}

	/**
	 * Record the creation of a new subscription.
	 *
	 * @return void
	 */
	public function track_new_subscriptions(): void {
		call_user_func( $this->event_recorder, 'wcs_new_subscription_created' );
	}
}
