<?php
/**
 * About page for Subscriptions 2.1.0
 *
 * @author		Prospress
 * @category	Admin
 * @package		WooCommerce Subscriptions/Admin/Upgrades
 * @version		1.0.0 - Migrated from WooCommerce Subscriptions v2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>

<div class="wrap about-wrap">

	<h1><?php esc_html_e( 'Welcome to Subscriptions 2.1!', 'woocommerce-subscriptions' ); ?></h1>

	<div class="about-text woocommerce-about-text">
		<?php esc_html_e( 'Thank you for updating to the latest version of WooCommerce Subscriptions.', 'woocommerce-subscriptions' ); ?>
		<?php printf( esc_html__( 'Version 2.1 introduces some great new features requested by store managers just like you (and possibly even by %syou%s).', 'woocommerce-subscriptions' ), '<em>', '</em>' ); ?>
		<?php esc_html_e( 'We hope you enjoy it!', 'woocommerce-subscriptions' ); ?>
	</div>

	<div class="wcs-badge">
		<?php
		// translators: placeholder is version number
		printf( esc_html__( 'Version %s', 'woocommerce-subscriptions' ), esc_html( $active_version ) ); ?>
	</div>

	<p class="woocommerce-actions">
		<a href="<?php echo esc_url( $settings_page ); ?>" class="button button-primary"><?php esc_html_e( 'Settings', 'woocommerce-subscriptions' ); ?></a>
		<a class="docs button button-primary" href="<?php echo esc_url( apply_filters( 'woocommerce_docs_url', 'http://docs.woocommerce.com/document/subscriptions/', 'woocommerce-subscriptions' ) ); ?>"><?php echo esc_html_x( 'Documentation', 'short for documents', 'woocommerce-subscriptions' ); ?></a>
		<a href="https://twitter.com/share" class="twitter-share-button" data-url="http://docs.woocommerce.com/document/subscriptions/version-2-1/" data-text="I just updated to WooCommerce Subscriptions v2.1, woot!" data-via="WooCommerce" data-size="large" data-hashtags="WooCommerce">Tweet</a>
<script>!function(d,s,id){var js,fjs=d.getElementsByTagName(s)[0];if(!d.getElementById(id)){js=d.createElement(s);js.id=id;js.src="//platform.twitter.com/widgets.js";fjs.parentNode.insertBefore(js,fjs);}}(document,"script","twitter-wjs");</script>
	</p>

	<div class="changelog">
		<h2><?php esc_html_e( "Check Out What's New", 'woocommerce-subscriptions' ); ?></h2>

		<div class="feature-section two-col">
			<div class="feature-image col">
				<img src="<?php echo esc_url( wcs_get_image_asset_url( 'subscription-reports.png' ) ); ?>" />
			</div>

			<div class="col last-feature feature-copy">
				<h3><?php esc_html_e( 'Subscription Reports', 'woocommerce-subscriptions' ); ?></h3>
				<p><?php esc_html_e( 'How many customers stay subscribed for more than 6 months? What is the average lifetime value of your subscribers? How much renewal revenue will your store earn next month?', 'woocommerce-subscriptions' ); ?></p>
				<p><?php esc_html_e( 'These are important questions for any subscription commerce business.', 'woocommerce-subscriptions' ); ?></p>
				<p><?php esc_html_e( 'Prior to Subscriptions 2.1, they were not easy to answer. Subscriptions 2.1 introduces new reports to answer these questions, and many more.', 'woocommerce-subscriptions' ); ?></p>
				<p class="woocommerce-actions">
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-reports&tab=subscriptions' ) ); ?>" ><?php esc_html_e( 'View Reports', 'woocommerce-subscriptions' ); ?></a>
					<a class="button" href="http://docs.woocommerce.com/document/subscriptions/reports/"><?php echo esc_html_x( 'Learn More', 'learn more link to subscription reports documentation', 'woocommerce-subscriptions' ); ?></a>
				</p>
			</div>
		</div>

		<div class="feature-section two-col">

			<div class="col last-feature feature-right feature-image">
				<img src="<?php echo esc_url( wcs_get_image_asset_url( 'renewal-retry-settings.png' ) ); ?>" />
			</div>

			<div class="col feature-copy">
				<h3><?php esc_html_e( 'Automatic Failed Payment Retry', 'woocommerce-subscriptions' ); ?></h3>
				<p><?php esc_html_e( 'Failed recurring payments can now be retried automatically. This helps recover revenue that would otherwise be lost due to payment methods being declined only temporarily.', 'woocommerce-subscriptions' ); ?></p>
				<p><?php esc_html_e( 'By default, Subscriptions will retry the payment 5 times over 7 days. The rules that control the retry system can be modified to customise:', 'woocommerce-subscriptions' ); ?></p>
				<ul>
					<li><?php esc_html_e( 'the total number of retry attempts', 'woocommerce-subscriptions' ); ?></li>
					<li><?php esc_html_e( 'how long to wait between retry attempts', 'woocommerce-subscriptions' ); ?></li>
					<li><?php esc_html_e( 'emails sent to the customer and store manager', 'woocommerce-subscriptions' ); ?></li>
					<li><?php esc_html_e( 'the status applied to the renewal order and subscription', 'woocommerce-subscriptions' ); ?></li>
				</ul>
				<p><?php esc_html_e( 'The retry system is disabled by default. To enable it, visit the Subscriptions settings administration screen.', 'woocommerce-subscriptions' ); ?></p>
				<p class="woocommerce-actions">
					<a class="button button-primary" href="<?php echo esc_url( $settings_page ); ?>" ><?php esc_html_e( 'Enable Automatic Retry', 'woocommerce-subscriptions' ); ?></a>
					<a class="button" href="http://docs.woocommerce.com/document/subscriptions/failed-payment-retry/"><?php echo esc_html_x( 'Learn More', 'learn more link to failed payment retry documentation', 'woocommerce-subscriptions' ); ?></a>
				</p>
			</div>
		</div>

		<div class="feature-section two-col">
			<div class="col feature-image">
				<img src="<?php echo esc_url( wcs_get_image_asset_url( 'subscription-suspended-email.jpg' ) ); ?>" />
			</div>

			<div class="col last-feature feature-copy">
				<h3><?php esc_html_e( 'New Subscription Emails', 'woocommerce-subscriptions' ); ?></h3>
				<p><?php esc_html_e( 'Subscriptions 2.1 also introduces a number of new emails to notify you when:', 'woocommerce-subscriptions' ); ?></p>
				<ul>
					<li><?php esc_html_e( 'a customer suspends a subscription', 'woocommerce-subscriptions' ); ?></li>
					<li><?php esc_html_e( 'an automatic payment fails', 'woocommerce-subscriptions' ); ?></li>
					<li><?php esc_html_e( 'a subscription expires', 'woocommerce-subscriptions' ); ?></li>
				</ul>
				<p><?php printf( esc_html__( 'These emails can be enabled, disabled and customised under the %sWooCommerce > Settings > Emails%s administration screen.', 'woocommerce-subscriptions' ), '<strong>', '</strong>' ); ?></p>
				<p class="woocommerce-actions">
					<a class="button button-primary" href="<?php echo esc_url( $settings_page ); ?>" ><?php esc_html_e( 'View Email Settings', 'woocommerce-subscriptions' ); ?></a>
					<a class="button" href="https://docs.woocommerce.com/document/subscriptions/store-manager-guide/#section-8"><?php echo esc_html_x( 'Learn More', 'learn more link to subscription emails documentation', 'woocommerce-subscriptions' ); ?></a>
				</p>
			</div>
		</div>
	</div>

	<div class="changelog still-more">

		<h2><?php esc_html_e( "But wait, there's more!", 'woocommerce-subscriptions' ); ?></h2>
		<p><?php esc_html_e( "That's not all we've working on for the last 12 months when it comes to Subscriptions. We've also released mini-extensions to help you get the most from your subscription store.", 'woocommerce-subscriptions' ); ?></p>

		<div class="feature-section three-col">

			<div class="col">
				<img src="<?php echo esc_url( wcs_get_image_asset_url( 'gift-subscription.png' ) ); ?>" />
				<h3><?php esc_html_e( 'Subscription Gifting', 'woocommerce-subscriptions' ); ?></h3>
				<p><?php esc_html_e( 'What happens when a customer wants to purchase a subscription product for someone else?', 'woocommerce-subscriptions' ); ?></p>
				<p><?php esc_html_e( 'The Gifting extension makes it possible for one person to purchase a subscription product for someone else. It then shares control of the subscription between the purchaser and recipient, allowing both to manage the subscription over its lifecycle.', 'woocommerce-subscriptions' ); ?></p>
				<p><?php
					// translators: placeholders are for opening and closing link (<a>) tags
					printf( esc_html__( '%sLearn more &raquo;%s', 'woocommerce-subscriptions' ), '<a href="https://woocommerce.com/products/woocommerce-subscriptions-gifting/">', '</a>' ); ?>
				</p>
			</div>

			<div class="col">
				<img src="<?php echo esc_url( wcs_get_image_asset_url( 'subscriptions-importer-exporter.png' ) ); ?>" />
				<h3><?php echo esc_html_x( 'Import/Export Subscriptions', 'h3 on the About Subscriptions page for this new feature', 'woocommerce-subscriptions' ); ?></h3>
				<p><?php esc_html_e( 'Import subscriptions to WooCommerce via CSV, or export your subscriptions from WooCommerce to a CSV with the WooCommerce Subscriptions Importer/Exporter extension.', 'woocommerce-subscriptions' ); ?></p>
				<p><?php esc_html_e( 'This free extension makes it possible to migrate subscribers from 3rd party systems to WooCommerce. It also makes it possible to export your subscription data for analysis in spreadsheet tools or 3rd party apps.', 'woocommerce-subscriptions' ); ?></p>
				<p><?php
					// translators: placeholders are for opening and closing link (<a>) tags
					printf( esc_html__( '%sLearn more &raquo;%s', 'woocommerce-subscriptions' ), '<a href="https://github.com/Prospress/woocommerce-subscriptions-importer-exporter#woocommerce-subscriptions-importer-and-exporter">', '</a>' ); ?>
				</p>
			</div>

			<div class="col last-feature">
				<img src="<?php echo esc_url( wcs_get_image_asset_url( 'subscribe-all-the-things.png' ) ); ?>" />
				<h3><?php esc_html_e( 'Subscribe All the Things', 'woocommerce-subscriptions' ); ?></h3>
				<p><?php esc_html_e( 'Want your customers to be able to subscribe to non-subscription products?', 'woocommerce-subscriptions' ); ?></p>
				<p><?php esc_html_e( 'With WooCommerce Subscribe All the Things, they can! This experimental extension is exploring how to convert any product, including Product Bundles and Composite Products, into a subscription product. It also offers customers a way to subscribe to a cart of non-subscription products.', 'woocommerce-subscriptions' ); ?></p>
				<p><?php
					// translators: placeholders are for opening and closing link (<a>) tags
					printf( esc_html__( '%sLearn more &raquo;%s', 'woocommerce-subscriptions' ), '<a href="https://github.com/Prospress/woocommerce-subscribe-all-the-things#woocommerce-subscribe-all-the-things">', '</a>' ); ?>
				</p>
			</div>

		</div>
	</div>

	<div class="changelog">

		<h2><?php esc_html_e( 'Peek Under the Hood for Developers', 'woocommerce-subscriptions' ); ?></h2>

		<div class="feature-section under-the-hood three-col">
			<div class="col">
				<h3><?php
					// translators: placeholders are opening and closing <code> tags
					printf( esc_html__( 'Customise Retry Rules', 'woocommerce-subscriptions' ), '<code>', '</code>' ); ?>
				</h3>
				<p><?php esc_html_e( 'The best part about the new automatic retry system is that the retry rules are completely customisable.', 'woocommerce-subscriptions' ); ?></p>
				<p><?php
					// translators: all placeholders are opening and closing <code> tags, no need to order them
					printf( esc_html__( 'With the %s\'wcs_default_retry_rules\'%s filter, you can define a set of default rules to apply to all failed payments in your store.', 'woocommerce-subscriptions' ), '<code>', '</code>' ); ?>
				</p>
				<p><?php
					// translators: all placeholders are opening and closing <code> tags, no need to order them
					printf( esc_html__( 'To apply a specific rule based on certain conditions, like high value orders or an infrequent renewal schedule, you can use the retry specific %s\'wcs_get_retry_rule\'%s filter. This provides the ID of the renewal order for the failed payment, which can be used to find information about the products, subscription and totals to which the failed payment relates.', 'woocommerce-subscriptions' ), '<code>', '</code>' ); ?>
				</p>
				<p><?php
					// translators: placeholders are opening and closing anchor tags linking to documentation
					printf( esc_html__( '%sLearn more &raquo;%s', 'woocommerce-subscriptions' ), '<a href="http://docs.woocommerce.com/document/subscriptions/develop/failed-payment-retry/">', '</a>' ); ?>
				</p>
			</div>
			<div class="col">
				<h3><?php esc_html_e( 'WP REST API Endpoints', 'woocommerce-subscriptions' ); ?></h3>
				<p><?php
					// translators: $1: opening <a> tag linking to WC API docs, $2: closing <a> tag, $3: opening <a> tag linking to WP API docs, $4: closing <a> tag
					printf( esc_html__( 'WooCommerce 2.6 added support for %1$sREST API%2$s endpoints built on WordPress core\'s %3$sREST API%4$s infrastructure.', 'woocommerce-subscriptions' ), '<a href="http://woocommerce.github.io/woocommerce-rest-api-docs/">', '</a>', '<a href="http://v2.wp-api.org/">', '</a>' ); ?>
				</p>
				<p><?php esc_html_e( 'Subscriptions 2.1 adds support for subscription data to this infrastructure.', 'woocommerce-subscriptions' ); ?></p>
				<p><?php esc_html_e( 'Your applications can now create, read, update or delete subscriptions via RESTful API endpoints with the same design as the latest version of WooCommerce\'s REST API endpoints.', 'woocommerce-subscriptions' ); ?></p>
				<p><?php
					// translators: all placeholders are opening and closing <code> tags, no need to order them
					printf( esc_html__( 'Want to list all the subscriptions on a site? Get %s/wp-json/wc/v1/subscriptions%s.', 'woocommerce-subscriptions' ), '<code>', '</code>' ); ?>
				</p>
				<p><?php
					// translators: all placeholders are opening and closing <code> tags, no need to order them
					printf( esc_html__( 'Want the details of a specific subscription? Get %s/wp-json/wc/v1/subscriptions/<id>/%s.', 'woocommerce-subscriptions' ), '<code>', '</code>' ); ?>
				</p>
				<p><?php
					// translators: placeholders are opening and closing anchor tags linking to documentation
					printf( esc_html__( '%sLearn more &raquo;%s', 'woocommerce-subscriptions' ), '<a href="https://prospress.github.io/subscriptions-rest-api-docs/">', '</a>' ); ?>
				</p>
			</div>
			<div class="col last-feature">
				<h3><?php
					// translators: placeholders are opening and closing code tags
					printf( esc_html__( 'Honour Renewal Order Data', 'woocommerce-subscriptions' ), '<code>', '</code>' ); ?>
				</h3>
				<p><?php esc_html_e( 'In previous versions of Subscriptions, the subscription total was passed to payment gateways as the amount to charge for automatic renewal payments. This made it unnecessarily complicated to add one-time fees or discounts to a renewal.', 'woocommerce-subscriptions' ); ?></p>
				<p><?php
					// translators: placeholders are opening and closing <code> tags
					printf( esc_html__( 'Subscriptions 2.1 now passes the renewal order\'s total, making it possible to add a fee or discount to the renewal order with simple one-liners like %s$order->add_fee()%s or %s$order->add_coupon()%s.', 'woocommerce-subscriptions' ), '<code>', '</code>', '<code>', '</code>' ); ?>
				</p>
				<p><?php
					// translators: placeholders are opening and closing <a> tags
					printf( esc_html__( 'Subscriptions also now uses the renewal order to setup the cart for %smanual renewals%s, making it easier to add products or discounts to a single renewal paid manually.', 'woocommerce-subscriptions' ), '<a href="https://docs.woocommerce.com/document/subscriptions/renewal-process/">', '</a>' ); ?>
				</p>
			</div>
	</div>

	<div class="return-to-dashboard">
		<p><a href="<?php echo esc_url( 'http://docs.woocommerce.com/document/subscriptions/version-2-1/' ); ?>"><?php esc_html_e( 'See the full guide to What\'s New in Subscriptions version 2.1 &raquo;', 'woocommerce-subscriptions' ); ?></a></p>
		<p><a href="<?php echo esc_url( $settings_page ); ?>"><?php esc_html_e( 'Go to WooCommerce Subscriptions Settings &raquo;', 'woocommerce-subscriptions' ); ?></a></p>
	</div>
</div>
