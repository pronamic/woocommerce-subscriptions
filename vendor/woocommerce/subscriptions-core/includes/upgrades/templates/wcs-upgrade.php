<?php
/**
 * Upgrade helper template
 *
 * @author		Prospress
 * @category	Admin
 * @package		WooCommerce Subscriptions/Admin/Upgrades
 * @version		2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" <?php language_attributes(); ?>>
	<head>
		<meta http-equiv="Content-Type" content="<?php bloginfo( 'html_type' ); ?>; charset=<?php echo esc_attr( get_option( 'blog_charset' ) ); ?>" />
		<title><?php esc_html_e( 'WooCommerce Subscriptions Update', 'woocommerce-subscriptions' ); ?></title>
		<?php wp_admin_css( 'install', true ); ?>
		<?php wp_admin_css( 'ie', true ); ?>
		<?php wp_print_styles( 'wcs-upgrade' ); ?>
		<?php wp_print_scripts( 'jquery' ); ?>
		<?php wp_print_scripts( 'wcs-upgrade' ); ?>
	</head>
	<body class="wp-core-ui">
		<h1 id="logo"><img alt="WooCommerce Subscriptions" width="325px" height="120px" src="<?php echo esc_url( wcs_get_image_asset_url( 'woocommerce_subscriptions_logo.png' ) ); ?>" /></h1>
		<div id="update-welcome">
			<h2><?php esc_html_e( 'Database Update Required', 'woocommerce-subscriptions' ); ?></h2>
			<p><?php esc_html_e( 'The WooCommerce Subscriptions plugin has been updated!', 'woocommerce-subscriptions' ); ?></p>
			<p><?php
				// translators: placeholders are opening and closing tags
				printf( esc_html__( 'Before we send you on your way, we need to update your database to the newest version. If you do not have a recent backup of your site, %snow is the time to create one%s.', 'woocommerce-subscriptions' ), '<a target="_blank" href="https://codex.wordpress.org/Backing_Up_Your_Database">', '</a>' ); ?>
			</p>
			<?php if ( 'false' == $script_data['really_old_version'] ) : ?>
			<p><?php
				// translators: 1$: number of subscriptions on site, 2$, lower estimate (minutes), 3$: upper estimate
				printf( esc_html__( 'The full update process for the %1$d subscriptions on your site will take between %2$d and %3$d minutes.', 'woocommerce-subscriptions' ), esc_html( $subscription_count ), esc_html( round( $estimated_duration * 0.75 ) ), esc_html( round( $estimated_duration * 1.5 ) ) ); ?>
			</p>
			<?php else : ?>
			<p><?php esc_html_e( 'The update process may take a little while, so please be patient.', 'woocommerce-subscriptions' ); ?></p>
			<?php endif; ?>
			<p><?php esc_html_e( 'Customers and other non-administrative users can browse and purchase from your store without interruption while the update is in progress.', 'woocommerce-subscriptions' ); ?></p>
			<form id="subscriptions-upgrade" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="submit" class="button" value="<?php echo esc_attr_x( 'Update Database', 'text on submit button', 'woocommerce-subscriptions' ); ?>">
			</form>
		</div>
		<div id="update-messages">
			<h2><?php esc_html_e( 'Update in Progress', 'woocommerce-subscriptions' ); ?></h2>
			<p><?php esc_html_e( 'This page will display the results of the process as each batch of subscriptions is updated.', 'woocommerce-subscriptions' ); ?></p>
			<p><?php esc_html_e( 'Please keep this page open until the update process completes. No need to refresh or restart the process.', 'woocommerce-subscriptions' ); ?></p>
			<?php if ( isset( $estimated_duration ) && $estimated_duration > 20 ) : ?>
			<p><?php esc_html_e( 'Remember, although the update process may take a while, customers and other non-administrative users can browse and purchase from your store without interruption while the update is in progress.', 'woocommerce-subscriptions' ); ?></p>
			<?php endif; ?>
			<ol>
			</ol>
			<img id="update-ajax-loader" alt="loading..." width="16px" height="16px" src="<?php echo esc_url( wcs_get_image_asset_url( 'ajax-loader@2x.gif' ) ); ?>" />
			<p id="estimated_time"></p>
		</div>
		<div id="update-complete">
			<h2><?php esc_html_e( 'Update Complete', 'woocommerce-subscriptions' ); ?></h2>
			<p><?php esc_html_e( 'Your database has been updated successfully!', 'woocommerce-subscriptions' ); ?></p>
			<p class="step"><a class="button" href="<?php echo esc_url( $about_page_url ); ?>"><?php esc_html_e( 'Continue', 'woocommerce-subscriptions' ); ?></a></p>
			<p class="log-notice"><?php
				// translators: $1: placeholder is number of weeks, 2$: path to the file
				echo wp_kses( sprintf( __( 'To record the progress of the update a new log file was created. This file will be automatically deleted in %1$d weeks. If you would like to delete it sooner, you can find it here: %2$s', 'woocommerce-subscriptions' ), esc_html( WCS_Upgrade_Logger::$weeks_until_cleanup ), '<code class="log-notice">' . esc_html( wc_get_log_file_path( WCS_Upgrade_Logger::$handle ) ) . '</code>' ), array( 'code' => array( 'class' => true ) ) );
				?>
			</p>
		</div>
		<div id="update-error">
			<h2><?php esc_html_e( 'Update Error', 'woocommerce-subscriptions' ); ?></h2>
			<p><?php esc_html_e( 'There was an error with the update. Please refresh the page and try again.', 'woocommerce-subscriptions' ); ?></p>
		</div>
	</body>
</html>
