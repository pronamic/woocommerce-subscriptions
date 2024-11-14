<?php
/**
 * The template for displaying an admin notice.
 *
 * @version 1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
 * @var WCS_Admin_Notice $notice
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div <?php $notice->print_attributes(); ?>>
	<?php if ( $notice->has_heading() ) : ?>
		<h2><?php $notice->print_heading(); ?></h2>
	<?php endif; ?>

	<?php $notice->print_content(); ?>

	<?php if ( $notice->has_actions() ) : ?>
		<p><?php foreach ( $notice->get_actions() as $action ) { ?>
				<a class="<?php echo esc_attr( isset( $action['class'] ) ? $action['class'] : 'docs button' ) ?>" href="<?php echo esc_url( $action['url'] ) ?>"><?php echo esc_html( $action['name'] ) ?></a>
			<?php } ?>
		</p>
	<?php endif; ?>

	<?php if ( $notice->is_dismissible() ) : ?>
		<a href="<?php $notice->print_dismiss_url(); ?>" type="button" class="notice-dismiss" style="text-decoration: none;"></a>
	<?php endif; ?>
</div>
