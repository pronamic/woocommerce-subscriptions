<?php
/**
 * The template for displaying a modal.
 *
 * @version 2.6.0
 * @var WCS_Modal $modal
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div data-modal-trigger="<?php echo esc_attr( $modal->get_trigger() );?>" class="wcs-modal" tabindex="0">
	<article class="content-wrapper">
			<header class="modal-header">
				<?php if ( $modal->has_heading() ) : ?>
					<h2><?php echo esc_html( $modal->get_heading() ) ?></h2>
				<?php endif ?>
				<a href="#" onclick="return false;" class="close" style="text-decoration: none;"><span class="dashicons dashicons-no"></span></a>
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
