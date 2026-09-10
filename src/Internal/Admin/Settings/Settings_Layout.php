<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Admin\Settings;

use WC_Subscriptions_Admin;

/**
 * Single source of truth for the redesigned Subscriptions settings layout.
 *
 * The redesign presents the settings tab as an ordered, card-grouped screen. This class owns the
 * layout definition shared by both renderers - the classic PHP renderer ({@see Classic_Renderer})
 * and the experimental React (settings-ui) renderer - so card order and grouping are maintained in
 * exactly one place:
 *
 *   - the top-to-bottom card order ({@see section_order()}), with unknown (extension-registered)
 *     sections preserved after all design-owned sections,
 *   - the split of the legacy "Miscellaneous" catch-all into its dedicated cards
 *     (Checkout options, Payment recovery, Suspensions, Downloadable content),
 *   - cross-section field moves (the APFS purchase-option-text field into Purchase text),
 *   - removal of the empty standalone APFS compatibility section.
 *
 * Everything here is a pure array-to-array transform: no gating, no rendering, no globals, no
 * options reads. Callers decide when to apply it (the classic renderer gates to the settings tab's
 * GET render; the modern adapter applies it when building its schema). Rendering concerns - card
 * wrapper markup and per-field normalization - live with the renderers, not here.
 *
 * Copy lives at source (the settings managers' `add_settings()` definitions); this class carries
 * only the section title/description definitions for the cards it creates itself, because those
 * sections do not exist anywhere at source.
 *
 * Disclosure data attributes contract: this class may attach `data-show-if-*` attributes only to
 * structures it creates itself. It currently creates none - all disclosure attributes come from the
 * source managers' field definitions.
 *
 * @internal This class is used internally by WooCommerce Subscriptions. It is not intended for
 *           third party use, and may change at any time.
 */
class Settings_Layout {

	/**
	 * Reshape the assembled settings array into the redesign's layout.
	 *
	 * Splits Miscellaneous into its dedicated cards, removes the empty standalone APFS compatibility
	 * section, moves the APFS purchase-option-text field into the Purchase text section, and reorders
	 * the section blocks into the design's card order. The returned array is flat (`title` ...
	 * `sectionend` blocks preserved), ready for either renderer. Fields sitting before the first
	 * section pass through ahead of the reordered blocks; loose fields sitting after (or between)
	 * sections keep rendering after them.
	 *
	 * @param array $settings Assembled legacy settings array.
	 * @return array
	 */
	public static function reshape( array $settings ): array {
		// Split the "Miscellaneous" catch-all into the redesign's dedicated cards.
		$settings = self::promote_miscellaneous_sections( $settings );

		// Standalone APFS uses this legacy section with a hidden description. Without field rows, its
		// redesigned card is empty.
		$settings = self::drop_empty_section( $settings, 'wcsatt_subscribe_to_cart_options_pre' );

		// Move the APFS "Purchase option text" field out of the Storewide plans card and into the
		// Purchase text card, where the redesign consolidates it alongside the button-text fields.
		$settings = self::move_field_into_section(
			$settings,
			'wcsatt_subscribe_to_cart_prompt',
			WC_Subscriptions_Admin::$option_prefix . '_button_text'
		);

		list( $leading, $blocks, $trailing ) = self::split_into_sections( $settings );

		if ( empty( $blocks ) ) {
			return $settings;
		}

		$blocks = self::order_sections( $blocks );

		$out = $leading;

		foreach ( $blocks as $block ) {
			foreach ( $block['fields'] as $field ) {
				$out[] = $field;
			}
		}

		foreach ( $trailing as $field ) {
			$out[] = $field;
		}

		return $out;
	}

	/**
	 * Split a flat settings array into its `title` ... `sectionend` section blocks.
	 *
	 * Public so renderers can iterate the reshaped array section by section (e.g. to bracket each
	 * block in card markup) without re-implementing the sectioning rules.
	 *
	 * Loose fields (outside any section) are bucketed by position: before the first section they
	 * are leading; after (or between) sections they are trailing, so an extension's loose field
	 * added after the last `sectionend` is never hoisted above the sections.
	 *
	 * @param array $settings Assembled legacy settings array.
	 * @return array{0: array, 1: array, 2: array} [ leading fields, list of section blocks (each
	 *                                               `[ 'id' => string, 'fields' => array ]`),
	 *                                               trailing fields ].
	 */
	public static function split_into_sections( array $settings ): array {
		$leading  = array();
		$blocks   = array();
		$trailing = array();
		$current  = null;

		foreach ( $settings as $field ) {
			$type = isset( $field['type'] ) ? $field['type'] : '';

			if ( 'title' === $type ) {
				// Flush any unterminated section defensively before starting a new one.
				if ( null !== $current ) {
					$blocks[] = $current;
				}

				$current = array(
					'id'     => isset( $field['id'] ) ? $field['id'] : '',
					'fields' => array( $field ),
				);
				continue;
			}

			if ( null === $current ) {
				if ( empty( $blocks ) ) {
					$leading[] = $field;
				} else {
					$trailing[] = $field;
				}
				continue;
			}

			$current['fields'][] = $field;

			if ( 'sectionend' === $type ) {
				$blocks[] = $current;
				$current  = null;
			}
		}

		if ( null !== $current ) {
			$blocks[] = $current;
		}

		return array( $leading, $blocks, $trailing );
	}

	/**
	 * Build a "Learn more" documentation link.
	 *
	 * Shared by the layout definitions and both renderers so the markup (new tab, no referrer) stays
	 * consistent everywhere a settings description links out to documentation.
	 *
	 * The anchor's own aria-label is the link's one accessible name, new-tab warning included. The
	 * arrow icon is purely decorative and marked aria-hidden: an aria-label on a plain span maps to
	 * the generic role, which ARIA prohibits naming, so browsers would ignore it anyway. The glyph
	 * carries the text-presentation selector (U+FE0E) so wp-emoji's Twemoji pass leaves it as text
	 * instead of swapping in an emoji image on wp-admin.
	 *
	 * @param string $url Destination URL.
	 * @return string
	 */
	public static function learn_more_link( string $url ): string {
		return sprintf(
			'<a class="components-external-link" href="%1$s" target="_blank" rel="external noreferrer noopener" aria-label="%3$s"><span class="components-external-link__contents">%2$s</span> <span class="components-external-link__icon" aria-hidden="true">&#8599;&#xFE0E;</span></a>',
			esc_url( $url ),
			esc_html__( 'Learn more', 'woocommerce-subscriptions' ),
			esc_attr__( 'Learn more (opens in a new tab)', 'woocommerce-subscriptions' )
		);
	}

	/**
	 * Sort section blocks into the redesign's card order.
	 *
	 * Sections named in {@see section_order()} sort to their listed position. Any section not named
	 * (e.g. an extension's own) keeps its relative order and lands after every design-owned section,
	 * so nothing is hidden or dropped.
	 *
	 * @param array $blocks Section blocks from {@see split_into_sections()}.
	 * @return array
	 */
	private static function order_sections( array $blocks ): array {
		$order = self::section_order();
		$rank  = array_flip( $order );

		// Unknown sections rank after every design-owned section.
		$unknown_rank = count( $order );

		$decorated = array();
		foreach ( $blocks as $index => $block ) {
			$decorated[] = array(
				'rank'  => isset( $rank[ $block['id'] ] ) ? $rank[ $block['id'] ] : $unknown_rank,
				'index' => $index,
				'block' => $block,
			);
		}

		// Decorate-sort-undecorate with the original index as tiebreaker, so ties stay stable on PHP 7.4.
		usort(
			$decorated,
			static function ( $a, $b ) {
				if ( $a['rank'] === $b['rank'] ) {
					return $a['index'] <=> $b['index'];
				}
				return $a['rank'] <=> $b['rank'];
			}
		);

		return array_column( $decorated, 'block' );
	}

	/**
	 * The redesign's top-to-bottom card order, by section (title) id.
	 *
	 * Sections not listed here sort after the complete design-owned list ({@see order_sections()}).
	 *
	 * @return string[]
	 */
	private static function section_order(): array {
		$prefix = WC_Subscriptions_Admin::$option_prefix;

		return array(
			'wcsatt_subscribe_to_cart_options',   // Storewide subscription plans (APFS; design keeps it fixed first).
			$prefix . '_renewal_options',         // Renewals.
			$prefix . '_checkout_options',        // Checkout options (split from Miscellaneous).
			$prefix . '_payment_recovery',        // Payment recovery (split from Miscellaneous).
			$prefix . '_switch_settings',         // Switching.
			'wcsatt_add_to_subscription_options', // Add to Subscription (APFS).
			$prefix . '_suspension_options',      // Suspensions (split from Miscellaneous).
			$prefix . '_gifting',                 // Gifting.
			$prefix . '_sync_payments_title',     // Billing date alignment.
			$prefix . '_downloads_settings',      // Downloadable content (split from Miscellaneous).
			$prefix . '_customer_notifications',  // Subscription notifications.
			$prefix . '_button_text',             // Purchase text.
			$prefix . '_role_options',            // Subscriber roles.
			$prefix . '_product_creation',        // Subscription product creation (fixed bottom).
			$prefix . '_health_check_options',    // Subscriptions health check (fixed bottom).
			$prefix . '_queue_processing_options', // Processing reliability (fixed bottom).
		);
	}

	/**
	 * Split the legacy "Miscellaneous" catch-all into the redesign's dedicated cards.
	 *
	 * Each field is lifted out of wherever it currently sits and re-grouped into a new
	 * `title` ... `sectionend` block carrying the section definition from
	 * {@see miscellaneous_sections()}. Missing fields are skipped, and a section with no fields is
	 * not created, so this is a no-op on stores where a given control is absent. When a section with
	 * the target id already exists (the Subscription Downloads feature registers its own
	 * `_downloads_settings` section), the lifted fields merge into it instead of duplicating the
	 * card. Once emptied, the Miscellaneous shell is dropped.
	 *
	 * @param array $settings Assembled legacy settings array.
	 * @return array
	 */
	private static function promote_miscellaneous_sections( array $settings ): array {
		$new_blocks = array();

		foreach ( self::miscellaneous_sections() as $section ) {
			$rows = array();

			foreach ( $section['fields'] as $field_id => $structural ) {
				$index = self::find_field_index( $settings, $field_id );
				if ( null === $index ) {
					continue;
				}

				$row = $settings[ $index ];
				array_splice( $settings, $index, 1 );

				// Structural help override only (copy lives at source): a section definition may blank a
				// field's `desc_tip` when the section description already carries the same explanation.
				if ( isset( $structural['desc_tip'] ) ) {
					$row['desc_tip'] = $structural['desc_tip'];
				}

				$rows[] = $row;
			}

			if ( empty( $rows ) ) {
				continue;
			}

			// If a section with this id already exists, merge into it rather than creating a duplicate
			// card: align it to the design definition and insert the lifted fields at the top.
			$existing_title = self::find_field_index( $settings, $section['id'], 'title' );
			if ( null !== $existing_title ) {
				$settings[ $existing_title ]['name']  = $section['name'];
				$settings[ $existing_title ]['title'] = $section['name'];
				$settings[ $existing_title ]['desc']  = $section['desc'];
				array_splice( $settings, $existing_title + 1, 0, $rows );
				continue;
			}

			$block = array(
				array(
					'type' => 'title',
					'id'   => $section['id'],
					'name' => $section['name'],
					'desc' => $section['desc'],
				),
			);
			foreach ( $rows as $row ) {
				$block[] = $row;
			}
			$block[] = array(
				'type' => 'sectionend',
				'id'   => $section['id'],
			);

			$new_blocks[] = $block;
		}

		// Drop the now-empty Miscellaneous shell, then append the new blocks (order_sections positions them).
		$settings = self::drop_empty_section( $settings, WC_Subscriptions_Admin::$option_prefix . '_miscellaneous' );

		foreach ( $new_blocks as $block ) {
			foreach ( $block as $field ) {
				$settings[] = $field;
			}
		}

		return $settings;
	}

	/**
	 * The cards carved out of "Miscellaneous".
	 *
	 * Each entry defines the card's section id, title and description (these sections exist nowhere
	 * at source, so their copy lives here) and the ids of the source-defined fields lifted into it,
	 * in card order. Field copy is never overridden here - it lives at source. The only per-field
	 * key supported is a structural `desc_tip` blank, used when the card's section description
	 * already carries the field's explanation.
	 *
	 * @return array
	 */
	private static function miscellaneous_sections(): array {
		$prefix = WC_Subscriptions_Admin::$option_prefix;

		return array(
			array(
				'id'     => $prefix . '_checkout_options',
				'name'   => __( 'Checkout options', 'woocommerce-subscriptions' ),
				'desc'   => __( 'Configure how subscription products can be purchased at checkout.', 'woocommerce-subscriptions' ),
				'fields' => array(
					$prefix . '_zero_initial_payment_requires_payment' => array(),
					$prefix . '_multiple_purchase' => array(),
				),
			),
			array(
				'id'     => $prefix . '_payment_recovery',
				'name'   => __( 'Payment recovery', 'woocommerce-subscriptions' ),
				'desc'   => sprintf(
					/* translators: %1$s: a "Learn more" documentation link. */
					__( 'Automatically retry failed recurring payments when a subscriber\'s payment method is temporarily declined. %1$s', 'woocommerce-subscriptions' ),
					self::learn_more_link( 'https://woocommerce.com/document/subscriptions/store-manager-guide/#payment-recovery' )
				),
				'fields' => array(
					// Structural: the section description above carries the retry explanation, so the
					// checkbox's source help is cleared to avoid repeating it inside the card.
					$prefix . '_enable_retry' => array( 'desc_tip' => '' ),
				),
			),
			array(
				'id'     => $prefix . '_suspension_options',
				'name'   => __( 'Suspensions', 'woocommerce-subscriptions' ),
				'desc'   => __( 'Allow subscribers to suspend their subscription.', 'woocommerce-subscriptions' ),
				'fields' => array(
					$prefix . '_max_customer_suspensions_enable'     => array(),
					$prefix . '_max_customer_suspensions_limit'      => array(),
					$prefix . '_max_customer_suspensions_per_period' => array(),
				),
			),
			array(
				'id'     => $prefix . '_downloads_settings',
				'name'   => __( 'Downloadable content', 'woocommerce-subscriptions' ),
				'desc'   => __( 'Configure how downloadable products work with subscriptions.', 'woocommerce-subscriptions' ),
				'fields' => array(
					$prefix . '_drip_downloadable_content_on_renewal' => array(),
				),
			),
		);
	}

	/**
	 * Remove a section's `title`/`sectionend` pair when it has no fields left between them. Sections
	 * that still hold fields are left untouched.
	 *
	 * @param array  $settings   Assembled legacy settings array.
	 * @param string $section_id Section id.
	 * @return array
	 */
	private static function drop_empty_section( array $settings, string $section_id ): array {
		$title_index = self::find_field_index( $settings, $section_id, 'title' );
		$end_index   = self::find_field_index( $settings, $section_id, 'sectionend' );

		if ( null === $title_index || null === $end_index ) {
			return $settings;
		}

		// Empty means the sectionend sits immediately after the title (no field rows in between).
		if ( 1 === $end_index - $title_index ) {
			array_splice( $settings, $title_index, 2 );
		}

		return $settings;
	}

	/**
	 * Move a single field so it renders at the top of another section (right after that section's
	 * `title`). No-op if either the field or the destination section is absent.
	 *
	 * @param array  $settings          Assembled legacy settings array.
	 * @param string $field_id          Id of the field to move.
	 * @param string $target_section_id Id of the destination section's `title` field.
	 * @return array
	 */
	private static function move_field_into_section( array $settings, string $field_id, string $target_section_id ): array {
		if ( null === self::find_field_index( $settings, $target_section_id, 'title' ) ) {
			return $settings;
		}

		$source = self::find_field_index( $settings, $field_id );
		if ( null === $source ) {
			return $settings;
		}

		$moved = array_splice( $settings, $source, 1 );

		// Re-find the target: its index may have shifted if the source sat before it.
		$target = self::find_field_index( $settings, $target_section_id, 'title' );
		array_splice( $settings, $target + 1, 0, $moved );

		return $settings;
	}

	/**
	 * Find the array index of a field by id, optionally constrained to a field type (to disambiguate
	 * the shared `title` / `sectionend` id).
	 *
	 * Public because {@see Modern_Field_Adaptations}' section builders share it (one helper, no
	 * drift), alongside this class's own layout transforms.
	 *
	 * @param array       $settings Assembled legacy settings array.
	 * @param string      $id       Field id to find.
	 * @param string|null $type     Optional field type to match.
	 * @return int|null
	 */
	public static function find_field_index( array $settings, string $id, ?string $type = null ) {
		foreach ( $settings as $index => $field ) {
			if ( ! is_array( $field ) || ! isset( $field['id'] ) || $field['id'] !== $id ) {
				continue;
			}
			if ( null !== $type && ( ! isset( $field['type'] ) || $field['type'] !== $type ) ) {
				continue;
			}
			return $index;
		}

		return null;
	}
}
