<?php

class WC_Order_Item_Pending_Switch extends WC_Order_Item_Product {
	public function get_type() {
		return 'line_item_pending_switch';
	}
}
