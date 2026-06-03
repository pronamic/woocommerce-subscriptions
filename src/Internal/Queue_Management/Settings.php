<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Queue_Management;

/**
 * Registers, renders, validates and persists the merchant-facing settings for the queue-management subsystem.
 *
 * Opens the unified "Processing reliability" section that brackets both the dedicated-queue settings owned here
 * and the external-trigger settings appended by {@see External_Trigger_Settings}. This class emits the section
 * title and the first group of rows but intentionally does not emit a sectionend — the trailing
 * {@see External_Trigger_Settings} entry closes the section so the two feature areas render as one cohesive
 * block on the WC > Settings > Subscriptions screen.
 *
 * The class is intentionally narrow: it owns the form definition (and the rotation-resolution helper) but does
 * not instantiate or configure any {@see Dedicated_Queue} of its own. {@see Manager} reads option values at
 * boot time and wires up the actual queue behaviour.
 *
 * The public option-key, section-id and rotation-range constants are the contract that wiring consumes.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Settings {

	/**
	 * Option key — checkbox controlling whether the dedicated queue mechanism is active for subscription work.
	 * Defaults to `'no'`; merchants opt in. Surfaces in the UI under the "Dedicated processing" label.
	 */
	public const OPTION_ENABLED = 'woocommerce_subscriptions_dedicated_queues_enabled';

	/**
	 * Option key — integer rotation value. Retained for back-compat with sites that set a custom rotation under
	 * the previous UI (which exposed a frequency dial); newer code paths should use the
	 * `woocommerce_subscriptions_queue_rotation` filter, which sees this stored value as its default.
	 */
	public const OPTION_ROTATION = 'woocommerce_subscriptions_dedicated_queues_rotation';

	/**
	 * Filter name — final say over the effective rotation value used by {@see Dedicated_Queue}. Sites without a
	 * stored rotation option see {@see DEFAULT_ROTATION}; sites with a stored value see that. Filter callbacks
	 * receive the integer and may return any value — out-of-range returns are clamped to the
	 * {@see MIN_ROTATION}..{@see MAX_ROTATION} band by {@see get_effective_rotation()}.
	 */
	public const FILTER_ROTATION = 'woocommerce_subscriptions_queue_rotation';

	/**
	 * WC Settings section id used to bracket the title/sectionend pair. Public because
	 * {@see External_Trigger_Settings} closes this same section with a matching-id sectionend.
	 */
	public const SECTION_ID = 'woocommerce_subscriptions_queue_processing_options';

	/**
	 * Minimum permitted value for the rotation. A rotation of 1 would mean "every batch", which is
	 * indistinguishable from disabling the rotation entirely.
	 */
	public const MIN_ROTATION = 2;

	/**
	 * Maximum permitted value for the rotation. Capped at 6 to keep the off-turn wait bounded; higher values
	 * can starve subscription work on quiet stores.
	 */
	public const MAX_ROTATION = 6;

	/**
	 * Default rotation when no option is stored and no filter override is in effect. Equivalent to dedicating
	 * every third batch to subscription work — the value the UI's "Dedicated processing" checkbox is
	 * documented as enabling.
	 */
	public const DEFAULT_ROTATION = 3;

	/**
	 * Hook priority for the settings filter. Late enough to land this section at the bottom of the
	 * Subscriptions tab, after the Health Check section (which uses 999), and ahead of
	 * {@see External_Trigger_Settings} (which uses 1001 to append into the same section).
	 */
	private const SETTINGS_PRIORITY = 1000;

	/**
	 * Register the settings filter. The class is inert until this is called.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_filter( 'woocommerce_subscription_settings', array( $this, 'add_settings' ), self::SETTINGS_PRIORITY );
	}

	/**
	 * Append the opening of the Processing reliability section (title + dedicated-queue rows) to the existing
	 * WC > Settings > Subscriptions array. Intentionally omits a sectionend: {@see External_Trigger_Settings}
	 * appends the web-cron rows and the closing sectionend at a higher hook priority.
	 *
	 * @param array<int, array<string, mixed>> $settings Existing settings array.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function add_settings( array $settings ): array {
		$section = array(
			array(
				'name' => __( 'Processing reliability', 'woocommerce-subscriptions' ),
				'type' => 'title',
				'id'   => self::SECTION_ID,
				'desc' => sprintf(
					// Translators: %1$s: opening link tag %2$s: closing link tag.
					esc_html__( 'Renewals, status changes, and other subscription events run in the background on your store as %1$sScheduled Actions%2$s. The settings below help keep them running reliably.', 'woocommerce-subscriptions' ),
					'<a href="' . esc_url(
						add_query_arg(
							array(
								'page' => 'wc-status',
								'tab'  => 'action-scheduler',
							),
							admin_url( 'admin.php' )
						)
					) . '">',
					'</a>'
				),
			),
			array(
				'id'       => self::OPTION_ENABLED,
				'name'     => __( 'Dedicated processing', 'woocommerce-subscriptions' ),
				'desc'     => __( 'Run scheduled subscription events in a dedicated batch', 'woocommerce-subscriptions' ),
				'desc_tip' => __( 'When enabled, subscription renewals and other events (retries, trial ends, expirations) are run in a dedicated batch, reducing delays caused by other scheduled actions on your site.', 'woocommerce-subscriptions' ),
				'default'  => 'no',
				'type'     => 'checkbox',
			),
		);

		return array_merge( $settings, $section );
	}

	/**
	 * Resolve the effective rotation value: start from the stored option (or {@see DEFAULT_ROTATION} if unset),
	 * apply the {@see FILTER_ROTATION} filter, then clamp the result into the
	 * {@see MIN_ROTATION}..{@see MAX_ROTATION} band.
	 *
	 * Belt-and-suspenders clamping protects against filter callbacks (or stored option values written outside
	 * the UI) returning values that would otherwise destabilise rotation accounting in {@see Dedicated_Queue}.
	 *
	 * @return int
	 */
	public function get_effective_rotation(): int {
		$seed = (int) get_option( self::OPTION_ROTATION, self::DEFAULT_ROTATION );

		/**
		 * Filter the rotation value used by the WooCommerce Subscriptions dedicated queue.
		 *
		 * Use this to override the default per-site without exposing a UI dial. Returns are clamped to the
		 * {@see Settings::MIN_ROTATION}..{@see Settings::MAX_ROTATION} band before use.
		 *
		 * @since 8.8.0
		 *
		 * @param int $rotation Current rotation value (stored option, or DEFAULT_ROTATION if unset).
		 */
		$rotation = (int) apply_filters( self::FILTER_ROTATION, $seed );

		return max( self::MIN_ROTATION, min( self::MAX_ROTATION, $rotation ) );
	}
}
