<?php
/**
 * Order Data
 *
 * Functions for displaying the order data meta box.
 *
 * @author   Prospress
 * @category Admin
 * @package  WooCommerce Subscriptions/Admin/Meta Boxes
 * @version  2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * WCS_Meta_Box_Subscription_Data Class
 */
class WCS_Meta_Box_Subscription_Data extends WC_Meta_Box_Order_Data {

	/**
	 * Output the metabox
	 */
	public static function output( $post ) {
		global $the_subscription;

		if ( ! is_object( $the_subscription ) || $the_subscription->id !== $post->ID ) {
			$the_subscription = wc_get_order( $post->ID );
		}

		$subscription = $the_subscription;

		self::init_address_fields();

		wp_nonce_field( 'woocommerce_save_data', 'woocommerce_meta_nonce' );
		?>
		<style type="text/css">
			#post-body-content, #titlediv, #major-publishing-actions, #minor-publishing-actions, #visibility, #submitdiv { display:none }
		</style>
		<div class="panel-wrap woocommerce">
			<input name="post_title" type="hidden" value="<?php echo empty( $post->post_title ) ? esc_attr( get_post_type_object( $subscription->post->post_type )->labels->singular_name ) : esc_attr( $post->post_title ); ?>" />
			<input name="post_status" type="hidden" value="<?php echo esc_attr( 'wc-' . $subscription->get_status() ); ?>" />
			<div id="order_data" class="panel">

				<h2><?php
				// translators: placeholder is the ID of the subscription
				printf( esc_html_x( 'Subscription #%s details', 'edit subscription header', 'woocommerce-subscriptions' ), esc_html( $subscription->get_order_number() ) ); ?></h2>

				<div class="order_data_column_container">
					<div class="order_data_column">

						<p class="form-field form-field-wide wc-customer-user">
							<label for="customer_user"><?php esc_html_e( 'Customer:', 'woocommerce-subscriptions' ) ?> <?php
							if ( ! empty( $subscription->customer_user ) ) {
								$args = array(
									'post_status' => 'all',
									'post_type'      => 'shop_subscription',
									'_customer_user' => absint( $subscription->customer_user ),
								);
								printf( '<a href="%s">%s &rarr;</a>',
									esc_url( add_query_arg( $args, admin_url( 'edit.php' ) ) ),
									esc_html__( 'View other subscriptions', 'woocommerce-subscriptions' )
								);
							}
							?></label>
							<?php
							$user_string = '';
							$user_id     = '';
							if ( ! empty( $subscription->customer_user ) && ( false !== get_userdata( $subscription->customer_user ) ) ) {
								$user_id     = absint( $subscription->customer_user );
								$user        = get_user_by( 'id', $user_id );
								$user_string = esc_html( $user->display_name ) . ' (#' . absint( $user->ID ) . ' &ndash; ' . esc_html( $user->user_email );
							}
							?>
							<input type="hidden" class="wc-customer-search" id="customer_user" name="customer_user" data-placeholder="<?php esc_attr_e( 'Search for a customer&hellip;', 'woocommerce-subscriptions' ); ?>" data-selected="<?php echo esc_attr( $user_string ); ?>" value="<?php echo esc_attr( $user_id ); ?>" />
						</p>

						<p class="form-field form-field-wide">
							<label for="order_status"><?php esc_html_e( 'Subscription Status:', 'woocommerce-subscriptions' ); ?></label>
							<select id="order_status" name="order_status">
								<?php
								$statuses = wcs_get_subscription_statuses();
								foreach ( $statuses as $status => $status_name ) {
									if ( ! $subscription->can_be_updated_to( $status ) && ! $subscription->has_status( str_replace( 'wc-', '', $status ) ) ) {
										continue;
									}
									echo '<option value="' . esc_attr( $status ) . '" ' . selected( $status, 'wc-' . $subscription->get_status(), false ) . '>' . esc_html( $status_name ) . '</option>';
								}
								?>
							</select>
						</p>

						<?php do_action( 'woocommerce_admin_order_data_after_order_details', $subscription ); ?>

					</div>
					<div class="order_data_column">
						<h4><?php esc_html_e( 'Billing Details', 'woocommerce-subscriptions' ); ?> <a class="edit_address" href="#"><a href="#" class="tips load_customer_billing" data-tip="Load billing address" style="display:none;">Load billing address</a></a></h4>
						<?php
						// Display values
						echo '<div class="address">';

						if ( $subscription->get_formatted_billing_address() ) {
							echo '<p><strong>' . esc_html__( 'Address', 'woocommerce-subscriptions' ) . ':</strong>' . wp_kses( $subscription->get_formatted_billing_address(), array( 'br' => array() ) ) . '</p>';
						} else {
							echo '<p class="none_set"><strong>' . esc_html__( 'Address', 'woocommerce-subscriptions' ) . ':</strong> ' . esc_html__( 'No billing address set.', 'woocommerce-subscriptions' ) . '</p>';
						}

						foreach ( self::$billing_fields as $key => $field ) {

							if ( isset( $field['show'] ) && false === $field['show'] ) {
								continue;
							}

							$field_name = 'billing_' . $key;

							if ( $subscription->$field_name ) {
								echo '<p><strong>' . esc_html( $field['label'] ) . ':</strong> ' . wp_kses_post( make_clickable( esc_html( $subscription->$field_name ) ) ) . '</p>';
							}
						}

						echo '<p' . ( ! empty( $subscription->payment_method ) ? ' class="' . esc_attr( $subscription->payment_method ) . '"' : '' ) . '><strong>' . esc_html__( 'Payment Method', 'woocommerce-subscriptions' ) . ':</strong>' . wp_kses_post( nl2br( $subscription->get_payment_method_to_display() ) );

						// Display help tip
						if ( ! empty( $subscription->payment_method ) && ! $subscription->is_manual() ) {
							echo '<img class="help_tip" data-tip="Gateway ID: [' . esc_attr( $subscription->payment_gateway->id ) . ']" src="' . esc_url( WC()->plugin_url() ) . '/assets/images/help.png" height="16" width="16" />';
						}

						echo '</p>';

						echo '</div>';

						// Display form
						echo '<div class="edit_address">';

						foreach ( self::$billing_fields as $key => $field ) {
							if ( ! isset( $field['type'] ) ) {
								$field['type'] = 'text';
							}

							switch ( $field['type'] ) {
								case 'select' :
									// allow for setting a default value programaticaly, and draw the selectbox
									woocommerce_wp_select( array( 'id' => '_billing_' . $key, 'label' => $field['label'], 'options' => $field['options'], 'value' => isset( $field['value'] ) ? $field['value'] : null ) );
									break;
								default :
									// allow for setting a default value programaticaly, and draw the textbox
									woocommerce_wp_text_input( array( 'id' => '_billing_' . $key, 'label' => $field['label'], 'value' => isset( $field['value'] ) ? $field['value'] : null ) );
									break;
							}
						}
						WCS_Change_Payment_Method_Admin::display_fields( $subscription );

						echo '</div>';

						do_action( 'woocommerce_admin_order_data_after_billing_address', $subscription );
						?>
					</div>
					<div class="order_data_column">

						<h4><?php esc_html_e( 'Shipping Details', 'woocommerce-subscriptions' ); ?>
							<a class="edit_address" href="#">
								<a href="#" class="tips billing-same-as-shipping" data-tip="Copy from billing" style="display:none;">Copy from billing</a>
								<a href="#" class="tips load_customer_shipping" data-tip="Load shipping address" style="display:none;">Load shipping address</a>
							</a>
						</h4>
						<?php
						// Display values
						echo '<div class="address">';

						if ( $subscription->get_formatted_shipping_address() ) {
							echo '<p><strong>' . esc_html__( 'Address', 'woocommerce-subscriptions' ) . ':</strong>' . wp_kses( $subscription->get_formatted_shipping_address(), array( 'br' => array() ) ) . '</p>';
						} else {
							echo '<p class="none_set"><strong>' . esc_html__( 'Address', 'woocommerce-subscriptions' ) . ':</strong> ' . esc_html__( 'No shipping address set.', 'woocommerce-subscriptions' ) . '</p>';
						}

						if ( self::$shipping_fields ) {
							foreach ( self::$shipping_fields as $key => $field ) {
								if ( isset( $field['show'] ) && false === $field['show'] ) {
									continue;
								}

								$field_name = 'shipping_' . $key;

								if ( ! empty( $subscription->$field_name ) ) {
									echo '<p><strong>' . esc_html( $field['label'] ) . ':</strong> ' . wp_kses_post( make_clickable( esc_html( $subscription->$field_name ) ) ) . '</p>';
								}
							}
						}

						if ( apply_filters( 'woocommerce_enable_order_notes_field', 'yes' == get_option( 'woocommerce_enable_order_comments', 'yes' ) ) && $post->post_excerpt ) {
							echo '<p><strong>' . esc_html__( 'Customer Note:', 'woocommerce-subscriptions' ) . '</strong> ' . wp_kses_post( nl2br( $post->post_excerpt ) ) . '</p>';
						}

						echo '</div>';

						// Display form
						echo '<div class="edit_address">';

						if ( self::$shipping_fields ) {
							foreach ( self::$shipping_fields as $key => $field ) {
								if ( ! isset( $field['type'] ) ) {
									$field['type'] = 'text';
								}

								switch ( $field['type'] ) {
									case 'select' :
										woocommerce_wp_select( array( 'id' => '_shipping_' . $key, 'label' => $field['label'], 'options' => $field['options'] ) );
										break;
									default :
										woocommerce_wp_text_input( array( 'id' => '_shipping_' . $key, 'label' => $field['label'] ) );
										break;
								}
							}
						}

						if ( apply_filters( 'woocommerce_enable_order_notes_field', 'yes' == get_option( 'woocommerce_enable_order_comments', 'yes' ) ) ) {
							?>
							<p class="form-field form-field-wide"><label for="excerpt"><?php esc_html_e( 'Customer Note:', 'woocommerce-subscriptions' ) ?></label>
								<textarea rows="1" cols="40" name="excerpt" tabindex="6" id="excerpt" placeholder="<?php esc_attr_e( 'Customer\'s notes about the order', 'woocommerce-subscriptions' ); ?>"><?php echo wp_kses_post( $post->post_excerpt ); ?></textarea></p>
								<?php
						}

						echo '</div>';

						do_action( 'woocommerce_admin_order_data_after_shipping_address', $subscription );
						?>
					</div>
				</div>
				<div class="clear"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Save meta box data
	 */
	public static function save( $post_id, $post ) {
		global $wpdb;

		if ( 'shop_subscription' != $post->post_type || empty( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( $_POST['woocommerce_meta_nonce'], 'woocommerce_save_data' ) ) {
			return;
		}

		self::init_address_fields();

		// Update meta
		update_post_meta( $post_id, '_customer_user', absint( $_POST['customer_user'] ) );

		if ( self::$billing_fields ) {
			foreach ( self::$billing_fields as $key => $field ) {
				update_post_meta( $post_id, '_billing_' . $key, wc_clean( $_POST[ '_billing_' . $key ] ) );
			}
		}

		if ( self::$shipping_fields ) {
			foreach ( self::$shipping_fields as $key => $field ) {
				update_post_meta( $post_id, '_shipping_' . $key, wc_clean( $_POST[ '_shipping_' . $key ] ) );
			}
		}

		$subscription = wcs_get_subscription( $post_id );

		try {
			WCS_Change_Payment_Method_Admin::save_meta( $subscription );

			if ( 'cancelled' == $_POST['order_status'] ) {
				$subscription->cancel_order();
			} else {
				$subscription->update_status( $_POST['order_status'], '', true );
			}
		} catch ( Exception $e ) {
			// translators: placeholder is error message from the payment gateway or subscriptions when updating the status
			wcs_add_admin_notice( sprintf( __( 'Error updating subscription: %s', 'woocommerce-subscriptions' ), $e->getMessage() ), 'error' );
		}

		do_action( 'woocommerce_process_shop_subscription_meta', $post_id, $post );
	}

}
