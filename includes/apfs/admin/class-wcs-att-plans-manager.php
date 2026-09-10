<?php
/**
 * Plans Manager
 *
 * Handles CRUD operations and data preparation for subscription plans.
 *
 * @since    9.0.0
 */

use Automattic\WooCommerce_Subscriptions\Internal\Products\Plan_Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages subscription plan CRUD: storage read/write, validation, ID generation, and filter hooks.
 *
 * For storewide plans, plans are stored in wp_options ('wcsatt_subscribe_to_cart_schemes').
 * For product plans, plans are stored in post meta ('_wcsatt_schemes'). Pass the product ID
 * as the $product_id argument to each CRUD method — this allows a single manager instance to
 * operate across multiple products without the overhead of creating one instance per product.
 */
class WCS_ATT_Plans_Manager {

	/**
	 * Plan type: 'storewide' or 'product'.
	 *
	 * Controls which storage backend is used and which third-party filter hook
	 * is applied when persisting plan data.
	 *
	 * @var string
	 */
	private $plan_type;

	/**
	 * Constructor.
	 *
	 * @since 9.0.0
	 *
	 * @param string $plan_type 'storewide' or 'product'.
	 */
	public function __construct( $plan_type ) {
		$this->plan_type = $plan_type;
	}

	// -------------------------------------------------------------------------
	// CRUD operations
	// -------------------------------------------------------------------------

	/**
	 * Read and return the current plans from storage.
	 *
	 * @since 9.0.0
	 *
	 * @param  int|null $product_id Product ID for 'product' plans; null for 'storewide' plans.
	 * @return array
	 */
	public function read( $product_id = null ) {
		if ( 'storewide' === $this->plan_type ) {
			$plans = get_option( 'wcsatt_subscribe_to_cart_schemes', array() );
		} else {
			$plans = get_post_meta( $product_id, '_wcsatt_schemes', true );
		}

		return is_array( $plans ) ? $plans : array();
	}

	/**
	 * Add a new plan to storage.
	 *
	 * Generates an ID, validates the data, and applies third-party filter hooks.
	 *
	 * @since 9.0.0
	 *
	 * @param  array    $plan_data  Plan data. An 'id' key is generated automatically if absent.
	 * @param  int|null $product_id Product ID for 'product' plans; null for 'storewide' plans.
	 * @return array Created plan data.
	 * @throws WCS_ATT_Plan_Exception On validation failure.
	 */
	public function create( $plan_data, $product_id = null ) {
		$validation_errors = WCS_ATT_Validation::validate_plan_data( $plan_data );
		if ( ! empty( $validation_errors ) ) {
			throw new WCS_ATT_Plan_Exception(
				'validation_error',
				'Invalid plan data.',
				400,
				$validation_errors // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This is not user-facing text, it's saved to the log.
			);
		}

		$plan_data = $this->apply_plan_filters( $plan_data, $product_id );

		if ( empty( $plan_data['id'] ) ) {
			$plan_data['id'] = wp_generate_uuid4();
		}

		$plans   = $this->read( $product_id );
		$plans[] = $plan_data;
		$this->save( $plans, $product_id );

		return $plan_data;
	}

	/**
	 * Update an existing plan in storage.
	 *
	 * Validates the data and applies third-party filter hooks.
	 *
	 * @since 9.0.0
	 *
	 * @param  string   $plan_id    Plan ID.
	 * @param  array    $plan_data  New plan data. The 'id' key is kept from storage when the plan already
	 *                             has one, and is otherwise set to $plan_id, so updating a plan never
	 *                             renames it.
	 * @param  int|null $product_id Product ID for 'product' plans; null for 'storewide' plans.
	 * @return array Updated plan data.
	 * @throws WCS_ATT_Plan_Exception If not found or invalid.
	 */
	public function update( $plan_id, $plan_data, $product_id = null ) {
		$plans      = $this->read( $product_id );
		$plan_index = $this->find_plan_index( $plans, $plan_id );

		if ( null === $plan_index ) {
			throw new WCS_ATT_Plan_Exception(
				'plan_not_found',
				'Plan not found.',
				404
			);
		}

		$validation_errors = WCS_ATT_Validation::validate_plan_data( $plan_data );
		if ( ! empty( $validation_errors ) ) {
			throw new WCS_ATT_Plan_Exception(
				'validation_error',
				'Invalid plan data.',
				400,
				$validation_errors // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This is not user-facing text, it's saved to the log.
			);
		}

		$plan_data = $this->apply_plan_filters( $plan_data, $product_id );

		/*
		 * Keep the id already in storage rather than the one the plan was looked up by: a legacy plan can be
		 * addressed by either spelling of its key, and updating it must not silently rename it. Plans stored
		 * without an id - which the standalone plugin's product-level save produced - adopt the key they
		 * were found by.
		 */
		$plan_data['id']      = ! empty( $plans[ $plan_index ]['id'] ) ? $plans[ $plan_index ]['id'] : $plan_id;
		$plans[ $plan_index ] = $plan_data;
		$this->save( $plans, $product_id );

		return $plan_data;
	}

	/**
	 * Remove a plan from storage.
	 *
	 * @since 9.0.0
	 *
	 * @param  string   $plan_id    Plan ID.
	 * @param  int|null $product_id Product ID for 'product' plans; null for 'storewide' plans.
	 * @return true
	 * @throws WCS_ATT_Plan_Exception If the plan was not found.
	 */
	public function delete( $plan_id, $product_id = null ) {
		$plans      = $this->read( $product_id );
		$plan_index = $this->find_plan_index( $plans, $plan_id );

		if ( null === $plan_index ) {
			throw new WCS_ATT_Plan_Exception(
				'plan_not_found',
				'Plan not found.',
				404
			);
		}

		unset( $plans[ $plan_index ] );
		$this->save( array_values( $plans ), $product_id );

		return true;
	}

	/**
	 * Reorder the plans in storage according to the provided ID sequence.
	 *
	 * @since 9.0.0
	 *
	 * @param  array    $plan_ids   Ordered list of all plan IDs.
	 * @param  int|null $product_id Product ID for 'product' plans; null for 'storewide' plans.
	 * @return true
	 * @throws WCS_ATT_Plan_Exception If IDs are invalid or incomplete.
	 */
	public function reorder( $plan_ids, $product_id = null ) {
		$plans     = $this->read( $product_id );
		$plans_map = array();

		foreach ( $plans as $plan ) {
			if ( isset( $plan['id'] ) ) {
				$plans_map[ $plan['id'] ] = $plan;
			}
		}

		foreach ( $plan_ids as $plan_id ) {
			if ( ! isset( $plans_map[ $plan_id ] ) ) {
				throw new WCS_ATT_Plan_Exception(
					'plan_not_found',
					sprintf( 'Plan with ID "%s" not found.', sanitize_text_field( $plan_id ) ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This is not user-facing text, it's saved to the log.
					404
				);
			}
		}

		if ( count( $plan_ids ) !== count( $plans_map ) ) {
			throw new WCS_ATT_Plan_Exception(
				'incomplete_plan_list',
				'All plans must be included in the reorder request.',
				400
			);
		}

		$reordered = array();
		foreach ( $plan_ids as $plan_id ) {
			$reordered[] = $plans_map[ $plan_id ];
		}

		$this->save( $reordered, $product_id );

		return true;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Persist the plans array to the appropriate storage backend.
	 *
	 * For product plans, also clears storewide / one-time-purchase mode markers so
	 * the product is put into custom plans mode (MODE_OVERRIDE) whenever plans are non-empty.
	 *
	 * @since 9.0.0
	 *
	 * @param array    $plans      Plans array to persist.
	 * @param int|null $product_id Product ID for 'product' plans; null for 'storewide' plans.
	 */
	private function save( $plans, $product_id = null ) {
		if ( 'storewide' === $this->plan_type ) {
			update_option( 'wcsatt_subscribe_to_cart_schemes', $plans );
			return;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			throw new WCS_ATT_Plan_Exception(
				'product_not_found',
				'Product not found.',
				404
			);
		}

		$product->update_meta_data( '_wcsatt_schemes', $plans );

		// Ensure the product is in custom plans mode (MODE_OVERRIDE) when managing product plans.
		if ( ! empty( $plans ) ) {
			WCS_ATT_Product::set_subscription_scheme_mode( $product, WCS_ATT_Scheme::MODE_OVERRIDE );
		}

		$product->save();
	}

	/**
	 * Apply the appropriate third-party filter hooks for the current plan type.
	 *
	 * WCS_ATT_Sync and other extensions hook here to add payment sync date fields
	 * and other custom data before plans are persisted.
	 *
	 * @since 9.0.0
	 *
	 * @param  array    $plan_data  Plan data.
	 * @param  int|null $product_id Product ID for 'product' plans; null for 'storewide' plans.
	 * @return array Filtered plan data.
	 */
	private function apply_plan_filters( $plan_data, $product_id = null ) {
		if ( 'storewide' === $this->plan_type ) {
			/**
			 * Allow third parties to add custom data to storewide schemes saved via the REST API.
			 *
			 * @since 9.0.0
			 *
			 * @param array $plan_data Sanitized plan data.
			 */
			return (array) apply_filters( 'wcsatt_processed_cart_scheme_data', $plan_data );
		}

		$scheme  = new WCS_ATT_Scheme( array( 'data' => $plan_data ) );
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			throw new WCS_ATT_Plan_Exception(
				'product_not_found',
				'Product not found.',
				404
			);
		}

		/**
		 * Allow third parties to add custom data to product schemes saved via the REST API.
		 *
		 * @since 9.0.0
		 *
		 * @param array           $plan_data Sanitized plan data.
		 * @param WCS_ATT_Scheme  $scheme    Scheme object.
		 * @param WC_Product|bool $product   Product object, or false if not found.
		 */
		return (array) apply_filters( 'wcsatt_processed_scheme_data', $plan_data, $scheme, $product );
	}

	/**
	 * Find the index of a plan in an array by its ID or scheme key.
	 *
	 * Tries to match by 'id' first. Falls back to matching legacy plans created by the standalone APFS
	 * plugin, whose key may be spelled either "{interval}_{period}_{length}" or with a zero length
	 * dropped - @see Plan_Utils::canonicalize_key(). Plans with no 'id'
	 * at all, which the standalone plugin's product-level save produced, are keyed by that same derived
	 * key, so they are matched here too.
	 *
	 * @since 9.0.0
	 *
	 * @param  array  $plans   Plans array.
	 * @param  string $plan_id Plan ID or scheme key.
	 * @return int|null Plan index or null if not found.
	 */
	private function find_plan_index( $plans, $plan_id ) {
		foreach ( $plans as $index => $plan ) {
			if ( isset( $plan['id'] ) && $plan['id'] === $plan_id ) {
				return $index;
			}
		}

		$canonical_id = Plan_Utils::canonicalize_key( $plan_id );

		/*
		 * First match wins here, unlike WCS_ATT_Product_Schemes::resolve_subscription_scheme_key(), which
		 * refuses to choose. The stakes differ: resolving an order item to the wrong plan misprices
		 * something a customer already bought, while this backs the admin CRUD path, where the merchant is
		 * acting on a plan in front of them and can see and redo the result. Refusing here would leave a
		 * store that has two id-less legacy plans sharing a schedule unable to edit or delete either one,
		 * since both localize to the same key and there is no other way to address them.
		 */
		foreach ( $plans as $index => $plan ) {
			$scheme = new WCS_ATT_Scheme( array( 'data' => $plan ) );

			if ( Plan_Utils::canonicalize_key( $scheme->get_key() ) === $canonical_id ) {
				return $index;
			}
		}

		return null;
	}
}
