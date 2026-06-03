<?php
/**
 * Upgrade script for version 8.8.0
 *
 * @version 8.8.0
 */

use Automattic\WooCommerce_Subscriptions\Internal\Queue_Management\Auto_Enable;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WCS_Plugin_Upgrade_8_8_0 class.
 */
class WCS_Plugin_Upgrade_8_8_0 {

	/**
	 * Consider auto-enabling the "Dedicated processing" feature on this site.
	 *
	 * Delegates the decision to {@see Auto_Enable}, which inspects the live environment for any signs of
	 * existing Action Scheduler tuning. Only flips the option on stores that pass every probe — when any
	 * signal suggests the site has been hand-tuned, we skip and leave the merchant in control. Logs the
	 * decision (and the disqualifying signal, when applicable) to the upgrade log for later diagnosis.
	 *
	 * @since 8.8.0
	 */
	public static function maybe_auto_enable_reserved_processing_capacity(): void {
		WCS_Upgrade_Logger::add( 'Considering auto-enable of "Dedicated processing"...' );

		$result = ( new Auto_Enable() )->maybe_enable();

		if ( $result['enabled'] ) {
			WCS_Upgrade_Logger::add( 'Auto-enabled "Dedicated processing".' );
		} else {
			WCS_Upgrade_Logger::add( sprintf( 'Skipped auto-enable of "Dedicated processing": %1$s.', $result['reason'] ) );
		}
	}
}
