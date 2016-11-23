jQuery(document).ready(function($){

	$('body.post-type-shop_order #post').submit(function(){
		if('wcs_retry_renewal_payment' == $( "body.post-type-shop_order select[name='wc_order_action']" ).val()) {
			return confirm(wcs_admin_order_meta_boxes.retry_renewal_payment_action_warning);
		}
	});
});
