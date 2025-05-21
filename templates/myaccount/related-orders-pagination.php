<?php
/**
 * Pagination controls in support of the related orders list found on the My Account > View Subscription page.
 *
 * @category WooCommerce Subscriptions/Templates
 * @version  7.5.0
 *
 * @var int $max_num_pages
 * @var int $page
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( 1 === $max_num_pages && 1 === $page ) {
	return;
}

$prev_page = max( 1, $page - 1 );
$next_page = min( $max_num_pages, $page + 1 );

$page_link = static function( int $page, string $type, string $label, string $alt_symbol, bool $enabled = true ): string {
	$base_classes = 'button woocommerce-button wp-element-button';
	$classes      = esc_attr( $base_classes . ' woocommerce-button--' . $type . ( $enabled ? '' : ' disabled' ) );
	$url          = esc_url( add_query_arg( 'related_orders_page', $page, '#woocommerce-subscriptions-related-orders-table' ) );
	$href         = $enabled ? "href='$url'" : '';
	$label        = esc_html( $label );
	$aria_label   = esc_attr( $label );
	$alt_symbol   = esc_html( $alt_symbol );

	return "<a $href class='$classes' aria-label='$aria_label'><span class='label'>$label</span><span class='symbol'>$alt_symbol</span></a>";
};

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Our helper function takes care of escaping.
?>
<div class="woocommerce-subscriptions-related-orders-pagination-links woocommerce-pagination">
	<div class="pagination-links">
		<?php echo $page_link( 1, 'first', _x( 'First', 'Related orders pagination', 'woocommerce-subscriptions' ), '«', 1 !== $page ); ?>
		<?php echo $page_link( $prev_page, 'prev', _x( 'Previous', 'Related orders pagination', 'woocommerce-subscriptions' ), '‹', 1 !== $page ); ?>
	</div>

	<div class="pagination-links">
		<?php echo $page_link( $next_page, 'next', _x( 'Next', 'Related orders pagination', 'woocommerce-subscriptions' ), '›', $page < $max_num_pages ); ?>
		<?php echo $page_link( $max_num_pages, 'last', _x( 'Last', 'Related orders pagination', 'woocommerce-subscriptions' ), '»', $page < $max_num_pages ); ?>
	</div>
</div>
<?php // phpcs:enable ?>
