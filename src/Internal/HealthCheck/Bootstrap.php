<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\HealthCheck;

/**
 * Entry point for the Subscriptions Health Check tool.
 *
 * The Health Check tool surfaces subscriptions that are on manual renewal
 * but have a payment method that supports automatic renewal, so merchants
 * can review and act on them. Gating is layered:
 *
 *   - `wcs_health_check_tool_enabled` filter (support-level escape hatch,
 *     default `true`) — flip via mu-plugin to disable the entire tool on a
 *     specific store without a code release. Hides the Status tab and
 *     prevents the schedule manager from registering at all.
 *   - `woocommerce_subscriptions_enable_health_check_nightly_scan` option
 *     (merchant-facing checkbox under WC > Settings > Subscriptions,
 *     default `'no'`) — controls only the nightly scheduled scan. The
 *     Status tab and "Run now" button stay available regardless: merchants
 *     can always trigger an on-demand scan even with the schedule disabled.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Bootstrap {

	/**
	 * Admin stylesheet handle for the Health Check Status tab.
	 */
	private const ADMIN_STYLE_HANDLE = 'wcs-health-check-admin';

	/**
	 * Admin script handle for the Health Check Status tab.
	 */
	private const ADMIN_SCRIPT_HANDLE = 'wcs-health-check-admin';

	/**
	 * Script handle for the resolve-dialog modal JS.
	 */
	private const DIALOG_SCRIPT_HANDLE = 'wcs-health-check-dialog';

	/**
	 * @var RemediationLock
	 */
	private RemediationLock $lock;

	/**
	 * @var CandidateStore
	 */
	private CandidateStore $candidate_store;

	/**
	 * @var RunStore
	 */
	private RunStore $run_store;

	/**
	 * @var RemediationAdvisor
	 */
	private RemediationAdvisor $advisor;

	/**
	 * @var ToolRunner
	 */
	private ToolRunner $runner;

	/**
	 * @var Admin\AjaxController|null
	 */
	private ?Admin\AjaxController $ajax_controller = null;

	public function __construct( ?RemediationLock $lock = null, ?CandidateStore $candidate_store = null, ?RunStore $run_store = null, ?RemediationAdvisor $advisor = null, ?ToolRunner $runner = null ) {
		$this->lock            = $lock ?? new RemediationLock();
		$this->candidate_store = $candidate_store ?? new CandidateStore();
		$this->run_store       = $run_store ?? new RunStore();
		$this->advisor         = $advisor ?? new RemediationAdvisor();
		$this->runner          = $runner ?? new ToolRunner();
	}

	/**
	 * Whether the Health Check tool surface is enabled at the support level.
	 *
	 * Distinct from the merchant nightly-scan toggle in `CircuitBreaker`:
	 * this filter is the entire-feature kill switch (no Status tab, no
	 * scheduled scan, no admin assets). The merchant toggle only gates
	 * the nightly scan.
	 *
	 * @return bool
	 */
	public function is_tool_enabled(): bool {
		/**
		 * Support-level kill switch for the Health Check tool as a whole —
		 * a superset of `wcs_health_check_scans_enabled` (CircuitBreaker),
		 * which only gates scan execution. When this filter returns false
		 * the admin Status tab disappears, the scheduled scan never
		 * registers, admin assets don't enqueue, and the settings-page
		 * checkbox stops rendering as well — there is no surface for the
		 * merchant to interact with the feature when support has forced
		 * it off.
		 *
		 * Drop-in delivery: this filter must be registered BEFORE the
		 * `plugins_loaded` tick that bootstraps the module, which in
		 * practice means an mu-plugin. A regular plugin's
		 * `plugins_loaded` callback is too late — we've already read the
		 * filter by then. For a site-level force-off ship a mu-plugin
		 * at `wp-content/mu-plugins/wcs-disable-health-check.php` with
		 * `add_filter( 'wcs_health_check_tool_enabled', '__return_false' )`.
		 *
		 * @since 8.7.0
		 *
		 * @param bool $enabled Whether the Health Check module is active. Defaults to true.
		 */
		return (bool) apply_filters( 'wcs_health_check_tool_enabled', true );
	}

	/**
	 * Wire up the Health Check components.
	 *
	 * Layout:
	 *   1. Tool-wide gate via `is_tool_enabled()` — when off, bail entirely.
	 *      No Status tab, no schedule, no settings UI.
	 *   2. Tables + StatusTab + admin assets always register when the tool
	 *      is enabled, so the merchant can always reach the tab and the
	 *      "Run now" button.
	 *   3. ScheduleManager handler bindings always register so the
	 *      SCAN_BATCH chain works for run-now invocations.
	 *   4. Recurring DAILY_SCAN registration is reconciled against the
	 *      merchant nightly-scan option on every Bootstrap run — see
	 *      `ScheduleManager::reconcile_daily_schedule()`. Both UI
	 *      surfaces redirect after writing the option, so the very
	 *      next request lands here again with the new value and the
	 *      schedule converges.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! $this->is_tool_enabled() ) {
			return;
		}

		// Hook at priority 999 so we run after every other extension's
		// `woocommerce_subscription_settings` callback (Gifting,
		// Downloads, Synchronization, Switching, etc., which use the
		// default priority 10). Without this our setting lands in the
		// middle of the page rather than at the bottom — Tim asked for
		// it to be the last setting on the Subscriptions tab.
		add_filter( 'woocommerce_subscription_settings', array( $this, 'add_settings' ), 999 );

		( new \WCS_Health_Check_Table_Maker() )->register_tables();

		$schedule_manager = new ScheduleManager();
		$schedule_manager->register();
		// Bootstrap runs on every admin request, so reconciling here is
		// enough — both UI surfaces (the in-tab button and the WC
		// settings form) `wp_safe_redirect()` after writing the option,
		// and the redirect is a fresh request that lands here again
		// with the new option value.
		$schedule_manager->reconcile_daily_schedule();

		// Admin surface — only in wp-admin. The Status tab hooks into
		// WooCommerce > Status via `woocommerce_admin_status_tabs`.
		// Meaningless on the frontend request path, so skipping the
		// registration keeps non-admin requests clean.
		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
			// AjaxController lazy-constructs the CandidatesListTable inside
			// its AJAX handlers — by then the wp-admin screen context is
			// fully loaded, so WP_List_Table's constructor (which calls
			// convert_to_screen()) can succeed. Constructing it eagerly here
			// fatals on the `init` hook because the wp-admin include hasn't
			// run yet.
			$this->ajax_controller = new Admin\AjaxController(
				$this->lock,
				$this->candidate_store,
				$this->run_store,
				$this->advisor,
				$this->runner
			);
			$this->ajax_controller->register();
			( new StatusTab() )->register();
		}
	}

	/**
	 * Append a dedicated Subscriptions Health Check section to the
	 * bottom of WC > Settings > Subscriptions. The section contains a
	 * single nightly-scan checkbox bound to
	 * `CircuitBreaker::OPTION_SCHEDULE_ENABLED`.
	 *
	 * Defaults to `'no'`: a fresh install does not run nightly scans
	 * until the merchant explicitly opts in. The Health Check tab
	 * itself stays visible regardless — only the AS-driven nightly
	 * scan is gated by this option.
	 *
	 * The checkbox's `desc_tip` embeds an anchor that deep-links into
	 * the Health Check Status tab so merchants who keep nightly scans
	 * off still see an obvious path to running an ad-hoc scan. WC core
	 * renders `desc_tip` for checkbox fields as
	 * `<p class="description">{desc_tip}</p>` without additional
	 * escaping (see `WC_Admin_Settings::get_field_description()`), so
	 * the anchor passes through cleanly; the URL is `esc_url()`-d and
	 * the link text is escaped via `esc_html__()`.
	 *
	 * @param array<int, array<string, mixed>> $settings Existing settings array.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function add_settings( $settings ) {
		$section_id = 'woocommerce_subscriptions_health_check_options';

		// The anchor open/close tags are passed as `%1$s` / `%2$s` so translators see a single,
		// complete sentence rather than a verb-phrase fragment they have to translate separately.
		// `esc_url()` runs on the href before it ever reaches the translatable string, and the
		// anchor tags themselves are not user-controllable, so the assembled fragment is safe.
		$tool_url = esc_url( admin_url( 'admin.php?page=wc-status&tab=' . StatusTab::TAB_SLUG ) );

		$health_check_section = array(
			array(
				'name' => __( 'Subscriptions health check', 'woocommerce-subscriptions' ),
				'type' => 'title',
				'id'   => $section_id,
			),
			array(
				'id'       => CircuitBreaker::OPTION_SCHEDULE_ENABLED,
				'name'     => __( 'Enable nightly scans', 'woocommerce-subscriptions' ),
				'desc'     => __( 'Allow nightly health check scans on your subscriptions', 'woocommerce-subscriptions' ),
				'desc_tip' => sprintf(
					/* translators: %1$s and %2$s wrap the link text "Subscriptions health check" in an <a> element pointing at the Health Check Status tab. */
					__( 'When enabled, a health check scan will run on your store each night to identify subscriptions that may need your attention. To view results or run a manual scan, go to %1$sSubscriptions health check%2$s.', 'woocommerce-subscriptions' ),
					'<a href="' . $tool_url . '">',
					'</a>'
				),
				'default'  => 'no',
				'type'     => 'checkbox',
			),
			array(
				'type' => 'sectionend',
				'id'   => $section_id,
			),
		);

		return array_merge( $settings, $health_check_section );
	}

	/**
	 * Enqueue Health Check admin assets. Scoped to WooCommerce > Status so
	 * other admin pages stay unaffected.
	 *
	 * @param mixed $hook_suffix Current admin page hook suffix.
	 *
	 * @return void
	 */
	public function enqueue_admin_assets( $hook_suffix = null ): void {
		if ( 'woocommerce_page_wc-status' !== $hook_suffix ) {
			return;
		}

		$core_plugin = \WC_Subscriptions_Core_Plugin::instance();
		$core_url    = $core_plugin->get_subscriptions_core_directory_url();

		// Build the filesystem path from the plugin root — not from
		// `get_subscriptions_core_directory()`, which appends `/includes`
		// and points below where the CSS actually lives.
		$plugin_dir = \WC_Subscriptions_Plugin::instance()->get_plugin_directory();
		$css_path   = $plugin_dir . 'assets/css/health-check-admin.css';

		// Use the file's mtime as the cache-bust token so every edit to the
		// CSS rewrites the `?ver=` query arg and browsers + CDNs pull fresh.
		// Falls back to the library version when the file is unreadable
		// (packaged release path, symlink edge cases).
		$version = file_exists( $css_path )
			? (string) filemtime( $css_path )
			: $core_plugin->get_library_version();

		wp_enqueue_style(
			self::ADMIN_STYLE_HANDLE,
			$core_url . 'assets/css/health-check-admin.css',
			array(),
			$version
		);

		// Floating-tooltip script: portals the warning bubble to <body> so it
		// escapes the candidates wrapper's overflow clipping. Same mtime
		// cache-bust strategy as the stylesheet above.
		$js_path    = $plugin_dir . 'assets/js/admin/health-check-admin.js';
		$js_version = file_exists( $js_path )
			? (string) filemtime( $js_path )
			: $core_plugin->get_library_version();

		wp_enqueue_script(
			self::ADMIN_SCRIPT_HANDLE,
			$core_url . 'assets/js/admin/health-check-admin.js',
			array(),
			$js_version,
			true
		);

		// Localize on the admin handle (not the dialog handle) so the
		// inline `var wcsHealthCheck = {...}` runs BEFORE admin.js. The
		// admin script's notices helper then extends the existing
		// global instead of getting overwritten when WP inlines the
		// localized values right before the next script tag.
		wp_localize_script(
			self::ADMIN_SCRIPT_HANDLE,
			'wcsHealthCheck',
			array_merge(
				$this->ajax_controller ? $this->ajax_controller->get_script_data() : array(),
				array(
					'i18n' => array(
						'unexpectedError' => __( 'An unexpected error occurred.', 'woocommerce-subscriptions' ),
						'noItemsToReview' => $this->build_no_items_message(),
						'opensInNewTab'   => __( 'opens in a new tab', 'woocommerce-subscriptions' ),
						'dismiss'         => __( 'Dismiss this notice.', 'woocommerce-subscriptions' ),
					),
				)
			)
		);

		$dialog_js_path    = $plugin_dir . 'assets/js/admin/health-check-dialog.js';
		$dialog_js_version = file_exists( $dialog_js_path )
			? (string) filemtime( $dialog_js_path )
			: $core_plugin->get_library_version();

		// Dialog depends on the admin handle so admin.js (and its
		// notices helper) is in the DOM before dialog.js needs to call
		// wcsHealthCheck.notices.inject().
		wp_enqueue_script(
			self::DIALOG_SCRIPT_HANDLE,
			$core_url . 'assets/js/admin/health-check-dialog.js',
			array( 'jquery', 'wc-backbone-modal', self::ADMIN_SCRIPT_HANDLE ),
			$dialog_js_version,
			true
		);
	}

	/**
	 * Build the empty-state message for the JS no-items row, matching the
	 * copy `CandidatesListTable::no_items()` renders server-side.
	 *
	 * @return string
	 */
	private function build_no_items_message(): string {
		$run_id = $this->run_store->get_latest_scan_run_id();

		if ( 0 === $run_id ) {
			return (string) __( 'No items to review.', 'woocommerce-subscriptions' );
		}

		$run      = $this->run_store->get( $run_id );
		$when_utc = is_array( $run ) ? (string) ( $run['completed_at'] ?? $run['started_at'] ?? '' ) : '';

		if ( '' === $when_utc ) {
			$how_long = (string) __( 'recently', 'woocommerce-subscriptions' );
		} else {
			$ts       = strtotime( $when_utc . ' UTC' );
			$how_long = false !== $ts ? human_time_diff( $ts, time() ) : (string) __( 'recently', 'woocommerce-subscriptions' );
		}

		return sprintf(
			/* translators: %s: human-readable time diff. */
			(string) __( 'No subscriptions currently need review. The latest scan completed %s ago.', 'woocommerce-subscriptions' ),
			esc_html( $how_long )
		);
	}
}
