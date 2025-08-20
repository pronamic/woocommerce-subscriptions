<?php
/**
 * Outputs the Status section for Subscriptions Gifting.
 *
 * @package WooCommerce Subscriptions Gifting/Templates/Admin
 * @version 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $debug_data ) || ! is_array( $debug_data ) || empty( $debug_data ) ) {
	return;
}

?>
<table class="wc_status_table widefat" cellspacing="0">
	<thead>
	<tr>
		<th colspan="3" data-export-label="<?php echo esc_attr( $section_title ); ?>">
			<h2><?php echo esc_html( $section_title ); ?>
				<?php echo wc_help_tip( $section_tooltip ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped ?>
			</h2></th>
	</tr>
	</thead>
	<tbody>
	<?php
	foreach ( $debug_data as $section => $data ) :
		// Use mark key if available, otherwise default back to the success key.
		if ( isset( $data['mark'] ) ) {
			$mark = $data['mark'];
		} elseif ( isset( $data['success'] ) && $data['success'] ) {
			$mark = 'yes';
		} else {
			$mark = 'error';
		}

		// Use mark_icon key if available, otherwise set based on $mark.
		if ( isset( $data['mark_icon'] ) ) {
			$mark_icon = $data['mark_icon'];
		} elseif ( 'yes' === $mark ) {
			$mark_icon = 'yes';
		} else {
			$mark_icon = 'no-alt';
		}
		?>
		<tr>
			<td data-export-label="<?php echo esc_attr( $data['label'] ); ?>"><?php echo esc_html( $data['name'] ); ?>:
			</td>
			<td class="help">&nbsp;</td>
			<td>
				<?php
				if ( isset( $data['data'] ) ) {

					if ( empty( $data['data'] ) ) {
						echo '&ndash;';
						continue;
					}

					$row_number = count( $data['data'] );

					foreach ( $data['data'] as $row ) {
						echo wp_kses_post( $row );

						if ( 1 !== $row_number ) {
							echo ', ';
						}
						echo '<br />';
						$row_number--;
					}
				}
				if ( isset( $data['note'] ) ) {
					if ( empty( $mark ) ) {
						echo wp_kses_post( $data['note'] );
					} else {
						?>
						<mark class="<?php echo esc_html( $mark ); ?>">
							<?php
							if ( $mark_icon ) {
								echo '<span class="dashicons dashicons-' . esc_attr( $mark_icon ) . '"></span> ';
							}
							echo wp_kses_post( $data['note'] );
							?>
						</mark>
						<?php
					}
				}
				?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
