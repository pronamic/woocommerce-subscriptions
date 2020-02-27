<?php // translators: placeholder is Subscription version string ('2.3') ?>
<p><?php esc_html_e( 'Thank you for updating to the latest version of WooCommerce Subscriptions.', 'woocommerce-subscriptions' ); ?></p>
<p>
	<?php // translators: placeholder $1 is the Subscription version string ('2.3'), $2-3 are opening and closing <em> tags ?>
	<?php echo wp_kses_post( sprintf( __( 'Version %1$s brings some new improvements requested by store managers just like you (and possibly even by %2$syou%3$s).', 'woocommerce-subscriptions' ), $version, '<em>', '</em>' ) ); ?>
	<?php esc_html_e( 'We hope you enjoy it!', 'woocommerce-subscriptions' ); ?>
</p>
<h3><?php esc_html_e( "What's new?", 'woocommerce-subscriptions' ); ?></h3>
<ul style="list-style-type: disc; padding-left: 2em;">
	<?php foreach ( $features as $feature ) : ?>
		<li><b><?php echo wp_kses_post( $feature['title'] ); ?></b> &ndash; <?php echo wp_kses_post( $feature['description'] ); ?></li>
	<?php endforeach; ?>
</ul>
<hr>
<?php // translators: placeholder is Subscription version string ('2.3') ?>
<p><?php echo esc_html( sprintf( __( 'Want to know more about Subscriptions %s?', 'woocommerce-subscriptions' ), $version ) ); ?></p>
