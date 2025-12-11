<?php
/**
 * The template for displaying a modal.
 *
 * @version 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
 * @var WCS_Modal $modal
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div data-modal-trigger="<?php echo esc_attr( $modal->get_trigger() );?>" class="wcs-modal" id="<?php echo esc_attr( $modal->get_id() ); ?>" tabindex="0">
	<?php
	$article_attributes = 'class="content-wrapper" role="dialog" aria-modal="true"';
	if ( $modal->has_heading() ) {
		$article_attributes .= ' aria-labelledby="' . esc_attr( $modal->get_id() . '-heading' ) . '"';
	}
	?>
	<article <?php echo $article_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="modal-header">
				<?php if ( $modal->has_heading() ) : ?>
					<h2 id="<?php echo esc_attr( $modal->get_id() . '-heading' ); ?>"><?php echo esc_html( $modal->get_heading() ) ?></h2>
				<?php endif ?>
				<button type="button" class="close" aria-label="<?php esc_attr_e( 'Close modal', 'woocommerce-subscriptions' ); ?>"><span class="dashicons dashicons-no"></span></button>
			</header>

		<div class="content">
			<?php $modal->print_content(); ?>
		</div>
		<?php if ( $modal->has_actions() ) : ?>
			<footer class="modal-footer"><?php
			foreach ( $modal->get_actions() as $action ) {
				$element_type = $action['type'];
				$attributes   = $modal->get_attribute_string( $action['attributes'] );

				echo wp_kses_post( "<{$element_type} {$attributes}>{$action['text']}</{$element_type}>" );
			}?>
			</footer>
		<?php endif ?>
	</article>
</div>
