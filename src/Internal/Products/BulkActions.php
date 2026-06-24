<?php
/**
 * Bulk edit fields for the Products list screen.
 *
 * @package WooCommerce Subscriptions
 * @since   8.6.0
 */

namespace Automattic\WooCommerce_Subscriptions\Internal\Products;

defined( 'ABSPATH' ) || exit;

/**
 * Adds subscription purchase options to the WooCommerce product bulk edit form.
 */
class BulkActions {

	/**
	 * Number of products skipped during bulk edit save.
	 *
	 * @var int
	 */
	private static $skipped = 0;

	/**
	 * Transient name prefix for storing bulk edit results across the POST-redirect-GET cycle.
	 */
	const TRANSIENT_PREFIX = 'woocommerce_subscriptions_bulk_edit_result_';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_product_bulk_edit_end', array( __CLASS__, 'render_bulk_edit_fields' ) );
		add_action( 'woocommerce_product_bulk_edit_save', array( __CLASS__, 'save_bulk_edit_fields' ) );
		add_action( 'shutdown', array( __CLASS__, 'persist_counts' ) );
		// Priority 20 to run after WC's bulk_post_updated_messages (priority 10) which sets the base message.
		add_filter( 'bulk_post_updated_messages', array( __CLASS__, 'customize_bulk_updated_messages' ), 20, 2 );
	}

	/**
	 * Persist counts to a transient at the end of the POST request.
	 *
	 * WP's bulk edit does POST-then-redirect, so static counts are lost.
	 * The transient carries them to the redirected GET where the message renders.
	 */
	public static function persist_counts() {
		if ( 0 === self::$skipped ) {
			return;
		}

		$transient_name = self::TRANSIENT_PREFIX . get_current_user_id();
		set_transient( $transient_name, self::$skipped, 60 );
	}

	/**
	 * Render subscription options in the bulk edit form.
	 */
	public static function render_bulk_edit_fields() {
		?>
		<div class="inline-edit-group woocommerce-subscriptions-bulk-edit" role="group" aria-labelledby="wcsatt-bulk-edit-heading">
			<h4 id="wcsatt-bulk-edit-heading"><?php esc_html_e( 'Subscription options', 'woocommerce-subscriptions' ); ?></h4>
			<div class="inline-edit-group">
				<label for="wcsatt-bulk-purchase-option">
					<span class="title"><?php esc_html_e( 'Purchase options', 'woocommerce-subscriptions' ); ?></span>
				</label>
				<div class="input-text-wrap">
					<select
						id="wcsatt-bulk-purchase-option"
						class="woocommerce_subscriptions_bulk_purchase_option"
						name="_wcsatt_bulk_purchase_option"
					>
						<option value=""><?php esc_html_e( '&mdash; No change &mdash;', 'woocommerce-subscriptions' ); ?></option>
						<option value="inherit"><?php esc_html_e( 'Use storewide subscription plans', 'woocommerce-subscriptions' ); ?></option>
						<option value="override"><?php esc_html_e( 'Use custom subscription plans', 'woocommerce-subscriptions' ); ?></option>
						<option value="disable"><?php esc_html_e( 'Sell one-time only', 'woocommerce-subscriptions' ); ?></option>
					</select>
				</div>
			</div>

			<div class="inline-edit-group wcsatt-one-time-purchase" style="display:none;">
				<label for="wcsatt-bulk-allow-one-off">
					<span class="title"><?php esc_html_e( 'One-time purchase', 'woocommerce-subscriptions' ); ?></span>
				</label>
				<div class="input-text-wrap">
					<select
						id="wcsatt-bulk-allow-one-off"
						name="_wcsatt_bulk_allow_one_off"
					>
						<option value=""><?php esc_html_e( '&mdash; No change &mdash;', 'woocommerce-subscriptions' ); ?></option>
						<option value="yes"><?php esc_html_e( 'Enable one-time purchases', 'woocommerce-subscriptions' ); ?></option>
						<option value="no"><?php esc_html_e( 'Disable one-time purchases', 'woocommerce-subscriptions' ); ?></option>
					</select>
				</div>
			</div>
		</div>

		<script type="text/javascript">
			jQuery( function( $ ) {
				$( '#wpbody' ).on( 'change', '.woocommerce_subscriptions_bulk_purchase_option', function() {
					var $oneTimePurchase = $( this ).closest( '.woocommerce-subscriptions-bulk-edit' ).find( '.wcsatt-one-time-purchase' );
					var value = $( this ).val();
					if ( 'inherit' === value || 'override' === value ) {
						$oneTimePurchase.show();
					} else {
						$oneTimePurchase.hide();
					}
				} );
			} );
		</script>
		<?php
	}

	/**
	 * Process subscription options during bulk edit save.
	 *
	 * Called by WooCommerce for each product being bulk edited.
	 *
	 * @param \WC_Product $product The product being saved.
	 */
	public static function save_bulk_edit_fields( $product ) {
		$mode = isset( $_REQUEST['_wcsatt_bulk_purchase_option'] ) ? wc_clean( wp_unslash( $_REQUEST['_wcsatt_bulk_purchase_option'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( empty( $mode ) || ! \WCS_ATT_Scheme::is_valid_mode( $mode ) ) {
			return;
		}

		if ( ! \WCS_ATT_Product::supports_feature( $product, 'subscription_schemes' ) ) {
			++self::$skipped;
			return;
		}

		// Override mode requires existing custom plans.
		if ( \WCS_ATT_Scheme::MODE_OVERRIDE === $mode ) {
			$schemes = $product->get_meta( '_wcsatt_schemes', true );
			if ( empty( $schemes ) ) {
				++self::$skipped;
				return;
			}
		}

		\WCS_ATT_Product::set_subscription_scheme_mode( $product, $mode );

		// Apply one-time purchase setting for non-disable modes.
		if ( \WCS_ATT_Scheme::MODE_DISABLE !== $mode ) {
			$allow_one_off = isset( $_REQUEST['_wcsatt_bulk_allow_one_off'] ) ? wc_clean( wp_unslash( $_REQUEST['_wcsatt_bulk_allow_one_off'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( '' !== $allow_one_off ) {
				$force_subscription = 'yes' === $allow_one_off ? 'no' : 'yes';
				$product->update_meta_data( '_wcsatt_force_subscription', $force_subscription );
			}
		}

		// WC calls $product->save() before this hook fires, not after.
		// Verified: without this save, meta changes from set_subscription_scheme_mode() are lost.
		$product->save();
	}

	/**
	 * Append subscription skip info to the WC bulk updated message.
	 *
	 * Hooks into bulk_post_updated_messages to modify the existing "X products updated"
	 * message rather than adding a separate notice. Only modifies the message when
	 * some products were skipped during subscription option changes.
	 *
	 * The WC updated count is not modified - WC correctly counts all saved products.
	 * "Skipped" here means subscription meta was not changed (e.g., unsupported type
	 * or missing custom plans), not that the product save failed.
	 *
	 * @param array $bulk_messages Arrays of messages keyed by post type.
	 * @param array $bulk_counts   Array of item counts for each message (unused, required by filter signature).
	 * @return array
	 */
	public static function customize_bulk_updated_messages( $bulk_messages, $bulk_counts ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$transient_name = self::TRANSIENT_PREFIX . get_current_user_id();
		$skipped        = get_transient( $transient_name );

		if ( false === $skipped || 0 === (int) $skipped ) {
			return $bulk_messages;
		}

		$skipped = (int) $skipped;
		delete_transient( $transient_name );

		// Append skip info to WC's existing "updated" message template.
		// The %s placeholder is filled by WP core with the updated count.
		$skip_suffix = ' ' . sprintf(
			/* translators: %d: number of products whose subscription options couldn't be updated. */
			_n(
				"Subscription options for %d product couldn't be updated.",
				"Subscription options for %d products couldn't be updated.",
				$skipped,
				'woocommerce-subscriptions'
			),
			$skipped
		);

		if ( isset( $bulk_messages['product']['updated'] ) ) {
			$bulk_messages['product']['updated'] .= $skip_suffix;
		}

		return $bulk_messages;
	}

	/**
	 * Get the number of skipped products.
	 *
	 * @return int
	 */
	public static function get_skipped_count() {
		return self::$skipped;
	}

	/**
	 * Reset the skipped counter.
	 */
	public static function reset_counts() {
		self::$skipped = 0;
	}
}
