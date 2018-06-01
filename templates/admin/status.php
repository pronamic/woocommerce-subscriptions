<?php
/**
 * Outputs the Status section for Subscriptions.
 *
 * @version 2.2.17
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $debug_data ) || ! is_array( $debug_data ) ) {
	return;
}

?>
<table class="wc_status_table widefat" cellspacing="0">
	<thead>
	<tr>
		<th colspan="3" data-export-label="subscriptions">
			<h2><?php esc_html_e( 'Subscriptions', 'woocommerce-subscriptions' ); ?>
				<?php echo wcs_help_tip( __( 'This section shows any information about Subscriptions.', 'woocommerce-subscriptions' ) ); ?>
			</h2></th>
	</tr>
	</thead>
	<tbody>
	<?php foreach ( $debug_data as $section => $data ) {
		// Use mark key if available, otherwise default back to the success key.
		if ( isset( $data['mark'] ) ) {
			$mark = $data['mark'];
		} elseif ( isset( $data['success'] ) && $data['success'] ) {
			$mark = 'yes';
		} else {
			$mark = 'error';
		}

		// Use mark_icon key if available, otherwise set based on $mark
		if ( isset( $data['mark_icon'] ) ) {
			$mark_icon = $data['mark_icon'];
		} elseif ( 'yes' === $mark ) {
			$mark_icon = 'yes';
		} else {
			$mark_icon = 'no-alt';
		}

		?>
		<tr>
			<td data-export-label="<?php echo esc_attr( $data['name'] ) ?>"><?php echo esc_html( $data['name'] ) ?>:</td>
			<td class="help">&nbsp;</td>
			<td>
				<?php
				// If this isn't theme overrides, keep it simple.
				if ( 'wcs_theme_overrides' !== $section ) {
					?>
					<mark class="<?php echo esc_html( $mark ) ?>">
						<span class="dashicons dashicons-<?php echo esc_attr( $mark_icon ) ?>"></span>
						<?php echo wp_kses_data( $data['note'] ); ?>
					</mark>
					<?php
					continue;
				}

				// If we don't have template data, continue early.
				if ( empty( $data['data'] ) || ! isset( $data['data']['overridden_templates'] ) || empty( $data['data']['overridden_templates'] ) ) {
					echo '&ndash;';
					continue;
				}

				foreach ( $data['data']['overridden_templates'] as $override ) {
					printf( '<code>%s</code> ', esc_html( $override['file'] ) );
					if ( $override['is_outdated'] ) {
						printf(
							/* translators: %1$s is the file version, %2$s is the core version */
							esc_html__( 'version %1$s is out of date. The core version is %2$s', 'woocommerce-subscriptions' ),
							'<strong style="color:red">' . esc_html( $override['version'] ) . '</strong>',
							'<strong>' . esc_html( $override['core_version'] ) . '</strong>'
						);
					}
					echo '<br />';
				}

				if ( $data['data']['has_outdated_templates'] ) {
					?>
					<br />
					<mark class="error"><span class="dashicons dashicons-warning"></span></mark>
					<a href="https://docs.woocommerce.com/document/fix-outdated-templates-woocommerce/" target="_blank">
						<?php esc_html_e( 'Learn how to update', 'woocommerce-subscriptions' ) ?>
					</a>
					<?php
				}

			?>
			</td>
		</tr>
	<?php } ?>
	</tbody>
</table>
