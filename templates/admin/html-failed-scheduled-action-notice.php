<?php
/**
 * The template for displaying an admin notice to report failed Subscriptions related scheduled actions.
 *
 * @version 2.4.0
 * @var array $failed_scheduled_actions
 * @var string $affected_subscription_events
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<p><?php
	// translators: $1 and $2 are opening and closing link tags, respectively.
	printf(
		esc_html( _n(
			'An error has occurred while processing a recent subscription related event. Please %1$sopen a new ticket at WooCommerce Support%2$s immediately to get this resolved.',
			'An error has occurred while processing recent subscription related events. Please %1$sopen a new ticket at WooCommerce Support%2$s immediately to get this resolved.',
			count( $failed_scheduled_actions ),
			'woocommerce-subscriptions'
		) ),
		'<a href="https://woocommerce.com/my-account/marketplace-ticket-form/" target="_blank">',
		'</a>'
	);?>
</p>
<p><?php
	// translators: $1 and $2 are opening and closing link tags, respectively.
	printf(
		esc_html__( 'To resolve this error as quickly as possible, please create a %1$stemporary administrator account%2$s with the user email support@prospress.com.', 'woocommerce-subscriptions' ),
		'<a href="https://docs.woocommerce.com/document/create-new-admin-account-wordpress/" target="_blank">',
		'</a>'
	);?>
</p>
<?php echo esc_html( _n( 'Affected event:', 'Affected events:', count( $failed_scheduled_actions ) , 'woocommerce-subscriptions' ) ); ?>
<code style="display: block; white-space: pre-wrap"><?php
	echo wp_kses( $affected_subscription_events, array( 'a' => array( 'href' => array() ) ) ); ?>
</code>
<p><?php
	// translators: $1 the log file name $2 and $3 are opening and closing link tags, respectively.
	printf(
		esc_html__( 'To see further details, view the %1$s log file from the %2$sWooCommerce logs screen.%2$s','woocommerce-subscriptions' ),
		'<code>failed-scheduled-actions</code>',
		'<a href="' . esc_url( admin_url( sprintf( 'admin.php?page=wc-status&tab=logs&log_file=%s-%s-log', 'failed-scheduled-actions', sanitize_file_name( wp_hash( 'failed-scheduled-actions' ) ) ) ) )  . '">',
		'</a>'
	);?>
</p>

