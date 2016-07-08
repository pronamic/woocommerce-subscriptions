<?php
/**
 * Subscription Product Add to Cart
 *
 * @author 		WooThemes
 * @package 	WooCommerce-Subscriptions/Templates
 * @version     1.5.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

global $product;

// Bail if the product isn't purchasable and that's not because it's limited
if ( ! $product->is_purchasable() && ( ! is_user_logged_in() || 'no' == $product->limit_subscriptions ) ) {
	return;
}

$user_id = get_current_user_id();

// Availability
$availability = $product->get_availability();

if ( $availability['availability'] ) :
	echo wp_kses_post( apply_filters( 'woocommerce_stock_html', '<p class="stock '.$availability['class'].'">'.$availability['availability'].'</p>', $availability['availability'] ) );
endif;

if ( ! $product->is_in_stock() ) : ?>
	<link itemprop="availability" href="http://schema.org/OutOfStock">
<?php else : ?>

	<link itemprop="availability" href="http://schema.org/InStock">

	<?php do_action( 'woocommerce_before_add_to_cart_form' ); ?>

	<?php if ( ! $product->is_purchasable() && 0 != $user_id && 'no' != $product->limit_subscriptions && ( ( 'active' == $product->limit_subscriptions && wcs_user_has_subscription( $user_id, $product->id, 'on-hold' ) ) || $user_has_subscription = wcs_user_has_subscription( $user_id, $product->id, $product->limit_subscriptions ) ) ) : ?>
		<?php if ( 'any' == $product->limit_subscriptions && $user_has_subscription && ! wcs_user_has_subscription( $user_id, $product->id, 'active' ) && ! wcs_user_has_subscription( $user_id, $product->id, 'on-hold' ) ) : // customer has an inactive subscription, maybe offer the renewal button ?>
			<?php $renewal_link = wcs_get_users_resubscribe_link_for_product( $product->id ); ?>
			<?php if ( ! empty( $renewal_link ) ) : ?>
				<a href="<?php echo esc_url( $renewal_link ); ?>" class="button product-renewal-link"><?php esc_html_e( 'Renew', 'woocommerce-subscriptions' ); ?></a>
			<?php endif; ?>
		<?php else : ?>
		<p class="limited-subscription-notice notice"><?php esc_html_e( 'You have an active subscription to this product already.', 'woocommerce-subscriptions' ); ?></p>
		<?php endif; ?>
	<?php else : ?>
	<form class="cart" method="post" enctype='multipart/form-data'>

		<?php do_action( 'woocommerce_before_add_to_cart_button' ); ?>

		<input type="hidden" name="add-to-cart" value="<?php echo esc_attr( $product->id ); ?>" />

		<?php
		if ( ! $product->is_sold_individually() ) {
			woocommerce_quantity_input( array(
				'min_value' => apply_filters( 'woocommerce_quantity_input_min', 1, $product ),
				'max_value' => apply_filters( 'woocommerce_quantity_input_max', $product->backorders_allowed() ? '' : $product->get_stock_quantity(), $product ),
			) );
		}
		?>

		<button type="submit" class="single_add_to_cart_button button alt"><?php echo esc_html( $product->single_add_to_cart_text() ); ?></button>

		<?php do_action( 'woocommerce_after_add_to_cart_button' ); ?>

	</form>
	<?php endif; ?>

	<?php do_action( 'woocommerce_after_add_to_cart_form' ); ?>

<?php endif; ?>
