<?php
/**
 * Order Data
 *
 * Functions for displaying the order data meta box.
 *
 * @category Admin
 * @package  WooCommerce Subscriptions/Admin/Meta Boxes
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * WCS_Meta_Box_Subscription_Data Class
 */
class WCS_Meta_Box_Subscription_Data extends WC_Meta_Box_Order_Data {

	/**
	 * Outputs the Subscription data metabox.
	 *
	 * @param WC_Subscription|WP_Post $subscription The subscription object to display the data metabox for. On CPT stores, this will be a WP Post object.
	 */
	public static function output( $subscription ) {
		global $the_subscription;

		if ( $subscription instanceof WP_Post ) {
			$subscription = wcs_get_subscription( $subscription->ID );
		}

		if ( ! is_object( $the_subscription ) || $the_subscription->get_id() !== $subscription->get_id() ) {
			$the_subscription = $subscription;
		}

		self::init_address_fields();

		wp_nonce_field( 'woocommerce_save_data', 'woocommerce_meta_nonce' );

		$subscription_title = $subscription->get_data_store()->get_title( $subscription );
		?>
		<style type="text/css">
			#post-body-content, #titlediv, #major-publishing-actions, #minor-publishing-actions, #visibility, #submitdiv { display:none }
		</style>
		<div class="panel-wrap woocommerce">
			<input name="post_title" type="hidden" value="<?php echo empty( $order_title ) ? esc_attr( get_post_type_object( $subscription->get_type() )->labels->singular_name ) : esc_attr( $subscription_title ); ?>" />
			<input name="post_status" type="hidden" value="<?php echo esc_attr( 'wc-' . $subscription->get_status() ); ?>" />
			<div id="order_data" class="panel">

				<h2>
				<?php
				// translators: placeholder is the ID of the subscription
				printf( esc_html_x( 'Subscription #%s details', 'edit subscription header', 'woocommerce-subscriptions' ), esc_html( $subscription->get_order_number() ) );
				?>
				</h2>

				<div class="order_data_column_container">
					<div class="order_data_column">
						<h3><?php esc_html_e( 'General', 'woocommerce-subscriptions' ); ?></h3>

						<p class="form-field form-field-wide wc-customer-user">
							<label for="customer_user"><?php esc_html_e( 'Customer:', 'woocommerce-subscriptions' ); ?> <?php
							if ( $subscription->get_user_id() ) {
								$args = array(
									'post_status'    => 'all',
									'post_type'      => 'shop_subscription',
									'_customer_user' => absint( $subscription->get_user_id() ),
								);
								printf(
									'<a href="%s">%s</a>',
									esc_url( add_query_arg( $args, admin_url( 'edit.php' ) ) ),
									esc_html__( 'View other subscriptions &rarr;', 'woocommerce-subscriptions' )
								);
								printf(
									'<a href="%s">%s</a>',
									esc_url( add_query_arg( 'user_id', $subscription->get_user_id(), admin_url( 'user-edit.php' ) ) ),
									esc_html__( 'Profile &rarr;', 'woocommerce-subscriptions' )
								);
							}
							?>
							</label>
							<?php
							$user_string = '';
							$user_id     = '';
							if ( $subscription->get_user_id() && ( false !== get_userdata( $subscription->get_user_id() ) ) ) {
								$user_id     = absint( $subscription->get_user_id() );
								$user        = get_user_by( 'id', $user_id );
								$user_string = esc_html( $user->display_name ) . ' (#' . absint( $user->ID ) . ' &ndash; ' . esc_html( $user->user_email ) . ')';
							}
							WCS_Select2::render(
								array(
									'class'       => 'wc-customer-search',
									'name'        => 'customer_user',
									'id'          => 'customer_user',
									'placeholder' => esc_attr__( 'Search for a customer&hellip;', 'woocommerce-subscriptions' ),
									'selected'    => $user_string,
									'value'       => $user_id,
								)
							);
							?>
						</p>

						<p class="form-field form-field-wide">
							<label for="order_status"><?php esc_html_e( 'Subscription status:', 'woocommerce-subscriptions' ); ?></label>
							<select id="order_status" name="order_status" class="wc-enhanced-select">
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
						<?php
						$parent_order = $subscription->get_parent();
						if ( $parent_order ) {
							?>
						<p class="form-field form-field-wide">
							<?php echo esc_html__( 'Parent order: ', 'woocommerce-subscriptions' ); ?>
						<a href="<?php echo esc_url( wcs_get_edit_post_link( $subscription->get_parent_id() ) ); ?>">
							<?php
							// translators: placeholder is an order number.
							echo sprintf( esc_html__( '#%1$s', 'woocommerce-subscriptions' ), esc_html( $parent_order->get_order_number() ) );
							?>
						</a>
						</p>
							<?php
						} else {
							?>
						<p class="form-field form-field-wide">
							<label for="parent-order-id"><?php esc_html_e( 'Parent order:', 'woocommerce-subscriptions' ); ?> </label>
							<?php
							WCS_Select2::render(
								array(
									'class'       => 'wc-enhanced-select',
									'name'        => 'parent-order-id',
									'id'          => 'parent-order-id',
									'placeholder' => esc_attr__( 'Select an order&hellip;', 'woocommerce-subscriptions' ),
								)
							);
							?>
						</p>
							<?php
						}
						do_action( 'woocommerce_admin_order_data_after_order_details', $subscription );
						?>

					</div>
					<div class="order_data_column">
						<h3>
							<?php esc_html_e( 'Billing', 'woocommerce-subscriptions' ); ?>
							<a href="#" class="edit_address"><?php esc_html_e( 'Edit', 'woocommerce-subscriptions' ); ?></a>
							<span>
								<a href="#" class="load_customer_billing" style="display:none;"><?php esc_html_e( 'Load billing address', 'woocommerce-subscriptions' ); ?></a>
							</span>
						</h3>
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

							$function_name = 'get_billing_' . $key;

							if ( is_callable( array( $subscription, $function_name ) ) ) {
								$field_value = $subscription->$function_name( 'edit' );
							} else {
								$field_value = $subscription->get_meta( '_billing_' . $key );
							}

							echo '<p><strong>' . esc_html( $field['label'] ) . ':</strong> ' . wp_kses_post( make_clickable( esc_html( $field_value ) ) ) . '</p>';
						}

						echo '<p' . ( ( '' != $subscription->get_payment_method() ) ? ' class="' . esc_attr( $subscription->get_payment_method() ) . '"' : '' ) . '><strong>' . esc_html__( 'Payment method', 'woocommerce-subscriptions' ) . ':</strong>' . wp_kses_post( nl2br( $subscription->get_payment_method_to_display() ) ); // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison

						// Display help tip
						if ( '' != $subscription->get_payment_method() && ! $subscription->is_manual() ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
							// translators: %s: gateway ID.
							echo wcs_help_tip( sprintf( _x( 'Gateway ID: [%s]', 'The gateway ID displayed on the Edit Subscriptions screen when editing payment method.', 'woocommerce-subscriptions' ), $subscription->get_payment_method() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}

						echo '</p>';

						echo '</div>';

						// Display form
						echo '<div class="edit_address">';

						foreach ( self::$billing_fields as $key => $field ) {
							if ( ! isset( $field['type'] ) ) {
								$field['type'] = 'text';
							}
							if ( ! isset( $field['id'] ) ) {
								$field['id'] = '_billing_' . $key;
							}

							switch ( $field['type'] ) {
								case 'select':
									wcs_woocommerce_wp_select( $field, $subscription );
									break;
								default:
									if ( is_callable( array( $subscription, 'get_billing_' . $key ) ) ) {
										$field['value'] = $subscription->{"get_billing_$key"}();
									} else {
										$field['value'] = $subscription->get_meta( $field['id'] );
									}

									woocommerce_wp_text_input( $field );
									break;
							}
						}

						WCS_Change_Payment_Method_Admin::display_fields( $subscription );

						echo '</div>';

						do_action( 'woocommerce_admin_order_data_after_billing_address', $subscription );

						// Display a link to the customer's add/change payment method screen.
						if ( $subscription->can_be_updated_to( 'new-payment-method' ) ) {

							if ( $subscription->has_payment_gateway() ) {
								$link_text = __( 'Customer change payment method page &rarr;', 'woocommerce-subscriptions' );
							} else {
								$link_text = __( 'Customer add payment method page &rarr;', 'woocommerce-subscriptions' );
							}

							printf(
								'<a href="%s">%s</a>',
								esc_url( $subscription->get_change_payment_method_url() ),
								esc_html( $link_text )
							);
						}
						?>
					</div>
					<div class="order_data_column">

						<h3>
							<?php esc_html_e( 'Shipping', 'woocommerce-subscriptions' ); ?>
							<a href="#" class="edit_address"><?php esc_html_e( 'Edit', 'woocommerce-subscriptions' ); ?></a>
							<span>
								<a href="#" class="load_customer_shipping" style="display:none;"><?php esc_html_e( 'Load shipping address', 'woocommerce-subscriptions' ); ?></a>
								<a href="#" class="billing-same-as-shipping" style="display:none;"><?php esc_html_e( 'Copy billing address', 'woocommerce-subscriptions' ); ?></a>
							</span>
						</h3>
						<?php
						// Display values
						echo '<div class="address">';

						if ( $subscription->get_formatted_shipping_address() ) {
							echo '<p><strong>' . esc_html__( 'Address', 'woocommerce-subscriptions' ) . ':</strong>' . wp_kses( $subscription->get_formatted_shipping_address(), array( 'br' => array() ) ) . '</p>';
						} else {
							echo '<p class="none_set"><strong>' . esc_html__( 'Address', 'woocommerce-subscriptions' ) . ':</strong> ' . esc_html__( 'No shipping address set.', 'woocommerce-subscriptions' ) . '</p>';
						}

						if ( ! empty( self::$shipping_fields ) ) {
							foreach ( self::$shipping_fields as $key => $field ) {
								if ( isset( $field['show'] ) && false === $field['show'] ) {
									continue;
								}

								$function_name = 'get_shipping_' . $key;

								if ( is_callable( array( $subscription, $function_name ) ) ) {
									$field_value = $subscription->$function_name( 'edit' );
								} else {
									$field_value = $subscription->get_meta( '_shipping_' . $key );
								}

								echo '<p><strong>' . esc_html( $field['label'] ) . ':</strong> ' . wp_kses_post( make_clickable( esc_html( $field_value ) ) ) . '</p>';
							}
						}

						if ( apply_filters( 'woocommerce_enable_order_notes_field', 'yes' === get_option( 'woocommerce_enable_order_comments', 'yes' ) ) && $subscription->get_customer_note() ) {
							echo '<p><strong>' . esc_html__( 'Customer Provided Note', 'woocommerce-subscriptions' ) . ':</strong> ' . wp_kses_post( nl2br( $subscription->get_customer_note() ) ) . '</p>';
						}

						echo '</div>';

						// Display form
						echo '<div class="edit_address">';

						if ( ! empty( self::$shipping_fields ) ) {
							foreach ( self::$shipping_fields as $key => $field ) {
								if ( ! isset( $field['type'] ) ) {
									$field['type'] = 'text';
								}
								if ( ! isset( $field['id'] ) ) {
									$field['id'] = '_shipping_' . $key;
								}

								switch ( $field['type'] ) {
									case 'select':
										wcs_woocommerce_wp_select( $field, $subscription );
										break;
									default:
										if ( is_callable( array( $subscription, 'get_shipping_' . $key ) ) ) {
											$field['value'] = $subscription->{"get_shipping_$key"}();
										} else {
											$field['value'] = $subscription->get_meta( $field['id'] );
										}

										woocommerce_wp_text_input( $field );
										break;
								}
							}
						}

						if ( apply_filters( 'woocommerce_enable_order_notes_field', 'yes' === get_option( 'woocommerce_enable_order_comments', 'yes' ) ) ) {
							?>
							<p class="form-field form-field-wide"><label for="excerpt"><?php esc_html_e( 'Customer Provided Note', 'woocommerce-subscriptions' ); ?>:</label>
								<textarea rows="1" cols="40" name="excerpt" tabindex="6" id="excerpt" placeholder="<?php esc_attr_e( 'Customer\'s notes about the order', 'woocommerce-subscriptions' ); ?>"><?php echo wp_kses_post( $subscription->get_customer_note() ); ?></textarea>
							</p>
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
	 * Saves the subscription data meta box.
	 *
	 * @see woocommerce_process_shop_order_meta
	 *
	 * @param int             $subscription_id Subscription ID.
	 * @param WC_Subscription $subscription Optional. Subscription object. Default null - will be loaded from the ID.
	 */
	public static function save( $subscription_id, $subscription = null ) {
		if ( ! wcs_is_subscription( $subscription_id ) ) {
			return;
		}

		if ( empty( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( wc_clean( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}

		self::init_address_fields();

		// Get subscription object.
		$subscription = is_a( $subscription, 'WC_Subscription' ) ? $subscription : wcs_get_subscription( $subscription_id );
		$props        = array();

		// Ensure there is an order key.
		if ( ! $subscription->get_order_key() ) {
			$props['order_key'] = wcs_generate_order_key();
		}

		// Update customer.
		$customer_id = isset( $_POST['customer_user'] ) ? absint( $_POST['customer_user'] ) : 0;
		if ( $customer_id !== $subscription->get_customer_id() ) {
			$props['customer_id'] = $customer_id;
		}

		// Update billing fields.
		foreach ( self::$billing_fields as $key => $field ) {
			$field['id'] = isset( $field['id'] ) ? $field['id'] : "_billing_{$key}";

			if ( ! isset( $_POST[ $field['id'] ] ) ) {
				continue;
			}

			$value = wc_clean( wp_unslash( $_POST[ $field['id'] ] ) );

			if ( is_callable( array( $subscription, 'set_billing_' . $key ) ) ) {
				$props[ "billing_{$key}" ] = $value;
			} else {
				$subscription->update_meta_data( $field['id'], $value );
			}
		}

		// Update shipping fields.
		foreach ( self::$shipping_fields as $key => $field ) {
			$field['id'] = isset( $field['id'] ) ? $field['id'] : "_shipping_{$key}";

			if ( ! isset( $_POST[ $field['id'] ] ) ) {
				continue;
			}

			$value = wc_clean( wp_unslash( $_POST[ $field['id'] ] ) );

			if ( is_callable( array( $subscription, 'set_shipping_' . $key ) ) ) {
				$props[ "shipping_{$key}" ] = $value;
			} else {
				$subscription->update_meta_data( $field['id'], $value );
			}
		}

		// Customer note.
		if ( isset( $_POST['excerpt'] ) ) {
			$props['customer_note'] = sanitize_textarea_field( wp_unslash( $_POST['excerpt'] ) );
		}

		$subscription->set_props( $props );
		$subscription->save();

		// Save the linked parent order ID.
		if ( ! empty( $_POST['parent-order-id'] ) ) {
			$parent_order_id = wc_clean( wp_unslash( $_POST['parent-order-id'] ) );
			// If the parent order to be set is a renewal order.
			if ( wcs_order_contains_renewal( $parent_order_id ) ) {
				// remove renewal order meta flag.
				$parent = wc_get_order( $parent_order_id );
				wcs_delete_objects_property( $parent, 'subscription_renewal' );
			}
			$subscription->set_parent_id( $parent_order_id );
			// translators: %s: parent order number (linked to its details screen).
			$subscription->add_order_note( sprintf( _x( 'Subscription linked to parent order %s via admin.', 'subscription note after linking to a parent order', 'woocommerce-subscriptions' ), sprintf( '<a href="%1$s">#%2$s</a> ', esc_url( wcs_get_edit_post_link( $subscription->get_parent_id() ) ), $subscription->get_parent()->get_order_number() ) ), false, true );
			$subscription->save();
		}

		try {
			WCS_Change_Payment_Method_Admin::save_meta( $subscription );
			$order_status = wc_clean( wp_unslash( $_POST['order_status'] ?? '' ) );

			if ( 'cancelled' === $order_status ) {
				$subscription->cancel_order();
			} else {
				$subscription->update_status( $order_status, '', true );
			}
		} catch ( Exception $e ) {
			// translators: placeholder is error message from the payment gateway or subscriptions when updating the status
			wcs_add_admin_notice( sprintf( __( 'Error updating some information: %s', 'woocommerce-subscriptions' ), $e->getMessage() ), 'error' );
		}

		if ( isset( $_POST['original_post_status'] ) && 'auto-draft' === $_POST['original_post_status'] ) {
			$subscription->set_created_via( 'admin' );
			$subscription->save();

			/**
			 * Fire an action after a subscription is created via the admin screen.
			 *
			 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4.1
			 * @param WC_Subscription $subscription The subscription object.
			 */
			do_action( 'woocommerce_admin_created_subscription', $subscription );
		}

		do_action( 'woocommerce_process_shop_subscription_meta', $subscription_id, $subscription );
	}
}
