<?php
/**
 * Admin View: Report by Period
 *
 * Based on WooCommerce's Report by Date template but without date filters or sidebar.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

?>

<div id="poststuff" class="woocommerce-reports-wide">
	<div class="postbox">
		<div class="inside">
			<?php $this->get_main_chart(); ?>
		</div>
	</div>
</div>
