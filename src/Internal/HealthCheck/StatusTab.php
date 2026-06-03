<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\HealthCheck;

use ActionScheduler_AdminView;
use Action_Scheduler\Migration\Controller as Migration_Controller;
use Automattic\WooCommerce_Subscriptions\Internal\HealthCheck\Admin\CandidatesListTable;
use Throwable;
use WCS_Action_Scheduler;

/**
 * The Health Check page as a tab inside WooCommerce > Status.
 *
 * The tab sits alongside System Status / Tools / Logs. It is server-
 * rendered (no React SPA) - the whole UI is a `WP_List_Table` plus a
 * single nonce-protected form POST in the page header that flips
 * between two states:
 *
 *   - **Run scan** (idle) - kicks off an on-demand scan through
 *     `ScheduleManager::start_scan()`. Always available regardless of
 *     the merchant's nightly-scan setting.
 *   - **Cancel scan** (busy) - cancels the in-flight scan through
 *     `ScheduleManager::cancel_scan()`. Soft cancel - candidates
 *     already detected stay in the table.
 *
 * The nightly-scan toggle is owned by WC > Settings > Subscriptions
 * (single source of truth). The tool surfaces a passive
 * "Nightly scans on/off" status line near the LAST SCAN card with a
 * Manage link to the Settings section, but it does NOT write to the
 * option directly.
 *
 * The tool is read-only otherwise: there is no "Restore auto-renewal"
 * button, no bulk actions, no undo flow in v1. Merchants see the table,
 * click through to the subscription edit screen, and act manually; the
 * 24-hour re-scan keeps the list fresh so resolved rows drop off.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class StatusTab {

	/**
	 * Slug used by `admin.php?page=wc-status&tab=<slug>` routing and as
	 * the suffix on the `woocommerce_admin_status_content_<slug>` action.
	 */
	public const TAB_SLUG = 'wcs-health-check';

	/**
	 * `wp_kses` allowlist used when echoing the F4-B inline scope HTML on the LAST SCAN card.
	 * Only `<span class>` is permitted; the literal `&middot;` entity carried inside the span
	 * is preserved by `wp_kses` regardless of the allowlist.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private const INLINE_SCOPE_ALLOWED_HTML = array(
		'span' => array(
			'class' => array(),
		),
	);

	/**
	 * `wp_kses` allowlist used when echoing the F4-D inline progress fragment ("Scanning now…
	 * - N of M subscriptions scanned") and the screen-reader live region. Permits `<span class>`
	 * (with the `role` + `aria-live` attributes the live region carries) plus the `<strong>` tags
	 * that wrap the count numerators inside `try_get_live_progress_label()`.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private const INLINE_PROGRESS_ALLOWED_HTML = array(
		'span'   => array(
			'class'     => array(),
			'role'      => array(),
			'aria-live' => array(),
		),
		'strong' => array(),
	);

	/**
	 * @var RunStore
	 */
	private $run_store;

	/**
	 * @var CandidateStore
	 */
	private $candidate_store;

	/**
	 * @var ScheduleManager
	 */
	private $schedule_manager;

	/**
	 * @var Tracks
	 */
	private $tracks;

	/**
	 * @var CircuitBreaker
	 */
	private $circuit_breaker;

	public function __construct(
		?RunStore $run_store = null,
		?CandidateStore $candidate_store = null,
		?ScheduleManager $schedule_manager = null,
		?Tracks $tracks = null,
		?CircuitBreaker $circuit_breaker = null
	) {
		$this->run_store        = $run_store ?? new RunStore();
		$this->candidate_store  = $candidate_store ?? new CandidateStore();
		$this->schedule_manager = $schedule_manager ?? new ScheduleManager();
		$this->tracks           = $tracks ?? new Tracks();
		$this->circuit_breaker  = $circuit_breaker ?? new CircuitBreaker();
	}

	/**
	 * Register the two WC integration hooks:
	 *   - filter: add the tab to the WC Status nav.
	 *   - action: render the body when the tab is active.
	 * Plus an `admin_init` hook to process the tab's form POSTs before
	 * anything renders (so `wp_safe_redirect()` can fire cleanly).
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_admin_status_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_admin_status_content_' . self::TAB_SLUG, array( $this, 'render' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_actions' ) );
		// Screen Options drawer: runs on the WC Status page load,
		// gated so we only advertise our columns when the merchant is
		// on our tab. WP reads `manage_{screen_id}_columns` + stores
		// hidden-columns prefs under `manage{screen_id}columnshidden`
		// user meta; matching the live screen id lets the default
		// drawer UI pick up our column list without custom rendering.
		add_action( 'load-woocommerce_page_wc-status', array( $this, 'maybe_register_screen_options' ) );
		add_action( 'admin_notices', array( $this, 'maybe_suppress_action_scheduler_notices' ), 0 );
	}

	/**
	 * Append our tab to the WooCommerce Status nav.
	 *
	 * @param array<string, string> $tabs Existing tab slug => label map.
	 *
	 * @return array<string, string>
	 */
	public function add_tab( $tabs ): array {
		if ( ! is_array( $tabs ) ) {
			$tabs = array();
		}
		$tabs[ self::TAB_SLUG ] = __( 'Subscriptions', 'woocommerce-subscriptions' );
		return $tabs;
	}

	/**
	 * Register Screen Options (the per-user column-visibility drawer)
	 * when the merchant lands on the Health Check tab.
	 *
	 * WordPress's column-hiding UI is wired off three things:
	 *   1. `get_column_headers($screen)` returning our column list.
	 *   2. `manage_{screen_id}_columns` filter advertising which
	 *      columns are registered.
	 *   3. `get_hidden_columns($screen)` reading the user's saved
	 *      preferences from
	 *      `usermeta.manage{screen_id}columnshidden`.
	 *
	 * Hooking `manage_woocommerce_page_wc-status_columns` during the
	 * page-load action ties our list table's columns to the live WP
	 * screen, so the Screen Options drawer renders with our checkboxes
	 * without any custom UI code. Gated on `tab === wcs-health-check`
	 * so we don't leak our columns onto the System Status / Tools /
	 * Logs tabs.
	 *
	 * @return void
	 */
	public function maybe_register_screen_options(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab gate.
		if ( ! isset( $_GET['tab'] ) || self::TAB_SLUG !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
			return;
		}

		// Advertise the Health Check columns to WP's Screen Options
		// drawer. Reaching back through `CandidatesListTable` for the
		// list avoids duplicating the column set here — any future
		// column addition lands in a single place.
		add_filter(
			'manage_woocommerce_page_wc-status_columns',
			static function () {
				return ( new CandidatesListTable() )->get_columns();
			}
		);

		// Hide the Renewal preference column by default on the Missing
		// renewals tab — it's a Supports-auto-renewal-specific signal
		// (customer self-serve opt-out / re-enable) that adds noise
		// for the missing-schedule diagnosis. Merchants can still
		// re-enable it via Screen Options. WP applies
		// `default_hidden_columns` only when the user has no saved
		// preference, so this won't override an explicit toggle.
		add_filter(
			'default_hidden_columns',
			static function ( $hidden, $screen ) {
				if ( ! ( $screen instanceof \WP_Screen ) || 'woocommerce_page_wc-status' !== $screen->id ) {
					return $hidden;
				}
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view gate.
				$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
				if ( 'missing_renewals' !== $view ) {
					return $hidden;
				}
				$hidden   = is_array( $hidden ) ? $hidden : array();
				$hidden[] = 'renewal_preference';
				return array_values( array_unique( $hidden ) );
			},
			10,
			2
		);
	}

	/**
	 * Remove the two Action-Scheduler-owned `admin_notices` callbacks while the merchant is on our tab.
	 *
	 * Both notices are generated by Action Scheduler. Letting them render on our tab is confusing and adds
	 * additional noise, especially as the past-due notice surfaces a site-wide count that diverges from our
	 * WCS-group-scoped SCHEDULED ACTIONS card, and the migration notice has no bearing on a merchant looking
	 * at subscription health.
	 *
	 * @return void
	 */
	public function maybe_suppress_action_scheduler_notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen || 'woocommerce_page_wc-status' !== $screen->id ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab gate, no state mutation.
		if ( ! isset( $_GET['tab'] ) || self::TAB_SLUG !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
			return;
		}

		if ( class_exists( ActionScheduler_AdminView::class ) ) {
			remove_action( 'admin_notices', array( ActionScheduler_AdminView::instance(), 'maybe_check_pastdue_actions' ) );
		}

		if ( class_exists( Migration_Controller::class ) ) {
			remove_action( 'admin_notices', array( Migration_Controller::instance(), 'display_migration_notice' ) );
		}
	}

	/**
	 * Process the tab's form POSTs.
	 *
	 * Runs on `admin_init` — well before rendering — so `wp_safe_redirect()`
	 * can fire before any output is sent.
	 *
	 * @return void
	 */
	public function maybe_handle_actions(): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$action = isset( $_POST['wcs_hc_action'] )
			? sanitize_key( wp_unslash( $_POST['wcs_hc_action'] ) )
			: '';
		if ( '' === $action ) {
			return;
		}

		$nonce = isset( $_POST['_wpnonce'] )
			? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, 'wcs_hc_' . $action ) ) {
			// Swallow the action on a bad nonce — do NOT wp_die. The tab
			// render path is the user-visible surface; adding a generic
			// "Cheatin'?" page for a stale form POST is a worse UX than
			// silently refusing the action.
			return;
		}

		switch ( $action ) {
			case 'run_scan':
				$redirect_url = $this->run_scan();
				break;
			case 'cancel_scan':
				$redirect_url = $this->cancel_scan();
				break;
			default:
				return;
		}

		$this->redirect_and_exit( $redirect_url );
	}

	/**
	 * Render the tab body. Called from the WC Status router via
	 * `do_action( 'woocommerce_admin_status_content_wcs-health-check' )`.
	 *
	 * @return void
	 */
	public function render(): void {
		// `get_latest_terminal_run()` returns the most recent terminal row regardless of outcome
		// (completed / failed / cancelled). It deliberately differs from the autoloaded
		// `LATEST_SCAN_RUN_ID_OPTION`, which `RunStore::complete()` only promotes for genuinely
		// completed scans - so a cancelled run will never surface as the "latest" through that
		// option, and the LAST SCAN card needs this broader query to render "Cancelled X ago"
		// or "Failed X ago" when the most recent terminal state isn't `completed`.
		$latest_terminal_run = $this->run_store->get_latest_terminal_run();
		$in_flight           = $this->run_store->get_in_flight_scan();

		$run_now_busy      = null !== $in_flight;
		$run_scan_label    = __( 'Run scan', 'woocommerce-subscriptions' );
		$cancel_scan_label = __( 'Cancel scan', 'woocommerce-subscriptions' );
		// `data-wcs-hc-scan-inflight` is the JS "start polling" signal — present only while a scan
		// is running. health-check-admin.js reads it on init and background-polls the scan-status
		// endpoint, updating the inline count in place and reloading once on a terminal state.
		?>
		<div class="woocommerce-subscriptions-health-check-tab"<?php echo $run_now_busy ? ' data-wcs-hc-scan-inflight="1"' : ''; ?>>
			<?php $this->maybe_render_query_arg_notice(); ?>
			<?php $this->maybe_render_tripped_notice(); ?>

			<div class="woocommerce-subscriptions-health-check-header">
				<div class="woocommerce-subscriptions-health-check-header-title">
					<h2 class="wp-heading-inline"><?php esc_html_e( 'Subscriptions health check', 'woocommerce-subscriptions' ); ?></h2>
					<p class="description">
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: "Learn more." link to the Subscriptions Health Check documentation. */
								__( "Scan your store's subscriptions for conditions that may need attention. %s", 'woocommerce-subscriptions' ),
								sprintf(
									'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
									esc_url( 'https://woocommerce.com/document/woocommerce-subscriptions-health-check/' ),
									esc_html__( 'Learn more.', 'woocommerce-subscriptions' )
								)
							),
							array(
								'a' => array(
									'href'   => array(),
									'target' => array(),
									'rel'    => array(),
								),
							)
						);
						?>
					</p>
				</div>
				<div class="woocommerce-subscriptions-health-check-header-actions">
					<?php if ( $run_now_busy ) : ?>
						<form method="post" class="woocommerce-subscriptions-health-check-cancel-scan-form">
							<?php wp_nonce_field( 'wcs_hc_cancel_scan' ); ?>
							<input type="hidden" name="wcs_hc_action" value="cancel_scan" />
							<button type="submit" class="button woocommerce-subscriptions-health-check-cancel-button" aria-busy="true">
								<?php /* F4-E: icon-first layout - spinner sits BEFORE the label. */ ?>
								<span class="woocommerce-subscriptions-health-check-spinner" aria-hidden="true"></span>
								<?php echo esc_html( $cancel_scan_label ); ?>
							</button>
							<span class="screen-reader-text" role="status">
								<?php esc_html_e( 'Scan in progress.', 'woocommerce-subscriptions' ); ?>
							</span>
						</form>
					<?php else : ?>
						<form method="post" class="woocommerce-subscriptions-health-check-run-now-form">
							<?php wp_nonce_field( 'wcs_hc_run_scan' ); ?>
							<input type="hidden" name="wcs_hc_action" value="run_scan" />
							<button type="submit" class="button button-primary">
								<?php echo esc_html( $run_scan_label ); ?>
							</button>
						</form>
					<?php endif; ?>
				</div>
			</div>

			<?php
			// In-flight progress is now driven by health-check-admin.js (gated on the
			// `data-wcs-hc-scan-inflight` hook on the wrapper above): it background-polls the
			// scan-status endpoint every few seconds, updates the inline "N of M" count in place,
			// and reloads the page exactly once when the scan reaches a terminal state — replacing
			// the previous blanket 8 s full-page reload that flashed the whole tab on every tick.
			?>

			<?php $this->render_header_cards( $in_flight, $latest_terminal_run ); ?>

			<?php
			$table = new CandidatesListTable( $this->run_store, $this->candidate_store );
			$table->prepare_items();
			?>
			<form method="get" class="woocommerce-subscriptions-health-check-candidates-form">
				<input type="hidden" name="page" value="wc-status" />
				<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB_SLUG ); ?>" />
				<?php $table->views(); ?>
				<?php $this->render_search_box(); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php $this->render_preview_modal_template(); ?>
		<?php
	}

	/**
	 * Output the Underscore/Backbone template for the subscription
	 * resolve modal. Uses the same `wc-backbone-modal` chrome as the
	 * WooCommerce Orders preview — header + article + footer — so
	 * inherited WC admin CSS handles layout and animations.
	 *
	 * The template id (`tmpl-wcs-health-check-dialog-modal`) maps to the
	 * `template` value passed to `$.fn.WCBackboneModal()` in
	 * `health-check-dialog.js`.
	 *
	 * @return void
	 */
	private function render_preview_modal_template(): void {
		?>
		<script type="text/template" id="tmpl-wcs-health-check-dialog-modal">
			<div class="wc-backbone-modal wcs-health-check-dialog-modal">
				<div class="wc-backbone-modal-content">
					<section class="wc-backbone-modal-main" role="dialog" aria-modal="true" aria-labelledby="wcs-health-check-dialog-title" tabindex="-1">
						<header class="wc-backbone-modal-header">
							<h1 id="wcs-health-check-dialog-title"><?php esc_html_e( 'Subscriptions Health Insights', 'woocommerce-subscriptions' ); ?></h1>
							<button class="modal-close modal-close-link dashicons dashicons-no-alt">
								<span class="screen-reader-text"><?php esc_html_e( 'Close modal panel', 'woocommerce-subscriptions' ); ?></span>
							</button>
						</header>
						<article>
							<div class="wcs-health-check-dialog-loading">
								<span class="spinner is-active"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Loading subscription details…', 'woocommerce-subscriptions' ); ?></span>
							</div>
							<div class="wcs-health-check-dialog-explanation" style="display:none;">
								<p></p>
							</div>
							<div class="wcs-health-check-dialog-error" role="alert" style="display:none;">
								<p></p>
							</div>
						</article>
						<footer>
							<div class="wcs-health-check-dialog-actions" style="display:none;">
								<button class="button wcs-health-check-action-secondary" data-action=""></button>
								<button class="button button-primary wcs-health-check-action-primary" data-action=""></button>
							</div>
						</footer>
					</section>
				</div>
			</div>
			<div class="wc-backbone-modal-backdrop modal-close"></div>
		</script>
		<?php
	}

	/**
	 * Render the three-card header strip.
	 *
	 * Cards:
	 *   1. Last scan         - "Completed X ago" / "Failed X ago" / "Cancelled X ago"
	 *                          (or "Scanning now…" while a scan is in flight).
	 *                          Inline muted scope ("N subscriptions scanned") appears
	 *                          after the headline on completed / failed runs (F4-B).
	 *                          The previously-standalone Scope card was removed in F4-B
	 *                          and inlined here.
	 *   2. Plugin version    - "WooCommerce Subscriptions X.Y.Z" / "Up to
	 *                          date ✓" or update marker.
	 *   3. Scheduled subscription actions
	 *                        - past-due-action count for our AS group, with a
	 *                          link to the AS admin screen and a pointer to
	 *                          our Processing reliability settings when the
	 *                          count is non-zero. Scope is carried by the
	 *                          card title so the body copy stays terse.
	 *
	 * F4-B removed the standalone SCOPE card; scope now reads inline on the LAST SCAN
	 * card's value to slim the header strip.
	 *
	 * @param array<string, mixed>|null $in_flight_run       The currently running scan row (or null when idle).
	 *                                                       The Last-scan card surfaces that state so the strip
	 *                                                       doesn't contradict the Cancel-scan spinner during the
	 *                                                       8 s reload cycle.
	 * @param array<string, mixed>|null $latest_terminal_run The latest terminal-status scan row regardless of
	 *                                                       outcome (completed / failed / cancelled), or null when
	 *                                                       no terminal scan exists yet. Drives the LAST SCAN card
	 *                                                       so a cancelled / failed run can surface with its own
	 *                                                       headline rather than the option-tracked completed one.
	 *
	 * @return void
	 */
	private function render_header_cards( ?array $in_flight_run, ?array $latest_terminal_run = null ): void {
		?>
		<div class="notice notice-info inline woocommerce-subscriptions-health-check-summary">
			<div class="woocommerce-subscriptions-health-check-summary-col woocommerce-subscriptions-health-check-summary-col-last-scan">
				<span class="woocommerce-subscriptions-health-check-summary-label"><?php esc_html_e( 'Last scan', 'woocommerce-subscriptions' ); ?></span>
				<?php $this->render_last_scan_value( $latest_terminal_run, $in_flight_run ); ?>
				<?php $this->render_nightly_status_line(); ?>
			</div>
			<div class="woocommerce-subscriptions-health-check-summary-col woocommerce-subscriptions-health-check-summary-col-version">
				<span class="woocommerce-subscriptions-health-check-summary-label"><?php esc_html_e( 'Plugin version', 'woocommerce-subscriptions' ); ?></span>
				<?php $this->render_version_value(); ?>
			</div>
			<div class="woocommerce-subscriptions-health-check-summary-col woocommerce-subscriptions-health-check-summary-col-scheduled-actions">
				<span class="woocommerce-subscriptions-health-check-summary-label"><?php esc_html_e( 'Scheduled subscription actions', 'woocommerce-subscriptions' ); ?></span>
				<?php $this->render_action_scheduler_value(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Compose the Last-scan card's body.
	 *
	 * Branching:
	 *  - **In-flight** (`$in_flight_run !== null`) - renders "Scanning now…" as the headline regardless of
	 *    whether a prior terminal run exists, plus an inline muted "N of M subscriptions scanned" progress
	 *    span when the store has any subscriptions. The previous terminal headline is intentionally
	 *    suppressed so the active state is unambiguous (F4-D). Returns after rendering; the active state
	 *    owns the entire card.
	 *  - **Cancelled latest** - "Cancelled X ago" headline + a partial-counts secondary line built from
	 *    the snapshot `ScheduleManager::cancel_scan()` persists on the run row.
	 *  - **Failed latest** - "Failed X ago" headline + the F4-B inline scope span.
	 *  - **Completed latest** - "Completed X ago" headline + the F4-B inline scope span.
	 *  - **No prior run + idle** - "No scan yet" empty state.
	 *
	 * Schedule-state details (on/off + "next in X" + Manage link) live entirely in
	 * `render_nightly_status_line()`, which is invoked once from `render_header_cards()`. This
	 * function therefore does NOT emit a secondary "Next scheduled" / "Due now" countdown - that
	 * duplicated copy was removed alongside the SCOPE card.
	 *
	 * @param array<string, mixed>|null $latest_terminal_run The most recent terminal scan row regardless of
	 *                                                       outcome (completed / failed / cancelled), or null
	 *                                                       when none exists yet.
	 * @param array<string, mixed>|null $in_flight_run       The currently running scan row, or null when idle.
	 *                                                       Used to look up the live `record_scanned` counter
	 *                                                       for the inline progress span.
	 *
	 * @return void
	 */
	private function render_last_scan_value( ?array $latest_terminal_run, ?array $in_flight_run ): void {
		// Active state short-circuit (F4-D). When a scan is in flight the merchant clicked Run scan or the
		// cron fired; both signal the previous terminal headline should yield to "Scanning now…" with the
		// live progress reading inline. The terminal-state branches below are unreachable while busy.
		if ( null !== $in_flight_run ) {
			$store_total    = CandidatesListTable::count_all_subscriptions();
			$progress_label = $this->try_get_live_progress_label( $in_flight_run, $store_total );

			if ( null !== $progress_label ) {
				// The `&middot;` separator stays OUTSIDE the inner `-progress-label` span so the JS
				// poll can swap just the count markup without re-emitting the bullet, and so the
				// screen-reader live region (below) never reads the dot. No leading space — the outer
				// span's `margin-inline-start: 4px` owns the gap after "Scanning now…".
				$inline_progress = sprintf(
					'<span class="woocommerce-subscriptions-health-check-last-scan-scope">&middot; <span class="woocommerce-subscriptions-health-check-progress-label">%s</span></span>',
					$progress_label
				);

				// Visually-hidden polite live region carrying the plain-text reading (no markup, no
				// bullet) so assistive tech announces the updated count each poll without flooding
				// the user with the surrounding chrome. Clamp identically to the visible label so the
				// SR announcement never diverges from the on-screen "N of M".
				$progress_scanned = min(
					max( $this->circuit_breaker->get_total_scanned( (int) $in_flight_run['id'] ), 0 ),
					$store_total
				);
				$progress_text    = ScanProgress::format_text( $progress_scanned, $store_total );
				$live_region      = null === $progress_text
					? ''
					: sprintf(
						'<span class="screen-reader-text woocommerce-subscriptions-health-check-progress-live" role="status" aria-live="polite">%s</span>',
						esc_html( $progress_text )
					);

				printf(
					'<div class="woocommerce-subscriptions-health-check-card-primary">%s%s%s</div>',
					esc_html__( 'Scanning now…', 'woocommerce-subscriptions' ),
					wp_kses( $inline_progress, self::INLINE_PROGRESS_ALLOWED_HTML ),
					wp_kses( $live_region, self::INLINE_PROGRESS_ALLOWED_HTML )
				);
			} else {
				// Empty store (or live counter unavailable). Headline alone communicates the state.
				printf(
					'<div class="woocommerce-subscriptions-health-check-card-primary">%s</div>',
					esc_html__( 'Scanning now…', 'woocommerce-subscriptions' )
				);
			}
			return;
		}

		if ( null === $latest_terminal_run ) {
			printf(
				'<div class="woocommerce-subscriptions-health-check-card-primary">%s</div>',
				esc_html__( 'No scan yet', 'woocommerce-subscriptions' )
			);
		} else {
			$when_utc     = (string) ( $latest_terminal_run['completed_at'] ?? $latest_terminal_run['started_at'] ?? '' );
			$time_ago     = $this->human_time_since_mysql_utc( $when_utc );
			$status       = (string) ( $latest_terminal_run['status'] ?? '' );
			$stats        = $this->decode_run_stats( $latest_terminal_run );
			$inline_scope = $this->build_inline_scope_html( $stats );

			if ( RunStore::STATUS_CANCELLED === $status ) {
				printf(
					'<div class="woocommerce-subscriptions-health-check-card-primary">%s</div>',
					sprintf(
						/* translators: %s: human-readable time diff like "5 minutes". */
						esc_html__( 'Cancelled %s ago', 'woocommerce-subscriptions' ),
						esc_html( $time_ago )
					)
				);

				// Surface the partial-progress snapshot persisted by RunStore::cancel() so the merchant sees how
				// far the scan got before they aborted it. The snapshot keys (`subscriptions_scanned`,
				// `total_subscriptions`) mirror the Tracks event payload emitted by ScheduleManager::cancel_scan().
				// The dedicated partial-counts line replaces the inline scope used by Completed / Failed, so we
				// do NOT render the inline scope here - the partial-counts line carries the equivalent signal.
				$this->render_cancelled_partial_counts( $stats );
			} elseif ( RunStore::STATUS_FAILED === $status ) {
				// Failed runs reach the LAST SCAN card via `RunStore::get_latest_terminal_run()` (the
				// `LATEST_SCAN_RUN_ID_OPTION` autoload tracks completed runs only). Surface them with a
				// dedicated "Failed X ago" headline so merchants can tell at a glance that the most
				// recent run did not finish. The underlying `error_message` is intentionally NOT echoed
				// here - that is an internal / log-only field; the merchant-facing diagnostic surfaces
				// through the circuit-breaker tripped notice when the breaker flips. The inline scope
				// span (F4-B) appears after the headline so the merchant still sees how far the failed
				// scan got before it died.
				printf(
					'<div class="woocommerce-subscriptions-health-check-card-primary">%s%s</div>',
					sprintf(
						/* translators: %s: human-readable time diff like "5 minutes". */
						esc_html__( 'Failed %s ago', 'woocommerce-subscriptions' ),
						esc_html( $time_ago )
					),
					wp_kses( $inline_scope, self::INLINE_SCOPE_ALLOWED_HTML )
				);
			} else {
				// The inline scope span (F4-B) replaces the SCOPE column. It renders in a muted-grey
				// class so the headline carries the visual weight; the scope reads as supporting info.
				printf(
					'<div class="woocommerce-subscriptions-health-check-card-primary">%s%s</div>',
					sprintf(
						/* translators: %s: human-readable time diff like "5 minutes". */
						esc_html__( 'Completed %s ago', 'woocommerce-subscriptions' ),
						esc_html( $time_ago )
					),
					wp_kses( $inline_scope, self::INLINE_SCOPE_ALLOWED_HTML )
				);
			}
		}

		// Schedule-state details (on/off + "next in X" + Manage link) live entirely in
		// `render_nightly_status_line()` (invoked once from `render_header_cards()`). Earlier
		// drafts mirrored the same info as "Next scheduled in X" / "Due now" / "Nightly scans
		// disabled" secondaries under the LAST SCAN value — both have been removed (F4-C +
		// follow-up) so there is exactly one canonical nightly-status surface on the tab.
	}

	/**
	 * Decode the persisted `stats_json` blob on a run row back into an
	 * associative array. Returns an empty array on missing / unreadable
	 * payloads so callers can always treat the return value as an array.
	 *
	 * @param array<string, mixed> $run Run row as returned by RunStore::get*.
	 *
	 * @return array<string, mixed>
	 */
	private function decode_run_stats( array $run ): array {
		$raw = $run['stats_json'] ?? null;
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Build the inline muted-grey scope span that appears immediately after the "Completed X ago"
	 * (or "Failed X ago") headline on the LAST SCAN card.
	 *
	 * Replaces the SCOPE column removed in F4-B - per the updated design the scope text reads as
	 * supporting info on the LAST SCAN value instead of a standalone card. Reads the per-run
	 * scanned count from stats_json. Prefers the semantic `subscriptions_scanned` key (written by
	 * `ScheduleManager::cancel_scan()` and parity-friendly with the Tracks event payload), falls
	 * back to `total_scanned` (written by `collect_run_stats()` on completed/failed runs).
	 *
	 * Returns the empty string when no usable count exists on the row - the headline alone
	 * communicates the state. The output already has the leading separator " &middot; " baked in
	 * (rendered as a centred dot) so the caller can concatenate without worrying about spacing.
	 *
	 * @param array<string, mixed> $stats Decoded `stats_json` payload.
	 *
	 * @return string Pre-escaped HTML fragment ready to concatenate after the headline.
	 */
	private function build_inline_scope_html( array $stats ): string {
		$scanned = isset( $stats['subscriptions_scanned'] )
			? (int) $stats['subscriptions_scanned']
			: (int) ( $stats['total_scanned'] ?? 0 );

		if ( $scanned <= 0 ) {
			return '';
		}

		$label = sprintf(
			/* translators: %s: count of subscriptions inspected during the most recent scan. */
			_n(
				'%s subscription scanned',
				'%s subscriptions scanned',
				$scanned,
				'woocommerce-subscriptions'
			),
			number_format_i18n( $scanned )
		);

		// No leading space before the span — the `.last-scan-scope` rule's `margin-inline-start: 4px`
		// owns the gap after the headline. A literal space here would stack with that margin and read
		// as a double space between "Completed X ago" and the bullet.
		return sprintf(
			'<span class="woocommerce-subscriptions-health-check-last-scan-scope">&middot; %s</span>',
			esc_html( $label )
		);
	}

	/**
	 * Render the partial-progress secondary line shown under "Cancelled X
	 * ago" on the LAST SCAN card. Uses the snapshot persisted by
	 * `RunStore::cancel()` via `ScheduleManager::cancel_scan()` so the
	 * merchant sees how far the scan got before they aborted it.
	 *
	 * Renders nothing when no usable counts exist on the row - the
	 * "Cancelled X ago" headline alone communicates the state.
	 *
	 * @param array<string, mixed> $stats Decoded `stats_json` payload.
	 *
	 * @return void
	 */
	private function render_cancelled_partial_counts( array $stats ): void {
		$scanned = isset( $stats['subscriptions_scanned'] ) ? (int) $stats['subscriptions_scanned'] : 0;
		$total   = isset( $stats['total_subscriptions'] ) ? (int) $stats['total_subscriptions'] : 0;

		if ( $scanned <= 0 && $total <= 0 ) {
			return;
		}

		if ( $total > 0 ) {
			$label = sprintf(
				/* translators: 1: bold-wrapped count of subscriptions scanned before cancel. 2: bold-wrapped store total. */
				__( '%1$s of ~%2$s scanned before cancel', 'woocommerce-subscriptions' ),
				'<strong>' . esc_html( number_format_i18n( $scanned ) ) . '</strong>',
				'<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>'
			);
		} else {
			$label = sprintf(
				/* translators: %s: bold-wrapped count of subscriptions scanned before cancel. */
				__( '%s scanned before cancel', 'woocommerce-subscriptions' ),
				'<strong>' . esc_html( number_format_i18n( $scanned ) ) . '</strong>'
			);
		}

		printf(
			'<div class="woocommerce-subscriptions-health-check-card-secondary">%s</div>',
			wp_kses( $label, array( 'strong' => array() ) )
		);
	}

	/**
	 * Build the bold-wrapped "**N** of **M** subscriptions scanned" fragment surfaced on the Scope card
	 * during an in-flight scan, or `null` when the store is empty and the reading would be meaningless.
	 *
	 * Pre-first-batch (`$in_flight_scanned === 0`) still renders "0 of M" — clamping the missing reading to
	 * 0 reads as "scan started, will progress" and gives the merchant a consistent fractional display from
	 * click through to completion, instead of toggling between "Scan in progress…" copy and a numeric
	 * reading once the first batch lands.
	 *
	 * `$store_total` is passed in by the caller rather than fetched here so the re-run path (which already
	 * computed it for the prior-scan primary line) does not issue a second `SELECT COUNT(*)` per render.
	 * The public `count_all_subscriptions()` is intentionally uncached for the StatusTab caller; honouring
	 * the "once per render" contract from commit d6f629fc0 keeps page renders cheap during the 8 s reload
	 * cycle on large stores.
	 *
	 * `$in_flight_scanned` is capped at `$store_total` so a delete-during-scan race never produces a "210 of
	 * 200" reading. Returns raw HTML — caller is responsible for `wp_kses`-ing it with the `strong`
	 * allowlist.
	 *
	 * @param array<string, mixed> $in_flight_run The currently-running scan row.
	 * @param int                  $store_total   Current store-wide subscription total. Pass <= 0 to signal
	 *                                            an empty store; the helper returns null in that case.
	 *
	 * @return string|null Translated label with `<strong>` tags around the two counts, or null when the
	 *                     store has no subscriptions to scan at all (the "0 of 0" reading would be broken,
	 *                     so the caller falls back to a static "Scan in progress…" copy).
	 */
	private function try_get_live_progress_label( array $in_flight_run, int $store_total ): ?string {
		if ( $store_total <= 0 ) {
			return null;
		}

		// `get_total_scanned()` returns the running tally of subscriptions surfaced
		// by the per-signal SQL shortlists (Detector::candidate_ids()), not every
		// active subscription the scan walked past. The value therefore underreports
		// the inspected-subs count - on a typical store the SQL filters narrow
		// aggressively, so the denominator climbs faster than the numerator. Read
		// the rendered "X of Y subscriptions scanned" copy as a coarse progress
		// indicator, not an exact sub-count.
		//
		// If we want a more representative ratio later, the cleanest option is to
		// swap the denominator from "all subscriptions in the store" to a snapshot
		// of the SQL-shortlist totals (one COUNT(*) per signal at run start,
		// persisted in stats_json). Out of scope for now - keeping the simpler
		// store-total denominator until there is a concrete ask.
		$in_flight_scanned = $this->circuit_breaker->get_total_scanned( (int) $in_flight_run['id'] );
		$in_flight_scanned = min( max( $in_flight_scanned, 0 ), $store_total );

		// The "N of M subscriptions scanned" copy + clamp now live in ScanProgress, the
		// single source of truth shared with the wcs_health_check_scan_status AJAX poll, so
		// the format string is not duplicated between the server render and the JS response.
		return ScanProgress::format_label( $in_flight_scanned, $store_total );
	}

	/**
	 * Compose the Plugin-version card's body — primary line is the
	 * current version string, secondary line the patch-applied /
	 * out-of-date marker.
	 *
	 * @return void
	 */
	private function render_version_value(): void {
		$current_version = class_exists( '\\WC_Subscriptions' ) ? (string) \WC_Subscriptions::$version : '0.0.0';
		printf(
			'<div class="woocommerce-subscriptions-health-check-card-primary">%s</div>',
			sprintf(
				/* translators: %s: WooCommerce Subscriptions version. */
				esc_html__( 'WooCommerce Subscriptions %s', 'woocommerce-subscriptions' ),
				esc_html( $current_version )
			)
		);
		$this->render_version_marker();
	}

	/**
	 * Compose the Scheduled-subscription-actions card's body — primary line is a count of past-due actions in
	 * our group, secondary line carries a single action link (only rendered when the count is non-zero, so a
	 * healthy site doesn't get nudged toward admin screens it has no reason to visit).
	 *
	 * "Past-due" is defined by Action Scheduler's own `action_scheduler_pastdue_actions_seconds` filter (default
	 * 1 day): a pending action whose run-time was more than that many seconds ago. Reusing AS's filter means
	 * operators tuning AS once see consistent behaviour on this card and in AS's own admin notices.
	 *
	 * The card's scope (subscription actions only) is carried by the title, not the body copy. The count line
	 * is plain text — the "Go to scheduled actions" link lives on the row beneath it (→ the AS admin screen's
	 * past-due view, un-scoped because AS doesn't accept a group filter as a query arg). The title prepares the
	 * merchant for the un-scoped AS view they'll see when they click through. A second "Review settings" link
	 * was dropped as redundant with the nightly-status "Manage" link, which already deep-links to the
	 * Subscriptions settings tab.
	 *
	 * @return void
	 */
	private function render_action_scheduler_value(): void {
		$count = $this->get_past_due_action_count();

		$count_label = sprintf(
			/* translators: %d: count of past-due Action Scheduler actions in our group. Verb agrees with count: "action" for 1, "actions" for 0 or 2+. */
			_n(
				'%d past-due action',
				'%d past-due actions',
				$count,
				'woocommerce-subscriptions'
			),
			number_format_i18n( $count )
		);
		if ( 0 === $count ) {
			// Trailing checkmark on the zero-state primary line — mirrors the version card's "Up to date ✓"
			// pattern, signalling a healthy reading at a glance. Appended outside the gettext call so we don't
			// double the singular/plural strings translators have to handle.
			$count_label .= ' ✓';
		}
		// Whole line reads at the bold `-card-primary` weight (600), matching the other status-card primary
		// lines; the count is plain text with no inline markup to keep.
		printf(
			'<div class="woocommerce-subscriptions-health-check-card-primary">%s</div>',
			esc_html( $count_label )
		);

		if ( 0 === $count ) {
			return;
		}

		printf(
			'<div class="woocommerce-subscriptions-health-check-card-secondary"><a class="woocommerce-subscriptions-health-check-card-link" href="%1$s">%2$s</a></div>',
			esc_url( $this->past_due_actions_admin_url() ),
			esc_html__( 'Go to scheduled actions', 'woocommerce-subscriptions' )
		);
	}

	//
	// ───── Private helpers ───────────────────────────────────────────────
	//

	/**
	 * Render the candidate-table search box.
	 *
	 * WP_List_Table::search_box() reuses the same string for the input
	 * label AND the submit button — the design wants a generic "Search"
	 * button paired with a descriptive placeholder on the input itself,
	 * which the parent helper can't express. Custom markup lets us split
	 * the two and keeps the standard `.search-box` chrome that WP styles
	 * via core admin CSS.
	 *
	 * @return void
	 */
	private function render_search_box(): void {
		$placeholder = __( 'Search by subscription ID or customer email', 'woocommerce-subscriptions' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search reflected back into the input.
		$current = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		?>
		<p class="search-box">
			<label class="screen-reader-text" for="woocommerce-subscriptions-health-check-search-input"><?php echo esc_html( $placeholder ); ?></label>
			<input
				type="search"
				id="woocommerce-subscriptions-health-check-search-input"
				name="s"
				value="<?php echo esc_attr( $current ); ?>"
				placeholder="<?php echo esc_attr( $placeholder ); ?>"
			/>
			<input
				type="submit"
				id="search-submit"
				class="button"
				value="<?php esc_attr_e( 'Search', 'woocommerce-subscriptions' ); ?>"
			/>
		</p>
		<?php
	}

	/**
	 * Handle the "Run scan now" form POST.
	 *
	 * Returns the URL to redirect to. When a scan is already in flight
	 * we redirect back with a notice query arg rather than stacking a
	 * second run onto the pipeline. The button stays visually active
	 * during a scan (the "Running" label + spinner already signal
	 * busy state), so this server-side guard also covers the case
	 * where a merchant submits the form while a scan is mid-flight.
	 *
	 * @return string Redirect URL.
	 */
	private function run_scan(): string {
		if ( null !== $this->run_store->get_in_flight_scan() ) {
			return add_query_arg( 'wcs_hc_notice', 'scan_already_running', $this->tab_url() );
		}

		try {
			// Keep wcs_health_check_runs.triggered_by PII-free; the raw
			// admin id has no product value for Health Check scans.
			$run_id = $this->schedule_manager->start_scan( 'user' );
		} catch ( HealthCheckDbException $e ) {
			// Typed exception from a failed run-row INSERT.
			return add_query_arg( 'wcs_hc_notice', 'scan_start_failed', $this->tab_url() );
		} catch ( HealthCheckScanInFlightException $e ) {
			// Typed exception from the atomic-guard race-loss path.
			return add_query_arg( 'wcs_hc_notice', 'scan_already_running', $this->tab_url() );
		} catch ( Throwable $e ) {
			// Anything else came from outside our typed contract — a
			// third-party hook attached to AS's enqueue path raising
			// a generic RuntimeException, a TypeError from an
			// extension's faulty filter callback, etc. Without this
			// catch, "Run now" would either render a "Cheatin uh?"
			// page (unhandled) or — worse, when the previous bare
			// `catch (RuntimeException $e)` was here — silently
			// swallow the failure and redirect back with no
			// diagnostic signal. Log + redirect with the generic
			// scan-start-failed notice so support has a breadcrumb
			// without surfacing a fatal error to the merchant.
			wc_get_logger()->error(
				sprintf(
					'Health Check: unexpected exception while starting scan — %s: %s',
					get_class( $e ),
					$e->getMessage()
				),
				array(
					'source'    => 'wcs-health-check',
					'exception' => $e,
				)
			);
			return add_query_arg( 'wcs_hc_notice', 'scan_start_failed', $this->tab_url() );
		}

		$this->tracks->manual_scan_triggered( array( 'run_id' => $run_id ) );

		return $this->tab_url();
	}

	/**
	 * Handle the "Cancel scan" form POST.
	 *
	 * Delegates to `ScheduleManager::cancel_scan()` which atomically
	 * flips the in-flight run row from `running` to `cancelled` and
	 * unschedules any queued SCAN_BATCH actions. Returns the URL to
	 * redirect to:
	 *
	 *  - On a successful cancel (`cancel_scan()` returned a run id), the
	 *    URL carries `wcs_hc_notice=scan_cancelled` so the next render
	 *    surfaces the acknowledgement notice.
	 *  - When no scan was in flight, or the atomic guard lost the race
	 *    against a parallel `complete()` / `fail()` (`cancel_scan()`
	 *    returned null), the URL carries `wcs_hc_notice=no_scan_to_cancel`
	 *    so the merchant sees an informational notice rather than a
	 *    silent redirect.
	 *
	 * The nonce field name is `wcs_hc_cancel_scan`, consistent with the
	 * `wcs_hc_<action>` naming used by the other form POSTs on this tab.
	 *
	 * @return string Redirect URL.
	 */
	private function cancel_scan(): string {
		$run_id = $this->schedule_manager->cancel_scan();

		return add_query_arg(
			'wcs_hc_notice',
			null === $run_id ? 'no_scan_to_cancel' : 'scan_cancelled',
			$this->tab_url()
		);
	}

	private function tab_url(): string {
		return admin_url( 'admin.php?page=wc-status&tab=' . self::TAB_SLUG );
	}

	/**
	 * Convert `?wcs_hc_notice=<known_flag>` into a rendered admin notice.
	 *
	 * Only renders recognised flags — an unknown value is silently
	 * ignored so a URL-crafting attacker can't inject arbitrary copy.
	 *
	 * @return void
	 */
	private function maybe_render_query_arg_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag on a GET redirect.
		$notice = isset( $_GET['wcs_hc_notice'] ) ? sanitize_key( wp_unslash( $_GET['wcs_hc_notice'] ) ) : '';
		if ( '' === $notice ) {
			return;
		}

		switch ( $notice ) {
			case 'scan_already_running':
				?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'A scan is already running. The new scan request was ignored.', 'woocommerce-subscriptions' ); ?></p>
				</div>
				<?php
				return;
			case 'scan_start_failed':
				?>
				<div class="notice notice-error inline">
					<p><?php esc_html_e( 'The scan could not be started due to a database error. Please check the logs and try again.', 'woocommerce-subscriptions' ); ?></p>
				</div>
				<?php
				return;
			case 'scan_cancelled':
				?>
				<div class="notice notice-success inline">
					<p><?php esc_html_e( 'Scan cancelled. Subscriptions already detected stay in the list below.', 'woocommerce-subscriptions' ); ?></p>
				</div>
				<?php
				return;
			case 'no_scan_to_cancel':
				?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'No scan was running. Nothing to cancel.', 'woocommerce-subscriptions' ); ?></p>
				</div>
				<?php
				return;
		}
	}

	/**
	 * Render the circuit-breaker-tripped notice on the Health Check tab.
	 *
	 * The circuit breaker trips after 3 consecutive failed scan batches
	 * (or a 48h-stale heartbeat) and silently pauses scheduled scans by
	 * flipping the nightly-scan option to `'no'`. Merchants have no
	 * other signal the tool has stopped working - this notice makes
	 * the stopped state visible and tells them how to recover by re-
	 * enabling the schedule under WC > Settings > Subscriptions (the
	 * single source of truth for the nightly toggle since iteration 2).
	 *
	 * @return void
	 */
	private function maybe_render_tripped_notice(): void {
		if ( ! $this->circuit_breaker->is_tripped() ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=subscriptions#woocommerce_subscriptions_enable_health_check_nightly_scan' );
		$notice_copy  = sprintf(
			/* translators: %s: link text "WooCommerce > Settings > Subscriptions" rendered as an anchor pointing at the Health Check section of the Subscriptions settings tab. */
			__( 'The last health check scan encountered an issue. Re-enable the scan schedule in %s to try again.', 'woocommerce-subscriptions' ),
			sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $settings_url ),
				esc_html__( 'WooCommerce > Settings > Subscriptions', 'woocommerce-subscriptions' )
			)
		);
		?>
		<div class="notice notice-error inline woocommerce-subscriptions-health-check-tripped-notice">
			<p>
				<?php
				echo wp_kses(
					$notice_copy,
					array(
						'a' => array(
							'href' => array(),
						),
					)
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Emit the passive nightly-scan status line surfaced under the
	 * LAST SCAN card. Reads its on/off state directly from the
	 * `CircuitBreaker::OPTION_SCHEDULE_ENABLED` option - the same
	 * option WC > Settings > Subscriptions writes. The line is
	 * read-only on this tab; the Manage anchor links merchants to
	 * the Settings section that owns the toggle.
	 *
	 * Markup:
	 *   <span class="woocommerce-subscriptions-health-check-nightly-status">
	 *     <span class="...__dot is-on|is-off" aria-hidden="true"></span>
	 *     <span class="...__label">Nightly scans on</span>
	 *     <span class="...__sep" aria-hidden="true">·</span>            (only when on)
	 *     <span class="...__next-in">next in 3 hours</span>             (only when on)
	 *     <span class="...__sep" aria-hidden="true">·</span>
	 *     <a class="...__manage" href="<settings url>">Manage</a>
	 *   </span>
	 *
	 * Each bullet is its own `__sep` span (muted, `aria-hidden`) rather than text baked into the
	 * adjacent copy, so both separators share one colour token and translators never handle the glyph.
	 *
	 * @return void
	 */
	private function render_nightly_status_line(): void {
		$enabled     = $this->circuit_breaker->is_schedule_enabled();
		$next_in     = $enabled ? $this->next_daily_scan_relative_label() : '';
		$state_class = $enabled
			? 'woocommerce-subscriptions-health-check-nightly-status__dot is-on'
			: 'woocommerce-subscriptions-health-check-nightly-status__dot is-off';
		$state_label = $enabled
			? __( 'Nightly scans on', 'woocommerce-subscriptions' )
			: __( 'Nightly scans off', 'woocommerce-subscriptions' );

		$manage_url = admin_url( 'admin.php?page=wc-settings&tab=subscriptions#woocommerce_subscriptions_enable_health_check_nightly_scan' );
		?>
		<span class="woocommerce-subscriptions-health-check-nightly-status">
			<span class="<?php echo esc_attr( $state_class ); ?>" aria-hidden="true"></span>
			<span class="woocommerce-subscriptions-health-check-nightly-status__label"><?php echo esc_html( $state_label ); ?></span>
			<?php if ( '' !== $next_in ) : ?>
				<span class="woocommerce-subscriptions-health-check-nightly-status__sep" aria-hidden="true">·</span>
				<span class="woocommerce-subscriptions-health-check-nightly-status__next-in">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: human-readable time diff like "3 hours". */
							__( 'next in %s', 'woocommerce-subscriptions' ),
							$next_in
						)
					);
					?>
				</span>
			<?php endif; ?>
			<span class="woocommerce-subscriptions-health-check-nightly-status__sep" aria-hidden="true">·</span>
			<a class="woocommerce-subscriptions-health-check-nightly-status__manage" href="<?php echo esc_url( $manage_url ); ?>"><?php esc_html_e( 'Manage', 'woocommerce-subscriptions' ); ?></a>
		</span>
		<?php
	}

	/**
	 * Return the human-readable "X hours" piece for the next
	 * scheduled daily scan, or an empty string when no scan is
	 * queued. Shared by `render_last_scan_value()` (which prefixes
	 * "Next scheduled in …") and `render_nightly_status_line()`
	 * (which prefixes "· next in …").
	 *
	 * @return string
	 */
	private function next_daily_scan_relative_label(): string {
		$next_run_ts = $this->next_daily_scan_timestamp();
		if ( $next_run_ts <= 0 ) {
			return '';
		}

		$now = time();
		if ( $next_run_ts <= $now ) {
			// Action is queued but the AS runner has not picked it up
			// yet. Surface this consistently with the LAST SCAN
			// secondary line so the two read in sync.
			return (string) __( 'now', 'woocommerce-subscriptions' );
		}

		return function_exists( 'human_time_diff' ) ? human_time_diff( $now, $next_run_ts ) : '';
	}

	/**
	 * Convert a MySQL-format UTC datetime string into a
	 * `human_time_diff()`-friendly relative label. Parsing explicitly
	 * with a `UTC` suffix avoids `strtotime()` guessing the local
	 * timezone.
	 *
	 * @param string $mysql_utc
	 *
	 * @return string
	 */
	private function human_time_since_mysql_utc( string $mysql_utc ): string {
		if ( '' === $mysql_utc ) {
			return (string) __( 'recently', 'woocommerce-subscriptions' );
		}
		$ts = strtotime( $mysql_utc . ' UTC' );
		if ( false === $ts ) {
			return (string) __( 'recently', 'woocommerce-subscriptions' );
		}
		return function_exists( 'human_time_diff' ) ? human_time_diff( $ts, time() ) : gmdate( 'Y-m-d', $ts );
	}

	/**
	 * Unix timestamp of the next scheduled daily-scan action, or 0 when
	 * none is queued. Wraps the Action Scheduler lookup behind a
	 * protected helper so tests can pin a deterministic value without
	 * a running scheduler — mirrors the ScheduleManager's probe-
	 * override pattern.
	 *
	 * @return int
	 */
	protected function next_daily_scan_timestamp(): int {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return 0;
		}
		$ts = as_next_scheduled_action( 'wcs_health_check_daily_scan' );
		return is_int( $ts ) && $ts > 0 ? $ts : 0;
	}

	/**
	 * Count of past-due pending actions in our group ({@see WCS_Action_Scheduler::ACTION_GROUP}).
	 *
	 * "Past-due" follows AS's own definition: pending status, run-time more than
	 * `action_scheduler_pastdue_actions_seconds` seconds in the past (default 1 day). Applying AS's filter
	 * keeps this card's threshold consistent with whatever the site operator has tuned globally for AS.
	 *
	 * Wraps the lookup behind a protected helper so tests can pin a deterministic value without scheduling
	 * fixtures — mirrors the {@see next_daily_scan_timestamp()} probe-override pattern.
	 *
	 * @return int
	 */
	protected function get_past_due_action_count(): int {
		if ( ! class_exists( '\ActionScheduler_Store', false ) ) {
			return 0;
		}

		$threshold_seconds = (int) apply_filters( 'action_scheduler_pastdue_actions_seconds', DAY_IN_SECONDS );

		$store = \ActionScheduler_Store::instance();
		$count = $store->query_actions(
			array(
				'group'    => WCS_Action_Scheduler::ACTION_GROUP,
				'date'     => as_get_datetime_object( time() - $threshold_seconds ),
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'count'
		);

		return max( 0, (int) $count );
	}

	/**
	 * URL to the WC > Status > Scheduled Actions tab, filtered to its past-due view. Site-wide (not
	 * group-scoped) — AS does not accept a group filter as a query arg, so we hand the merchant the same
	 * listing AS would surface on its own admin notice; our group's pending entries appear inline with
	 * any others.
	 *
	 * Targets the in-status tab (`page=wc-status&tab=action-scheduler`) rather than AS's standalone
	 * Tools > Scheduled Actions menu so the merchant stays inside the Status tabset and can navigate
	 * back to the Subscriptions tab via the existing nav.
	 *
	 * @return string
	 */
	private function past_due_actions_admin_url(): string {
		return add_query_arg(
			array(
				'page'   => 'wc-status',
				'tab'    => 'action-scheduler',
				'status' => 'past-due',
				'order'  => 'asc',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render the plugin-version secondary line on the version card.
	 *
	 * Up-to-date reads "Up to date ✓" in the success green token used elsewhere in WP admin
	 * (`#00a32a`, mirroring the nightly-status dot); out of date reads as an amber warning line
	 * ("Newer version available.") with an "Update now" link to the plugin update page. That link
	 * uses the shared `-card-link` blue treatment so it matches the other status-strip links rather
	 * than inheriting the muted secondary-link grey. The up-to-date treatment is the design's
	 * positive-status signal — the `-success` modifier paints both the copy and the trailing checkmark.
	 *
	 * @return void
	 */
	private function render_version_marker(): void {
		if ( ! $this->has_wcs_update_available() ) {
			printf(
				'<div class="woocommerce-subscriptions-health-check-card-secondary woocommerce-subscriptions-health-check-card-secondary-success">%s</div>',
				esc_html__( 'Up to date ✓', 'woocommerce-subscriptions' )
			);
			return;
		}

		$plugins_url = admin_url( 'plugins.php' );
		printf(
			'<div class="woocommerce-subscriptions-health-check-card-secondary woocommerce-subscriptions-health-check-card-secondary-warn">%1$s <a class="woocommerce-subscriptions-health-check-card-link" href="%2$s">%3$s</a></div>',
			esc_html__( 'Newer version available.', 'woocommerce-subscriptions' ),
			esc_url( $plugins_url ),
			esc_html__( 'Update now', 'woocommerce-subscriptions' )
		);
	}

	/**
	 * Whether WordPress reports a newer version of WooCommerce
	 * Subscriptions is available in the `update_plugins` transient.
	 *
	 * Reads the transient directly rather than hitting the WP.org
	 * update API — the transient is refreshed by the standard plugin-
	 * update cron tick, so we always see the latest known state
	 * without making outbound requests on every render. Protected so
	 * tests can pin the value without seeding the transient.
	 *
	 * @return bool
	 */
	protected function has_wcs_update_available(): bool {
		if ( ! class_exists( '\\WC_Subscriptions' ) || ! function_exists( 'plugin_basename' ) ) {
			return false;
		}

		$basename = plugin_basename( \WC_Subscriptions::$plugin_file );
		$updates  = get_site_transient( 'update_plugins' );

		return is_object( $updates )
			&& isset( $updates->response )
			&& is_array( $updates->response )
			&& isset( $updates->response[ $basename ] );
	}

	/**
	 * Overridable redirect wrapper so tests can exercise the action
	 * handlers without terminating the test process via `exit`.
	 *
	 * @param string $url Destination URL.
	 *
	 * @return void
	 */
	protected function redirect_and_exit( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
