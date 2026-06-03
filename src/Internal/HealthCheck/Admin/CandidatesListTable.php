<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\HealthCheck\Admin;

use Automattic\WooCommerce_Subscriptions\Internal\HealthCheck\CandidateStore;
use Automattic\WooCommerce_Subscriptions\Internal\HealthCheck\Detector;
use Automattic\WooCommerce_Subscriptions\Internal\HealthCheck\RunStore;
use WC_Payment_Token_CC;
use WCS_Payment_Tokens;
use WC_Subscription;
use WP_List_Table;

/**
 * Read-only WP_List_Table for the Health Check candidate list.
 *
 * Rendered inside WooCommerce > Status > Subscriptions. Seven
 * columns, column order fixed by the MVP-update spec:
 *
 *   1. Subscription            — "#<id>" + subscribed product title, HPOS-
 *                                aware deep link.
 *   2. Customer                — name linked to user-edit.php (plain
 *                                text for guest subscriptions).
 *   3. Status                  — live WC status pill; sortable PHP-side.
 *   4. Billing mode            — Manual ⚠ / Automatic badge; sortable PHP-
 *                                side.
 *   5. Payment method          — "{Gateway} ···· {last4}" over "✓/⚠ token
 *                                on file".
 *   6. Renewal order status    — pill for the most recent renewal order's
 *                                status (Pending / Failed / etc.); sortable
 *                                PHP-side on the stored detail field.
 *   7. Last successful payment — date over wc_price() total from the prior
 *                                auto-renewal order.
 *
 * Every flagged sub is treated uniformly — no confidence tier is
 * computed or displayed; the Renewal preference column carries the
 * per-row context merchants need to decide what to do.
 *
 * Sort rules:
 *   - Status, Billing mode — derived from live subscription lookups
 *     (not stored on candidate rows), so sort the current page in PHP.
 *   - Renewal order status — stored in details_json (latest_renewal_status),
 *     so sort the current page in PHP without a live order load.
 *
 * Search: PHP-side match on subscription id and customer email.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class CandidatesListTable extends WP_List_Table {

	/**
	 * Page size for the list. Matches WooCommerce's default Subscriptions
	 * list density and gives the WP-standard pagination something to do
	 * when a store accumulates more than ~20 candidates.
	 */
	private const PER_PAGE = 20;

	/**
	 * @var RunStore
	 */
	private $run_store;

	/**
	 * @var CandidateStore
	 */
	private $candidate_store;

	/**
	 * Per-request cache of `count_all_subscriptions()` results, keyed
	 * on status filter. Populated lazily by
	 * `cached_count_all_subscriptions()`.
	 *
	 * @var array<string, int>
	 */
	private $count_cache = array();

	/**
	 * Per-request cache of loaded WC_Subscription objects, keyed by ID.
	 * Populated lazily by `load_subscription()` to avoid redundant
	 * `wcs_get_subscription()` calls across column renderers.
	 *
	 * @var array<int, WC_Subscription|null>
	 */
	private $subscription_cache = array();

	/**
	 * Detector used by render_row_for() to live-classify a subscription
	 * for the transformed-row update path. Lazily instantiated.
	 *
	 * @var Detector|null
	 */
	private $detector;

	public function __construct( ?RunStore $run_store = null, ?CandidateStore $candidate_store = null, ?Detector $detector = null ) {
		$this->run_store       = $run_store ?? new RunStore();
		$this->candidate_store = $candidate_store ?? new CandidateStore();
		$this->detector        = $detector;

		parent::__construct(
			array(
				'singular' => 'wcs-health-check-candidate',
				'plural'   => 'wcs-health-check-candidates',
				'ajax'     => false,
				'screen'   => 'wcs_health_check',
			)
		);
	}

	/**
	 * Render a single candidate row as an HTML `<tr>` string, suitable
	 * for swapping into the candidates table from the Resolve modal's
	 * AJAX response after a transformed-case action (the subscription
	 * is still flagged, just under a different signal — see T7 / T10
	 * in the WOOSUBS-1674 spec).
	 *
	 * Self-contained — live-classifies the subscription via Detector so
	 * the rendered row reflects the post-action state, even when the
	 * candidate_store doesn't yet have a row for the new signal. No
	 * state mutation.
	 *
	 * Threads `$view` through the table's view-dependent rendering by
	 * temporarily overriding `$_REQUEST['view']` for the duration of
	 * the `single_row()` call; restores the prior value in a `finally`
	 * so the override doesn't leak.
	 *
	 * @param WC_Subscription $subscription Subscription to render.
	 * @param string          $view         One of 'all', 'supports_auto_renewal',
	 *                                       'missing_renewals'.
	 *                                       Determines which signal's data
	 *                                       drives the row and which row
	 *                                       actions appear (see T8 gating).
	 *
	 * @return string Full `<tr>...</tr>` HTML for the subscription, or an
	 *                empty string when the subscription doesn't classify
	 *                under any signal at the moment of the call.
	 */
	public function render_row_for( WC_Subscription $subscription, string $view ): string {
		$signal_type = self::signal_type_for_view( $view );

		$detector        = $this->detector ?? new Detector();
		$classifications = $detector->classify_all_signals( $subscription );

		// Pick the signal whose data should drive the row. For per-signal
		// views, prefer that signal; for 'all', fall back to the first
		// non-null signal — the All view doesn't depend on signal-specific
		// columns, but `signals_from()` / `details_from()` still need a
		// `signal_summary` to decode.
		$signal_data = null;
		if ( '' !== $signal_type && isset( $classifications[ $signal_type ] ) && is_array( $classifications[ $signal_type ] ) ) {
			$signal_data = $classifications[ $signal_type ];
		} else {
			foreach ( $classifications as $candidate_data ) {
				if ( is_array( $candidate_data ) ) {
					$signal_data = $candidate_data;
					break;
				}
			}
		}

		if ( null === $signal_data ) {
			return '';
		}

		$summary = wp_json_encode( $signal_data );
		$item    = array(
			'subscription_id' => (int) $subscription->get_id(),
			'signal_summary'  => is_string( $summary ) ? $summary : '{}',
		);

		// Drop any cached subscription instance — the row may be re-rendered
		// after a state-mutating action, so column renderers must re-load.
		unset( $this->subscription_cache[ (int) $subscription->get_id() ] );

		// Thread the view through column renderers that read $_REQUEST['view'].
		// Restore in finally so the override can't leak even on a renderer fatal.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$had_prior_view   = isset( $_REQUEST['view'] );
		$prior_view       = $had_prior_view ? $_REQUEST['view'] : null;
		$_REQUEST['view'] = $view;
		// phpcs:enable

		// Track buffer ownership so an exception inside single_row() can't
		// leave a dangling output buffer on the PHP-FPM worker. Mirrors the
		// fix in commit 5dfa6133a for class-wc-subscriptions-change-payment-gateway.
		// Closing the buffer in `finally` (rather than the normal path) means
		// we always close exactly the buffer we opened — we never reach for
		// an outer buffer we don't own, even when single_row() succeeds with
		// empty output.
		$row_html             = '';
		$output_buffer_opened = ob_start();
		try {
			$this->single_row( $item );
		} finally {
			if ( $output_buffer_opened ) {
				$row_html = (string) ob_get_clean();
			}
			if ( $had_prior_view ) {
				$_REQUEST['view'] = $prior_view;
			} else {
				unset( $_REQUEST['view'] );
			}
		}
		return $row_html;
	}

	/**
	 * View slug -> CandidateStore signal type mapping shared between
	 * the table view, the row-render helper, and AjaxController's
	 * per-view re-classify wiring. Empty string for the All view,
	 * which has no signal-specific column data.
	 *
	 * @param string $view 'all', 'supports_auto_renewal' or 'missing_renewals'.
	 *
	 * @return string SIGNAL_TYPE_* constant or empty string.
	 */
	public static function signal_type_for_view( string $view ): string {
		switch ( $view ) {
			case 'missing_renewals':
				return CandidateStore::SIGNAL_TYPE_MISSING_RENEWAL;
			case 'supports_auto_renewal':
				return CandidateStore::SIGNAL_TYPE_SUPPORTS_AUTO_RENEWAL;
			default:
				return '';
		}
	}

	/**
	 * Column definitions, in display order.
	 *
	 * Universal across every view — swapping columns as merchants
	 * change tabs is disorienting, so the same column set renders
	 * everywhere and per-user hiding goes through the Screen Options
	 * drawer.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'subscription_id'      => __( 'Subscription', 'woocommerce-subscriptions' ),
			'created'              => __( 'Created', 'woocommerce-subscriptions' ),
			'customer'             => __( 'Customer', 'woocommerce-subscriptions' ),
			'cycle'                => __( 'Cycle', 'woocommerce-subscriptions' ),
			'status'               => __( 'Status', 'woocommerce-subscriptions' ),
			'billing_mode'         => __( 'Billing mode', 'woocommerce-subscriptions' ),
			'renewal_preference'   => __( 'Renewal preference', 'woocommerce-subscriptions' ),
			'payment_method'       => __( 'Payment method', 'woocommerce-subscriptions' ),
			'next_payment'         => __( 'Next payment date', 'woocommerce-subscriptions' ),
			'renewal_order_status' => __( 'Renewal order status', 'woocommerce-subscriptions' ),
			'last_payment'         => __( 'Last successful payment', 'woocommerce-subscriptions' ),
		);
	}

	/**
	 * Sortable columns + default-descending flag.
	 *
	 * Cycle is deliberately omitted — "Every 2 months" has no natural
	 * ordering vs "Every 3 weeks" (the period + interval combination
	 * isn't a linear quantity), so any sort key would encode a
	 * product decision the merchant didn't ask us to make.
	 *
	 * @return array<string, array{0:string,1:bool}>
	 */
	protected function get_sortable_columns(): array {
		$columns = array(
			'subscription_id'      => array( 'subscription_id', false ),
			'created'              => array( 'created', false ),
			'status'               => array( 'status', false ),
			'billing_mode'         => array( 'billing_mode', false ),
			'renewal_preference'   => array( 'renewal_preference', false ),
			'next_payment'         => array( 'next_payment', false ),
			'renewal_order_status' => array( 'renewal_order_status', false ),
			'last_payment'         => array( 'last_payment', false ),
		);

		// The All view paginates at the SQL level — only
		// subscription_id and created map to real SQL orderby
		// values. All other columns require PHP-side lookups
		// that only work on the bounded candidate-backed views.
		if ( 'all' === $this->current_view() ) {
			unset(
				$columns['status'],
				$columns['billing_mode'],
				$columns['renewal_preference'],
				$columns['next_payment'],
				$columns['renewal_order_status'],
				$columns['last_payment']
			);
		}

		return $columns;
	}

	/**
	 * Filter tabs rendered above the table:
	 *   - All                              — every subscription in the store.
	 *   - Supports auto-renewal    — Supports-auto-renewal signal.
	 *   - Missing renewals                  — missing / stale next-payment
	 *                                         schedule.
	 *
	 * "Supports auto-renewal" is the default view, so its tab
	 * carries the bare tab URL; the All and Missing tabs get explicit
	 * `?view=` params.
	 *
	 * View slugs (`all`, `supports_auto_renewal`, `missing_renewals`)
	 * map directly to the signal types so URLs shared in support
	 * tickets stay self-describing.
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$run_id         = $this->run_store->get_latest_scan_run_id();
		$eligible_total = 0 === $run_id ? 0 : $this->candidate_store->count_by_run_and_signal( $run_id, CandidateStore::SIGNAL_TYPE_SUPPORTS_AUTO_RENEWAL );
		$missing_total  = 0 === $run_id ? 0 : $this->candidate_store->count_by_run_and_signal( $run_id, CandidateStore::SIGNAL_TYPE_MISSING_RENEWAL );
		$all_total      = $this->cached_count_all_subscriptions();

		$current = $this->current_view();
		$base    = remove_query_arg( array( 'view', 'paged', 'orderby', 'order', 's' ) );

		return array(
			'all'                   => sprintf(
				'<a href="%1$s" class="%2$s">%3$s <span class="count">(%4$d)</span></a>',
				esc_url( add_query_arg( 'view', 'all', $base ) ),
				'all' === $current ? 'current' : '',
				esc_html__( 'All', 'woocommerce-subscriptions' ),
				$all_total
			),
			'supports_auto_renewal' => sprintf(
				'<a href="%1$s" class="%2$s">%3$s <span class="count">(%4$d)</span></a>',
				esc_url( $base ),
				'supports_auto_renewal' === $current ? 'current' : '',
				esc_html__( 'Supports auto-renewal', 'woocommerce-subscriptions' ),
				$eligible_total
			),
			'missing_renewals'      => sprintf(
				'<a href="%1$s" class="%2$s">%3$s <span class="count">(%4$d)</span></a>',
				esc_url( add_query_arg( 'view', 'missing_renewals', $base ) ),
				'missing_renewals' === $current ? 'current' : '',
				esc_html__( 'Missing renewals', 'woocommerce-subscriptions' ),
				$missing_total
			),
		);
	}

	/**
	 * Total subscription count across the whole store. Direct SQL
	 * count rather than `wcs_get_subscriptions(['subscriptions_per_page' => -1])`
	 * because the latter would hydrate every WC_Subscription object
	 * just to count them — unbounded memory on big stores.
	 *
	 * Matches the status filter `wcs_get_subscriptions( [ 'subscription_status'
	 * => [ 'any' ] ] )` actually applies — `trash` and `auto-draft` are excluded
	 * by the paginated fetch, so including them here would make the `All (N)`
	 * tab count drift higher than the rows a merchant can browse to.
	 *
	 * HPOS-aware: queries `wc_orders` when HPOS is enabled, falls
	 * back to `posts` otherwise.
	 *
	 * @return int
	 */
	public static function count_all_subscriptions( string $status_filter = '' ): int {
		global $wpdb;

		// When a status filter is active, narrow the count so pagination
		// matches the `wcs_get_subscriptions` result set. Values are
		// allowlisted by `current_filters()` before reaching here.
		$status_where_hpos    = "status NOT IN ('trash', 'auto-draft')";
		$status_where_classic = "post_status NOT IN ('trash', 'auto-draft')";

		if ( '' !== $status_filter ) {
			$hpos_status          = 'wc-' === substr( $status_filter, 0, 3 ) ? $status_filter : 'wc-' . $status_filter;
			$status_where_hpos    = $wpdb->prepare( 'status = %s', $hpos_status );
			$status_where_classic = $wpdb->prepare( 'post_status = %s', $hpos_status );
		}

		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only count, status clause is either a static allowlist or a prepared fragment.
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_subscription' AND {$status_where_hpos}" );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only count, status clause is either a static allowlist or a prepared fragment.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_subscription' AND {$status_where_classic}" );
	}

	/**
	 * Instance-cached wrapper for `count_all_subscriptions()` used by
	 * the two internal callers (`get_views()` for the All-tab badge and
	 * `prepare_items_all_view()` for pagination totals) that both run
	 * within the same request on the same list-table instance. Collapses
	 * those two otherwise-identical COUNT(*) queries per All-view render
	 * into one.
	 *
	 * The public static `count_all_subscriptions()` stays uncached for
	 * callers from outside the class (StatusTab scope card) that call
	 * it once per render.
	 *
	 * @param string $status_filter Optional status filter; defaults to
	 *                              "all statuses" (trash/auto-draft
	 *                              excluded).
	 *
	 * @return int
	 */
	private function cached_count_all_subscriptions( string $status_filter = '' ): int {
		if ( ! array_key_exists( $status_filter, $this->count_cache ) ) {
			$this->count_cache[ $status_filter ] = self::count_all_subscriptions( $status_filter );
		}
		return $this->count_cache[ $status_filter ];
	}

	/**
	 * Currently-selected filter tab. Defaults to 'supports_auto_renewal' —
	 * the design treats "things that need attention" as the merchant's
	 * primary entry point. Unknown values fall back to the default
	 * view rather than surfacing as an empty page.
	 */
	private function current_view(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter switch.
		if ( ! isset( $_REQUEST['view'] ) ) {
			return 'supports_auto_renewal';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = sanitize_key( wp_unslash( $_REQUEST['view'] ) );
		return in_array( $view, array( 'all', 'supports_auto_renewal', 'missing_renewals' ), true ) ? $view : 'supports_auto_renewal';
	}

	/**
	 * Candidate-store signal_type corresponding to the currently-selected
	 * view. Only meaningful for candidate-backed views ('supports_auto_renewal'
	 * and 'missing_renewals'); the All view reads from `wcs_get_subscriptions()`
	 * and ignores signal type.
	 *
	 * @return string Empty string for the All view; a SIGNAL_TYPE_*
	 *                constant otherwise.
	 */
	private function signal_type_for_current_view(): string {
		return self::signal_type_for_view( $this->current_view() );
	}

	/**
	 * Populate `$this->items` from the latest completed scan's
	 * candidates, with search + sort applied.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->_column_headers       = array( $this->get_columns(), $this->get_hidden_columns_for_current_view(), $this->get_sortable_columns() );
		$this->signal_view_truncated = false;

		if ( 'all' === $this->current_view() ) {
			$this->prepare_items_all_view();
		} else {
			$this->prepare_items_signal_view( $this->signal_type_for_current_view() );
		}
	}

	/**
	 * Hidden-column list for the current view. Reads the user's saved
	 * preferences from the WP Screen Options drawer (backed by user
	 * meta) and falls back to the empty array so every column renders
	 * by default — matches the Figma literal.
	 *
	 * Wrapped in a helper rather than inlined in `prepare_items()` so
	 * the fallback is testable and the dependency on the live screen
	 * is isolated to one place.
	 *
	 * @return array<int, string>
	 */
	private function get_hidden_columns_for_current_view(): array {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return array();
		}

		$screen = get_current_screen();
		if ( null === $screen ) {
			return array();
		}

		$hidden = get_hidden_columns( $screen );
		return is_array( $hidden ) ? array_values( array_filter( array_map( 'strval', $hidden ) ) ) : array();
	}

	/**
	 * Hard cap on the number of candidate rows fetched into PHP when
	 * search / filter / PHP-sortable column fallback kicks in on a
	 * candidate-backed view (Supports auto-renewal / Missing renewals). Candidate
	 * counts are bounded in practice (~100s), but a pathologically
	 * broken store could produce thousands — without this cap,
	 * `usort` + per-row `load_subscription()` would run O(N log N)
	 * hydrations and tip over the page.
	 *
	 * When the cap is hit the merchant sees a "showing first N" notice
	 * (see `prepare_items_signal_view()`) that invites them to narrow
	 * the search.
	 *
	 * @var int
	 */
	public const SIGNAL_PHP_FALLBACK_CAP = 500;

	/**
	 * @deprecated Use `SIGNAL_PHP_FALLBACK_CAP`. Alias retained for
	 *             callers outside this class that may have inlined the
	 *             older constant. Removes on next major.
	 */
	public const ELIGIBLE_PHP_FALLBACK_CAP = self::SIGNAL_PHP_FALLBACK_CAP;

	/**
	 * True when the most recent `prepare_items_signal_view()` call
	 * fetched exactly the cap and at least one more candidate existed
	 * beyond it. Read by `extra_tablenav()` to render the truncation
	 * notice. Reset to false on every `prepare_items()` call so a
	 * later page load (search cleared, cap no longer hit) doesn't
	 * carry the notice forward.
	 *
	 * @var bool
	 */
	private $signal_view_truncated = false;

	/**
	 * Signal-backed view: PHP-sliced page over the latest scan's
	 * candidate set, filtered to the given signal type. Loads every
	 * candidate row of that signal for the run, applies search + sort,
	 * then slices for the visible page. Acceptable here because
	 * per-signal candidate counts are bounded (~100s, not 100k+) —
	 * and for pathological stores, capped at
	 * `SIGNAL_PHP_FALLBACK_CAP` with an in-table notice pointing the
	 * merchant at search/filter to narrow the set.
	 *
	 * @param string $signal_type One of the `CandidateStore::SIGNAL_TYPE_*`
	 *                            constants.
	 */
	private function prepare_items_signal_view( string $signal_type ): void {
		$run_id = $this->run_store->get_latest_scan_run_id();
		if ( 0 === $run_id ) {
			$this->items = array();
			$this->set_pagination_args(
				array(
					'total_items' => 0,
					'per_page'    => self::PER_PAGE,
				)
			);
			return;
		}

		$search  = $this->current_search_term();
		$orderby = $this->current_orderby();
		$order   = $this->current_order();
		$filters = $this->current_filters();

		$per_page     = self::PER_PAGE;
		$current_page = max( 1, (int) $this->get_pagenum() );
		$offset       = ( $current_page - 1 ) * $per_page;

		$has_filters = $this->has_active_filters( $filters );

		$signal_view_requires_php_sort = in_array(
			$orderby,
			array( 'status', 'billing_mode', 'renewal_preference', 'next_payment', 'renewal_order_status', 'last_payment', 'created' ),
			true
		);
		if ( '' === $search && ! $has_filters && ! $signal_view_requires_php_sort ) {
			$store_orderby = in_array( $orderby, array( 'id', 'subscription_id', 'created_at' ), true ) ? $orderby : null;
			$total_items   = $this->candidate_store->count_by_run_and_signal( $run_id, $signal_type );

			$this->items = $this->candidate_store->list_by_run_and_signal( $run_id, $signal_type, $store_orderby, $order, $per_page, $offset );
			$this->set_pagination_args(
				array(
					'total_items' => $total_items,
					'per_page'    => $per_page,
					'total_pages' => (int) ceil( $total_items / $per_page ),
				)
			);
			return;
		}

		// Bounded fetch. Request cap+1 so we can detect "there are
		// more rows than we're showing" without running a second
		// COUNT query. If the fetch returns fewer than cap+1 rows
		// the DB has no more to give and the notice stays off.
		$items = $this->candidate_store->list_by_run_and_signal(
			$run_id,
			$signal_type,
			null,
			'desc',
			self::SIGNAL_PHP_FALLBACK_CAP + 1
		);

		if ( count( $items ) > self::SIGNAL_PHP_FALLBACK_CAP ) {
			$items                       = array_slice( $items, 0, self::SIGNAL_PHP_FALLBACK_CAP );
			$this->signal_view_truncated = true;
		}

		if ( '' !== $search ) {
			$items = array_values(
				array_filter(
					$items,
					fn( $row ) => $this->row_matches_search( (array) $row, $search )
				)
			);
		}

		if ( $has_filters ) {
			$items = array_values(
				array_filter(
					$items,
					fn( $row ) => $this->row_matches_filters( (array) $row, $filters )
				)
			);
		}

		if ( $signal_view_requires_php_sort ) {
			$items = $this->php_sort_items( $items, $orderby, $order );
		}

		$total_items = count( $items );

		$this->items = array_slice( $items, $offset, $per_page );
		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * All view: SQL-paginated query against every subscription in
	 * the store via `wcs_get_subscriptions`. Sort + pagination push
	 * down to SQL so memory stays bounded regardless of store size.
	 *
	 * Per-row data not stored on the candidate row (latest renewal
	 * status, prior auto-renewal id used for Last successful payment)
	 * is computed live in the renderers — see
	 * `render_renewal_order_status` and `render_last_payment` for the
	 * fallback path.
	 *
	 * Search support is intentionally narrow on this view: a numeric
	 * search term is treated as a subscription id lookup (cheap +
	 * correct); free-text email search is not supported here because
	 * `wcs_get_subscriptions` has no native text index. Merchants who
	 * need email search should use the Supports auto-renewal view (which already
	 * supports it via PHP filtering over a small dataset) or the
	 * standard WC subscriptions list page.
	 */
	private function prepare_items_all_view(): void {
		$current_page = max( 1, (int) $this->get_pagenum() );
		$per_page     = self::PER_PAGE;

		$search = $this->current_search_term();
		if ( '' !== $search && ctype_digit( $search ) ) {
			// Numeric search → direct sub-id lookup, single row when found.
			$sub = wcs_get_subscription( (int) $search );
			if ( $sub instanceof WC_Subscription ) {
				$this->items = array( array( 'subscription_id' => $sub->get_id() ) );
				$this->set_pagination_args(
					array(
						'total_items' => 1,
						'per_page'    => $per_page,
						'total_pages' => 1,
					)
				);
				return;
			}
			$this->items = array();
			$this->set_pagination_args(
				array(
					'total_items' => 0,
					'per_page'    => $per_page,
					'total_pages' => 0,
				)
			);
			return;
		}

		$filters       = $this->current_filters();
		$status_filter = $filters['status'];
		$status_arg    = '' === $status_filter ? array( 'any' ) : array( $status_filter );

		// `wcs_get_subscriptions` accepts `paged` but doesn't translate it to
		// an offset internally — only the explicit `offset` arg drives row
		// skipping. Pass the computed offset so page > 1 actually paginates.
		$query_args = array(
			'subscriptions_per_page' => $per_page,
			'offset'                 => ( $current_page - 1 ) * $per_page,
			'orderby'                => $this->all_view_orderby(),
			'order'                  => 'desc' === $this->current_order() ? 'DESC' : 'ASC',
			'subscription_status'    => $status_arg,
		);

		$subs = wcs_get_subscriptions( $query_args );

		$items = array();
		foreach ( $subs as $sub ) {
			if ( ! $sub instanceof WC_Subscription ) {
				continue;
			}
			$items[] = array( 'subscription_id' => (int) $sub->get_id() );
		}

		$total_items = $this->cached_count_all_subscriptions( $status_filter );

		$this->items = $items;
		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Map the user-selected orderby column to a value
	 * `wcs_get_subscriptions` understands. Columns the upstream
	 * function can't sort on directly fall back to `start_date` —
	 * the same default the function uses when no orderby is provided.
	 *
	 * Next-payment sort on the All view: `wcs_get_subscriptions`
	 * doesn't expose a dedicated orderby for `_schedule_next_payment`,
	 * and wiring a `meta_value` sort would mean bypassing the function
	 * and hand-building the HPOS-vs-CPT query. Out of scope for v1;
	 * falls back to ID order on the All view. The Supports auto-renewal
	 * + Missing renewals views keep real next-payment sort via `php_sort_items()` because
	 * their row count is bounded.
	 *
	 * @return string
	 */
	private function all_view_orderby(): string {
		$orderby = $this->current_orderby();
		switch ( $orderby ) {
			case 'subscription_id':
				return 'ID';
			case 'created':
				// Subscription start_date IS the creation timestamp
				// — `wcs_get_subscriptions` uses `start_date` as its
				// canonical orderby alias for the underlying post
				// date_created field.
				return 'start_date';
			case 'last_payment':
				// `start_date` is a reasonable proxy when the user
				// hasn't asked for anything more specific — there's
				// no SQL-level "last successful renewal date" index.
				return 'start_date';
			case 'next_payment':
				// See method docblock — falls back to ID order on
				// the All view.
				return 'ID';
			default:
				return 'ID';
		}
	}

	/**
	 * Render a single cell. Public so tests can exercise the column
	 * formatters directly.
	 *
	 * @param array<string, mixed> $item        Candidate row.
	 * @param string               $column_name Column slug.
	 *
	 * @return string
	 */
	public function render_column( array $item, string $column_name ): string {
		$details = $this->details_from( $item );

		switch ( $column_name ) {
			case 'subscription_id':
				return $this->render_subscription_link( (int) ( $item['subscription_id'] ?? 0 ) );
			case 'created':
				return $this->render_created( (int) ( $item['subscription_id'] ?? 0 ) );
			case 'customer':
				return $this->render_customer( (int) ( $item['subscription_id'] ?? 0 ) );
			case 'status':
				return $this->render_status( (int) ( $item['subscription_id'] ?? 0 ) );
			case 'billing_mode':
				return $this->render_billing_mode( (int) ( $item['subscription_id'] ?? 0 ), $this->signals_from( $item ) );
			case 'cycle':
				return $this->render_cycle( $details, (int) ( $item['subscription_id'] ?? 0 ) );
			case 'renewal_preference':
				return $this->render_renewal_preference( $details );
			case 'payment_method':
				return $this->render_payment_method( (int) ( $item['subscription_id'] ?? 0 ) );
			case 'next_payment':
				return $this->render_next_payment( $details, (int) ( $item['subscription_id'] ?? 0 ) );
			case 'renewal_order_status':
				return $this->render_renewal_order_status( $details, (int) ( $item['subscription_id'] ?? 0 ) );
			case 'last_payment':
				return $this->render_last_payment( $details, (int) ( $item['subscription_id'] ?? 0 ) );
		}
		return '';
	}

	/**
	 * Created-date cell — subscription creation date formatted in the
	 * store's date format. Live-looked up on every render; no Detector
	 * stash, since `WC_Subscription::get_date_created()` is cheap after
	 * `load_subscription()` caches the object.
	 *
	 * @param int $subscription_id
	 *
	 * @return string
	 */
	private function render_created( int $subscription_id ): string {
		$sub = $this->load_subscription( $subscription_id );
		if ( ! $sub instanceof WC_Subscription ) {
			return '—';
		}
		$date = $sub->get_date_created();
		if ( ! $date ) {
			return '—';
		}
		return esc_html( $date->date_i18n( get_option( 'date_format', 'Y-m-d' ) ) );
	}

	/**
	 * WP_List_Table's default cell dispatch.
	 *
	 * @param array<string, mixed> $item
	 * @param string               $column_name
	 *
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return $this->render_column( (array) $item, (string) $column_name );
	}


	/**
	 * Empty-state copy. Two states — see F1-polish commit for the
	 * rationale behind two-state messaging (unscanned vs zero).
	 *
	 * @return void
	 */
	public function no_items(): void {
		$latest_run_id = $this->run_store->get_latest_scan_run_id();
		if ( 0 === $latest_run_id ) {
			esc_html_e(
				"No scan results yet. Click 'Run now' above to audit your subscriptions, or wait for the scheduled nightly scan.",
				'woocommerce-subscriptions'
			);
			return;
		}

		$run      = $this->run_store->get( $latest_run_id );
		$when_utc = is_array( $run ) ? (string) ( $run['completed_at'] ?? $run['started_at'] ?? '' ) : '';
		$how_long = $this->human_time_since_mysql_utc( $when_utc );

		$has_filters = $this->has_active_filters( $this->current_filters() ) || '' !== $this->current_search_term();

		if ( $has_filters ) {
			printf(
				/* translators: %s: human-readable time diff. */
				esc_html__( 'No subscriptions match your current search or filters. The latest scan completed %s ago.', 'woocommerce-subscriptions' ),
				esc_html( $how_long )
			);
			return;
		}

		printf(
			/* translators: %s: human-readable time diff. */
			esc_html__( 'No subscriptions currently need review. The latest scan completed %s ago.', 'woocommerce-subscriptions' ),
			esc_html( $how_long )
		);
	}

	//
	// ───── Column renderers ──────────────────────────────────────────────
	//

	private function render_subscription_link( int $subscription_id ): string {
		if ( $subscription_id <= 0 ) {
			return '';
		}

		$url   = $this->subscription_edit_url( $subscription_id );
		$title = $this->subscription_title( $subscription_id );

		$line1 = sprintf(
			'<strong><a href="%1$s">#%2$d</a></strong>',
			esc_url( $url ),
			$subscription_id
		);

		$subscription = $this->load_subscription( $subscription_id );

		$actions = array(
			'edit' => sprintf(
				'<a href="%1$s" aria-label="%2$s">%3$s</a>',
				esc_url( $url ),
				/* translators: %d: subscription ID. */
				esc_attr( sprintf( __( 'Edit subscription #%d', 'woocommerce-subscriptions' ), $subscription_id ) ),
				esc_html__( 'Edit', 'woocommerce-subscriptions' )
			),
		);

		// The All view is intended as a list-and-navigate surface across
		// signals. Remediation needs signal-specific context that's only
		// clear on the per-signal tabs, so Resolve is available only
		// there. Edit stays everywhere. The View order action was
		// dropped in iteration 2: the row's Edit link already covers
		// the nav case, and the parent-order link added little signal.
		$is_all_view = 'all' === $this->current_view();

		if ( ! $is_all_view && $this->can_resolve() ) {
			$actions['resolve'] = sprintf(
				'<a href="#" class="wcs-health-check-resolve" data-subscription-id="%1$d" aria-haspopup="dialog" aria-label="%2$s">%3$s</a>',
				$subscription_id,
				/* translators: %d: subscription ID. */
				esc_attr( sprintf( __( 'Resolve subscription #%d', 'woocommerce-subscriptions' ), $subscription_id ) ),
				esc_html__( 'Resolve', 'woocommerce-subscriptions' )
			);
		}

		$row_actions = $this->row_actions( $actions );

		if ( '' === $title ) {
			return $line1 . $row_actions;
		}

		return sprintf(
			'<div class="woocommerce-subscriptions-health-check-subscription"><div>%1$s</div><div class="woocommerce-subscriptions-health-check-subscription-title">%2$s</div></div>%3$s',
			$line1,
			esc_html( $title ),
			$row_actions
		);
	}

	/**
	 * Render the Customer cell. Shows the billing name (with fallbacks
	 * via resolve_customer_name()) linked to the customer's WP user
	 * edit screen — the same pattern the WCS Subscriptions list uses in
	 * class-wcs-admin-post-types.php. For guest subscriptions
	 * (no customer user id) the name renders as plain text with no
	 * link. Email was retired from the visible column in a copy
	 * review; the search filter still matches on billing email via
	 * row_matches_search().
	 *
	 * @param int $subscription_id Subscription row id.
	 *
	 * @return string
	 */
	private function render_customer( int $subscription_id ): string {
		$subscription = $this->load_subscription( $subscription_id );
		if ( ! $subscription instanceof WC_Subscription ) {
			return '—';
		}

		$name = $this->resolve_customer_name( $subscription );
		if ( '' === $name ) {
			return '—';
		}

		$user_id = (int) $subscription->get_customer_id();
		if ( $user_id > 0 ) {
			return sprintf(
				'<div class="woocommerce-subscriptions-health-check-customer"><a href="%1$s">%2$s</a></div>',
				esc_url( admin_url( 'user-edit.php?user_id=' . $user_id ) ),
				esc_html( $name )
			);
		}

		return sprintf(
			'<div class="woocommerce-subscriptions-health-check-customer">%s</div>',
			esc_html( $name )
		);
	}

	/**
	 * Resolve a display-friendly customer name. Prefers the
	 * subscription's billing first/last fields (entered at checkout);
	 * when those are empty — common on fixtures, imported subs, or
	 * subscriptions created via the API — falls back to the WP user
	 * profile's first/last, then the user's display_name. Returns an
	 * empty string when no source has a name.
	 *
	 * @param WC_Subscription $subscription
	 *
	 * @return string
	 */
	private function resolve_customer_name( WC_Subscription $subscription ): string {
		$first = trim( (string) $subscription->get_billing_first_name() );
		$last  = trim( (string) $subscription->get_billing_last_name() );
		$name  = trim( $first . ' ' . $last );
		if ( '' !== $name ) {
			return $name;
		}

		$user_id = (int) $subscription->get_customer_id();
		if ( $user_id <= 0 ) {
			return '';
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return '';
		}

		$user_name = trim( trim( (string) $user->first_name ) . ' ' . trim( (string) $user->last_name ) );
		if ( '' !== $user_name ) {
			return $user_name;
		}
		return trim( (string) $user->display_name );
	}

	private function render_status( int $subscription_id ): string {
		$subscription = $this->load_subscription( $subscription_id );
		if ( ! $subscription instanceof WC_Subscription ) {
			return '—';
		}

		$status = (string) $subscription->get_status();
		$label  = (string) wcs_get_subscription_status_name( $status );

		// Mirror the main Subscriptions list table's status pill markup
		// (`WCS_Admin_Post_Types::render_columns()`) so Health Check shows the
		// same colour language merchants are used to. The `subscription-status`
		// class is what WCS's status palette (Active = green, Expired, Pending
		// Cancellation) keys off - the bare `order-status` markup we used before
		// only picked up WC core's order-status colours, which cover shared
		// statuses (On hold / Cancelled / Pending) but leave the
		// subscription-specific ones uncoloured. The matching `.subscription-status`
		// rules are shipped in `health-check-admin.css` (the list table's own
		// `admin.css` is not enqueued on the WC Status screen). The `tips`
		// tooltip is dropped: the label is already visible and the Status screen
		// does not load WC's tipTip JS.
		return sprintf(
			'<mark class="subscription-status order-status status-%1$s %1$s"><span>%2$s</span></mark>',
			esc_attr( sanitize_title( $status ) ),
			esc_html( $label )
		);
	}

	/**
	 * Renewal preference pill — Default / Opted out.
	 *
	 * Reads from the `renewal_preference` value the Detector stashes in
	 * `details_json` during a scan (`'opted_out'` / `'re_enabled'` /
	 * null). The pill surfaces:
	 *
	 *   - `'opted_out'` → **Opted out** (amber warning chrome)
	 *   - anything else (`'re_enabled'`, null, or missing) → **Default**
	 *     (neutral chrome). A re-enable note means the subscriber chose
	 *     auto at some point and the sub has since reverted to manual
	 *     via some other path — reads as Default from the merchant's
	 *     perspective (no explicit opt-out on record here).
	 *
	 * The filter scope already guarantees every row is manual with an
	 * eligible payment method, so there's no meaningful "Automatic"
	 * value on this column — every row IS manual by the filter
	 * definition. The only question the pill answers is "was manual
	 * the default, or did someone explicitly opt out?"
	 *
	 * @param array<string, mixed> $details Pre-stashed details payload.
	 *
	 * @return string
	 */
	private function render_renewal_preference( array $details ): string {
		$preference = (string) ( $details['renewal_preference'] ?? '' );

		if ( 'opted_out' === $preference ) {
			return sprintf(
				'<mark class="order-status status-on-hold"><span>%s</span></mark>',
				esc_html__( 'Opted out', 'woocommerce-subscriptions' )
			);
		}

		// Default: re-enable note, no renewal-type note, legacy pre-notes,
		// or imported-as-manual — none of which is an explicit current
		// opt-out, so the merchant sees "Default" (i.e. manual by store
		// or migration default, no subscriber preference on record).
		return sprintf(
			'<mark class="order-status status-pending"><span>%s</span></mark>',
			esc_html__( 'Default', 'woocommerce-subscriptions' )
		);
	}

	/**
	 * Render the Billing mode cell.
	 *
	 * Manual rows in the Supports auto-renewal view (rows whose `signals`
	 * include `has_token`) are prefixed with a warning-triangle tooltip:
	 * the merchant has confirmed evidence the customer has a payment
	 * method on file, so leaving the subscription in manual renewal is
	 * very likely a misconfiguration. Manual rows surfaced via other
	 * signals (e.g. Missing renewals) skip the warning because we don't
	 * have token-on-file evidence for those.
	 *
	 * @param int      $subscription_id Subscription id.
	 * @param string[] $signals         Per-row signal flags from the Detector
	 *                                  (e.g. `['has_token']` on the
	 *                                  Supports-auto-renewal pipeline).
	 *
	 * @return string
	 */
	private function render_billing_mode( int $subscription_id, array $signals = array() ): string {
		$subscription = $this->load_subscription( $subscription_id );
		if ( ! $subscription instanceof WC_Subscription ) {
			return '—';
		}

		// Reuse WC core's `.order-status` pill chrome to stay
		// visually consistent with the Status column.
		// Manual → `status-on-hold` (amber); Automatic → `status-completed` (green).
		if ( $subscription->is_manual() ) {
			$warning = '';
			if ( in_array( 'has_token', $signals, true ) ) {
				$warning = $this->render_warning_icon(
					__( 'The payment method on this subscription supports automatic renewal. Switch the billing mode to enable it.', 'woocommerce-subscriptions' )
				) . ' ';
			}

			return sprintf(
				'%1$s<mark class="order-status status-on-hold"><span>%2$s</span></mark>',
				$warning,
				esc_html__( 'Manual', 'woocommerce-subscriptions' )
			);
		}

		return sprintf(
			'<mark class="order-status status-completed"><span>%s</span></mark>',
			esc_html__( 'Automatic', 'woocommerce-subscriptions' )
		);
	}

	/**
	 * Render the Cycle cell — billing period + interval combined into
	 * a single human-readable label.
	 *
	 * Interval = 1 collapses to the period-specific adjective
	 * ("Daily" / "Weekly" / "Monthly" / "Yearly"); interval > 1 takes
	 * the "Every N {period-plural}" form. Matches the Figma mockup
	 * — the default WCS "every 2nd month" strings from
	 * `wcs_get_subscription_period_interval_strings()` read more
	 * awkwardly in this dense table context.
	 *
	 * Not sortable: "Every 2 months" has no natural ordering vs
	 * "Every 3 weeks", so any sort key would encode a product
	 * decision the merchant didn't ask us to make.
	 *
	 * @param array<string, mixed> $details         Pre-stashed details payload.
	 * @param int                  $subscription_id Live-lookup fallback target.
	 *
	 * @return string
	 */
	private function render_cycle( array $details, int $subscription_id ): string {
		$period   = isset( $details['cycle_period'] ) ? (string) $details['cycle_period'] : '';
		$interval = isset( $details['cycle_interval'] ) ? (int) $details['cycle_interval'] : 0;

		if ( '' === $period ) {
			// No stashed cycle (All-view rows; Supports-auto-renewal-view rows that
			// don't currently stash). Live-load the subscription so
			// the column still populates.
			$subscription = $this->load_subscription( $subscription_id );
			if ( ! $subscription instanceof WC_Subscription ) {
				return '—';
			}
			$period   = (string) $subscription->get_billing_period();
			$interval = (int) $subscription->get_billing_interval();
		}

		if ( '' === $period ) {
			return '—';
		}

		$label = $this->format_cycle_label( $period, max( 1, $interval ) );
		return '' === $label ? '—' : esc_html( $label );
	}

	/**
	 * Format a billing period + interval pair into the UI label.
	 *
	 * Broken out of `render_cycle()` so tests can assert the mapping
	 * without needing to construct a real subscription, and so the
	 * Missing-renewal classifier could reuse the same formatter for
	 * stash-time display strings later.
	 *
	 * @param string $period   'day' | 'week' | 'month' | 'year'.
	 * @param int    $interval Billing interval (≥ 1).
	 *
	 * @return string Empty string for unrecognised periods.
	 */
	public static function format_cycle_label( string $period, int $interval ): string {
		if ( $interval < 1 ) {
			$interval = 1;
		}

		if ( 1 === $interval ) {
			switch ( $period ) {
				case 'day':
					return (string) __( 'Daily', 'woocommerce-subscriptions' );
				case 'week':
					return (string) __( 'Weekly', 'woocommerce-subscriptions' );
				case 'month':
					return (string) __( 'Monthly', 'woocommerce-subscriptions' );
				case 'year':
					return (string) __( 'Yearly', 'woocommerce-subscriptions' );
				default:
					return '';
			}
		}

		switch ( $period ) {
			case 'day':
				/* translators: %d: number of days in a subscription's billing cycle. */
				return sprintf( _n( 'Every %d day', 'Every %d days', $interval, 'woocommerce-subscriptions' ), $interval );
			case 'week':
				/* translators: %d: number of weeks in a subscription's billing cycle. */
				return sprintf( _n( 'Every %d week', 'Every %d weeks', $interval, 'woocommerce-subscriptions' ), $interval );
			case 'month':
				/* translators: %d: number of months in a subscription's billing cycle. */
				return sprintf( _n( 'Every %d month', 'Every %d months', $interval, 'woocommerce-subscriptions' ), $interval );
			case 'year':
				/* translators: %d: number of years in a subscription's billing cycle. */
				return sprintf( _n( 'Every %d year', 'Every %d years', $interval, 'woocommerce-subscriptions' ), $interval );
			default:
				return '';
		}
	}

	private function render_payment_method( int $subscription_id ): string {
		$subscription = $this->load_subscription( $subscription_id );
		if ( ! $subscription instanceof WC_Subscription ) {
			return '—';
		}

		$gateway_title = (string) $subscription->get_payment_method_title();
		$token_summary = $this->resolve_payment_token( $subscription );
		$last4         = $token_summary['last4'] ?? '';
		$has_token     = (bool) ( $token_summary['has_token'] ?? false );

		$line1_parts = array();
		if ( '' !== $gateway_title ) {
			$line1_parts[] = esc_html( $gateway_title );
		}
		if ( '' !== $last4 ) {
			$line1_parts[] = esc_html( '···· ' . $last4 );
		}

		$line1 = '' === $gateway_title && '' === $last4
			? esc_html__( 'Not set', 'woocommerce-subscriptions' )
			: implode( ' ', $line1_parts );

		$line2 = sprintf(
			'<span class="woocommerce-subscriptions-health-check-token %1$s">%2$s %3$s</span>',
			$has_token ? 'woocommerce-subscriptions-health-check-token-ok' : 'woocommerce-subscriptions-health-check-token-warn',
			esc_html__( 'Token on file:', 'woocommerce-subscriptions' ),
			$has_token
				? esc_html__( 'Yes', 'woocommerce-subscriptions' )
				: esc_html__( 'No', 'woocommerce-subscriptions' )
		);

		return sprintf(
			'<div class="woocommerce-subscriptions-health-check-payment"><div>%1$s</div><div>%2$s</div></div>',
			$line1,
			$line2
		);
	}

	/**
	 * Render the Next payment date cell.
	 *
	 * Sources the timestamp from the candidate row's details payload
	 * when available (Missing-renewal classifier stashes
	 * `next_payment_timestamp`); falls back to a live
	 * `WC_Subscription::get_time('next_payment')` lookup for All-view
	 * rows and Supports-auto-renewal rows (whose classifier doesn't
	 * stash the timestamp — it's not part of that signal's decision).
	 *
	 * Rendering:
	 *   - Missing: em dash — the Missing-renewal signal means "no next
	 *     payment date exists"; the tab context already communicates why.
	 *   - Past-due: formatted date on line 1, "N ago" on line 2 in
	 *     the same amber pill chrome.
	 *   - Upcoming: formatted date on line 1, "in N" on line 2 in
	 *     the neutral `.woocommerce-subscriptions-health-check-next-payment-future`
	 *     wrapper.
	 *
	 * @param array<string, mixed> $details         Pre-stashed details payload.
	 * @param int                  $subscription_id Live-lookup fallback target.
	 *
	 * @return string
	 */
	private function render_next_payment( array $details, int $subscription_id ): string {
		$stashed_ts    = isset( $details['next_payment_timestamp'] ) ? (int) $details['next_payment_timestamp'] : 0;
		$stashed_state = isset( $details['next_payment_state'] ) ? (string) $details['next_payment_state'] : '';

		$subscription = null;
		$timestamp    = $stashed_ts;
		if ( 0 === $timestamp && $subscription_id > 0 ) {
			$subscription = $this->load_subscription( $subscription_id );
			if ( $subscription instanceof WC_Subscription ) {
				$timestamp = (int) $subscription->get_time( 'next_payment' );
			}
		}

		if ( 'missing' === $stashed_state || 0 === $timestamp ) {
			$warning = $this->render_warning_icon(
				__( 'There is no scheduled payment date for this subscription. Process now to resume billing.', 'woocommerce-subscriptions' )
			);
			return $warning . ' &mdash;';
		}

		$date_label = wp_date( get_option( 'date_format', 'Y-m-d' ), $timestamp );
		if ( false === $date_label ) {
			$date_label = gmdate( 'Y-m-d', $timestamp );
		}

		$now         = time();
		$is_past_due = $timestamp < $now;

		if ( $is_past_due ) {
			$diff = human_time_diff( $timestamp, $now );
			/* translators: %s: human-readable time diff like "3 days". */
			$diff_text = sprintf( __( '%s ago', 'woocommerce-subscriptions' ), $diff );
			$warning   = $this->render_warning_icon(
				__( 'The scheduled payment date for this subscription is in the past. Process now to resume billing.', 'woocommerce-subscriptions' )
			);
			return sprintf(
				'<div class="woocommerce-subscriptions-health-check-next-payment woocommerce-subscriptions-health-check-next-payment-past">%3$s<div class="woocommerce-subscriptions-health-check-next-payment-text"><div>%1$s</div><div class="woocommerce-subscriptions-health-check-next-payment-diff">%2$s</div></div></div>',
				esc_html( $date_label ),
				esc_html( $diff_text ),
				$warning
			);
		}

		$diff = human_time_diff( $now, $timestamp );
		/* translators: %s: human-readable time diff like "3 days". */
		$diff_text = sprintf( __( 'in %s', 'woocommerce-subscriptions' ), $diff );
		return sprintf(
			'<div class="woocommerce-subscriptions-health-check-next-payment woocommerce-subscriptions-health-check-next-payment-future"><div>%1$s</div><div class="woocommerce-subscriptions-health-check-next-payment-diff">%2$s</div></div>',
			esc_html( $date_label ),
			esc_html( $diff_text )
		);
	}

	/**
	 * Renewal-order-status pill. Supports-auto-renewal-view rows carry the status
	 * pre-stashed in `details.latest_renewal_status` by the Detector;
	 * All-view rows have no details payload, so we fall back to a
	 * live `WC_Subscription::get_related_orders('renewal')` lookup.
	 *
	 * Uses the same `mark.order-status.status-{slug}` pill chrome as
	 * the subscription Status column so the visual language is shared.
	 *
	 * @param array<string, mixed> $details         Pre-stashed details payload.
	 *                                              Empty array on All-view rows.
	 * @param int                  $subscription_id Live-lookup fallback target.
	 *
	 * @return string
	 */
	private function render_renewal_order_status( array $details, int $subscription_id ): string {
		$status = isset( $details['latest_renewal_status'] ) ? (string) $details['latest_renewal_status'] : '';
		if ( '' === $status && $subscription_id > 0 ) {
			$status = $this->lookup_latest_renewal_status( $subscription_id );
		}
		if ( '' === $status ) {
			return '—';
		}

		$label = (string) wc_get_order_status_name( $status );

		return sprintf(
			'<mark class="order-status status-%1$s"><span>%2$s</span></mark>',
			esc_attr( $status ),
			esc_html( $label )
		);
	}

	/**
	 * Last successful payment cell — live-looked up from the subscription
	 * on every render, mirroring the Detector's pre-broadening "most
	 * recent completed/processing renewal with a non-empty payment method"
	 * logic, falling back to a qualifying parent order if the sub has no
	 * renewal history.
	 *
	 * `$details` is kept on the signature for future use (a cached value
	 * could be surfaced via `details_json` in a later iteration), but is
	 * currently unused.
	 *
	 * @param array<string, mixed> $details         Unused (kept for signature symmetry with other column renderers).
	 * @param int                  $subscription_id Subscription to resolve the payment from.
	 *
	 * @return string
	 */
	private function render_last_payment( array $details, int $subscription_id ): string {
		unset( $details );

		if ( $subscription_id <= 0 ) {
			return '—';
		}

		$order_id = $this->lookup_prior_auto_renewal_id( $subscription_id );
		if ( $order_id <= 0 ) {
			$order_id = $this->lookup_prior_parent_payment_id( $subscription_id );
		}

		if ( $order_id <= 0 ) {
			return '—';
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return '—';
		}

		$date = $order->get_date_created();
		if ( ! $date ) {
			return '—';
		}

		$date_label = $date->date_i18n( get_option( 'date_format', 'Y-m-d' ) );
		// wc_price() returns HTML that can be filtered by third-party
		// plugins via `wc_price`/`woocommerce_price_format`. The
		// internal escaping of the numeric value is solid, but the
		// interpolated HTML is not — wrap in wp_kses_post() for
		// defense-in-depth against a filter that injects unescaped
		// content.
		$total_label = wp_kses_post(
			wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) )
		);

		return sprintf(
			'<div class="woocommerce-subscriptions-health-check-last-payment"><div>%1$s</div><div class="woocommerce-subscriptions-health-check-last-payment-total">%2$s</div></div>',
			esc_html( $date_label ),
			$total_label
		);
	}

	/**
	 * Live lookup for the latest renewal order's status — All-view
	 * fallback when details_json doesn't carry the pre-stashed value.
	 * `WC_Subscription::get_related_orders('all','renewal')` returns
	 * the renewal orders most-recent-first (it `arsort()`s the
	 * id-keyed map internally), so the first WC_Order entry is the
	 * latest. The Detector encodes the same selection during scans
	 * via the prefetch path's `(date_gmt DESC, id DESC)` comparison;
	 * this method is the live equivalent for unscanned rows.
	 *
	 * @param int $subscription_id
	 *
	 * @return string Empty string when no renewal exists.
	 */
	private function lookup_latest_renewal_status( int $subscription_id ): string {
		$sub = $this->load_subscription( $subscription_id );
		if ( ! $sub instanceof WC_Subscription ) {
			return '';
		}
		foreach ( $sub->get_related_orders( 'all', 'renewal' ) as $renewal ) {
			if ( $renewal instanceof \WC_Order ) {
				return (string) $renewal->get_status();
			}
		}
		return '';
	}

	/**
	 * Live lookup for the most recent successful auto-renewal id —
	 * All-view fallback for the Last successful payment column.
	 * Filter shape: status in {completed, processing} AND non-empty
	 * payment method. The "non-empty payment method" guard
	 * distinguishes a genuinely-charged renewal from a manual-mode
	 * placeholder that WCS sometimes leaves with no method id.
	 *
	 * @param int $subscription_id
	 *
	 * @return int 0 when no qualifying renewal exists.
	 */
	private function lookup_prior_auto_renewal_id( int $subscription_id ): int {
		$sub = $this->load_subscription( $subscription_id );
		if ( ! $sub instanceof WC_Subscription ) {
			return 0;
		}
		foreach ( $sub->get_related_orders( 'all', 'renewal' ) as $renewal ) {
			if ( ! $renewal instanceof \WC_Order ) {
				continue;
			}
			$status = $renewal->get_status();
			if ( 'completed' !== $status && 'processing' !== $status ) {
				continue;
			}
			if ( '' === (string) $renewal->get_payment_method() ) {
				continue;
			}
			return (int) $renewal->get_id();
		}
		return 0;
	}

	/**
	 * Live lookup for the parent-order payment id — All-view fallback for
	 * the Last successful payment column when no renewal evidence exists
	 * (silent-from-birth victims). Filter shape: parent in
	 * {completed, processing} AND non-empty payment method. The gateway-
	 * supports-subscriptions check is intentionally omitted here — the
	 * UI is visualising an historical charge, not re-running the
	 * detection pipeline, so listing a completed parent payment that
	 * happened on a now-non-auto gateway is still informative.
	 *
	 * @param int $subscription_id
	 *
	 * @return int 0 when no qualifying parent exists.
	 */
	private function lookup_prior_parent_payment_id( int $subscription_id ): int {
		$sub = $this->load_subscription( $subscription_id );
		if ( ! $sub instanceof WC_Subscription ) {
			return 0;
		}
		$parent = $sub->get_parent();
		if ( ! $parent instanceof \WC_Order ) {
			return 0;
		}
		$status = $parent->get_status();
		if ( 'completed' !== $status && 'processing' !== $status ) {
			return 0;
		}
		if ( '' === (string) $parent->get_payment_method() ) {
			return 0;
		}
		return (int) $parent->get_id();
	}

	//
	// ───── Sort + search ─────────────────────────────────────────────────
	//

	private function current_orderby(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list sort on a GET.
		if ( ! isset( $_REQUEST['orderby'] ) ) {
			// Default sort: newest subscription first. Subscription IDs
			// are monotonically increasing auto-increment PKs, so
			// `subscription_id DESC` gives the same "newest first" order
			// as sorting by `start_date` DESC — at significantly lower
			// cost (no extra timestamp lookup).
			return 'subscription_id';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return sanitize_key( wp_unslash( $_REQUEST['orderby'] ) );
	}

	private function current_order(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_REQUEST['order'] ) ) {
			// Pair with `current_orderby()`'s default of subscription_id
			// to land "newest first" on the default page load.
			return 'desc';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order = strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) );
		return 'desc' === $order ? 'desc' : 'asc';
	}

	private function current_search_term(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_REQUEST['s'] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw = sanitize_text_field( wp_unslash( $_REQUEST['s'] ) );
		return '' === $raw ? '' : strtolower( $raw );
	}

	private function row_matches_search( array $row, string $search ): bool {
		$sub_id = (string) ( $row['subscription_id'] ?? '' );
		if ( '' !== $sub_id && false !== strpos( strtolower( $sub_id ), $search ) ) {
			return true;
		}

		$subscription = $this->load_subscription( (int) ( $row['subscription_id'] ?? 0 ) );
		if ( $subscription instanceof WC_Subscription ) {
			$email = strtolower( (string) $subscription->get_billing_email() );
			if ( '' !== $email && false !== strpos( $email, $search ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Currently-active top-bar filters, sanitized and allowlisted.
	 *
	 * Any missing or out-of-allowlist query arg comes back as an empty
	 * string so downstream callers can use a simple `'' === $value`
	 * check.
	 *
	 * @return array{status:string, billing_mode:string, renewal_order_status:string, renewal_preference:string}
	 */
	private function current_filters(): array {
		return array(
			'status'               => $this->read_filter( 'status', $this->allowed_status_values() ),
			'billing_mode'         => $this->read_filter( 'billing_mode', $this->allowed_billing_mode_values() ),
			'renewal_order_status' => $this->read_filter( 'renewal_order_status', $this->allowed_renewal_order_status_values() ),
			'renewal_preference'   => $this->read_filter( 'renewal_preference', $this->allowed_renewal_preference_values() ),
		);
	}

	/**
	 * Read a filter query-arg and gate it against the provided allowlist.
	 *
	 * @param string        $key     Query-arg key.
	 * @param array<string> $allowed Allowlisted values.
	 *
	 * @return string Empty string when missing or not in the allowlist.
	 */
	private function read_filter( string $key, array $allowed ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter on a GET.
		if ( ! isset( $_REQUEST[ $key ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$value = sanitize_key( wp_unslash( $_REQUEST[ $key ] ) );
		return in_array( $value, $allowed, true ) ? $value : '';
	}

	/**
	 * @param array{status:string, billing_mode:string, renewal_order_status:string, renewal_preference:string} $filters
	 */
	private function has_active_filters( array $filters ): bool {
		return '' !== $filters['status']
			|| '' !== $filters['billing_mode']
			|| '' !== $filters['renewal_order_status']
			|| '' !== $filters['renewal_preference'];
	}

	/**
	 * Does this candidate row match every active filter? Missing keys
	 * (e.g. a row with no stashed `renewal_preference`) fail the match
	 * when the user has asked for a specific value — nothing surfaces
	 * without positive evidence.
	 *
	 * Status + billing mode are read from the live subscription, not
	 * the stashed row — a merchant may have changed either after the
	 * last scan.
	 *
	 * @param array<string, mixed>                                                                              $row
	 * @param array{status:string, billing_mode:string, renewal_order_status:string, renewal_preference:string} $filters
	 */
	private function row_matches_filters( array $row, array $filters ): bool {
		$needs_live_sub = '' !== $filters['status'] || '' !== $filters['billing_mode'];
		$sub            = $needs_live_sub ? $this->load_subscription( (int) ( $row['subscription_id'] ?? 0 ) ) : null;

		if ( '' !== $filters['status'] ) {
			if ( ! $sub instanceof WC_Subscription || $sub->get_status() !== $filters['status'] ) {
				return false;
			}
		}

		if ( '' !== $filters['billing_mode'] ) {
			if ( ! $sub instanceof WC_Subscription ) {
				return false;
			}
			$row_mode = $sub->is_manual() ? 'manual' : 'auto';
			if ( $row_mode !== $filters['billing_mode'] ) {
				return false;
			}
		}

		$details = $this->details_from( $row );

		if ( '' !== $filters['renewal_order_status'] ) {
			$latest = (string) ( $details['latest_renewal_status'] ?? '' );
			if ( $latest !== $filters['renewal_order_status'] ) {
				return false;
			}
		}

		if ( '' !== $filters['renewal_preference'] ) {
			$preference = (string) ( $details['renewal_preference'] ?? '' );
			if ( 'default' === $filters['renewal_preference'] ) {
				if ( 'opted_out' === $preference ) {
					return false;
				}
			} elseif ( $preference !== $filters['renewal_preference'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Subscription statuses merchants can filter on. Matches the set
	 * WooCommerce Subscriptions considers active/live (not trash or
	 * auto-draft). Includes historical statuses (cancelled / expired)
	 * so a merchant can page through past subs as well.
	 *
	 * @return array<string>
	 */
	private function allowed_status_values(): array {
		return array( 'active', 'on-hold', 'pending-cancel', 'pending', 'cancelled', 'expired' );
	}

	/**
	 * Billing-mode dropdown values. Two options — automatic (token-
	 * billed) and manual. Candidate rows expose the live mode via
	 * `WC_Subscription::is_manual()` so the filter works uniformly on
	 * every view.
	 *
	 * @return array<string>
	 */
	private function allowed_billing_mode_values(): array {
		return array( 'auto', 'manual' );
	}

	/**
	 * WC order statuses the renewal_order_status filter can match.
	 * Mirrors the options WooCommerce renders on the Orders list.
	 *
	 * @return array<string>
	 */
	private function allowed_renewal_order_status_values(): array {
		return array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' );
	}

	/**
	 * Renewal preference values currently rendered by the Renewal
	 * preference pill. 'default' is not a stored value — "no stored
	 * opt-out note" reads as default — so the dropdown exposes both
	 * `opted_out` and `default` as filterable cases.
	 *
	 * @return array<string>
	 */
	private function allowed_renewal_preference_values(): array {
		return array( 'opted_out', 'default' );
	}

	/**
	 * Render the top-of-table filter bar. Standard WP_List_Table hook —
	 * `$which` is `'top'` or `'bottom'`. We render controls only above
	 * the table, mirroring the WC Products / Orders list convention.
	 *
	 * Filter scope caveat: `renewal_order_status` and `renewal_preference`
	 * dropdowns read from per-row details stashed on the candidate row.
	 * On the All view those details are not populated (rows come from
	 * `wcs_get_subscriptions`, not the candidate store), so only the
	 * Status filter has an effect there.
	 *
	 * @param string $which 'top' or 'bottom'.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$filters      = $this->current_filters();
		$current_view = $this->current_view();
		$is_all_view  = 'all' === $current_view;

		// Preserve the active view across filter submits. WP_List_Table
		// wraps the filter controls in the StatusTab's GET form, so a
		// filter click drops any query-arg not represented as a form
		// field. Without this hidden input, filtering from a non-
		// default view would bounce back to the Supports auto-renewal
		// view. The default view's tab URL omits `?view=` by design, so
		// the hidden input is suppressed for it too.
		if ( 'supports_auto_renewal' !== $current_view ) {
			printf(
				'<input type="hidden" name="view" value="%s" />',
				esc_attr( $current_view )
			);
		}

		echo '<div class="alignleft actions">';

		$this->render_filter_select(
			'status',
			__( 'All statuses', 'woocommerce-subscriptions' ),
			$this->status_filter_options(),
			$filters['status']
		);

		// Filters that the All view's `wcs_get_subscriptions()` query
		// path does not narrow on. Renewal order status + Renewal
		// preference read per-row details stashed on candidate rows
		// (not populated for All-view rows fetched live); Billing
		// mode is a live `WC_Subscription::is_manual()` read but the
		// All-view query path does not thread it through. Hide the
		// dropdowns there so merchants don't click controls that
		// can't change the result set.
		if ( ! $is_all_view ) {
			$this->render_filter_select(
				'billing_mode',
				__( 'All billing modes', 'woocommerce-subscriptions' ),
				$this->billing_mode_filter_options(),
				$filters['billing_mode']
			);

			$this->render_filter_select(
				'renewal_order_status',
				__( 'All renewal order statuses', 'woocommerce-subscriptions' ),
				$this->renewal_order_status_filter_options(),
				$filters['renewal_order_status']
			);

			$this->render_filter_select(
				'renewal_preference',
				__( 'All renewal preferences', 'woocommerce-subscriptions' ),
				$this->renewal_preference_filter_options(),
				$filters['renewal_preference']
			);
		}

		submit_button( __( 'Filter', 'woocommerce-subscriptions' ), '', 'filter_action', false );

		echo '</div>';
	}

	/**
	 * Two responsibilities, both keyed off the tablenav position:
	 *
	 *  - Render the truncation notice between the top tablenav and the
	 *    table itself. `extra_tablenav()` would have placed it inside
	 *    `.tablenav.top` next to the float-left filter controls, where
	 *    a non-floated block doesn't clear and overlaps the headers
	 *    below. Emitting after the parent's top tablenav lands the
	 *    notice as a clean sibling of the tablenav.
	 *
	 *  - Bracket the `<table>` with `.wcs-health-check-candidates-scroll`,
	 *    a horizontally-scrollable wrapper. WP core's `display()` calls
	 *    this method around the table, so opening the wrapper after the
	 *    top tablenav and closing it before the bottom tablenav keeps
	 *    the views / search / pagination chrome at full content width
	 *    while only the data area scrolls.
	 *
	 * @param string $which 'top' or 'bottom'.
	 */
	protected function display_tablenav( $which ) {
		if ( 'top' === $which ) {
			parent::display_tablenav( $which );
			$this->maybe_render_truncation_notice();
			echo '<div class="wcs-health-check-candidates-scroll">';
			return;
		}

		echo '</div>';
		parent::display_tablenav( $which );
	}

	/**
	 * Render a "showing first N" notice when a candidate-backed view
	 * (Supports auto-renewal / Missing renewals) capped the fallback PHP fetch.
	 * Kept in the table header so merchants see the cap before they
	 * scroll an incomplete list wondering where the rest of their
	 * candidates went.
	 */
	private function maybe_render_truncation_notice(): void {
		if ( ! $this->signal_view_truncated ) {
			return;
		}

		printf(
			'<div class="notice notice-warning inline"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: localised number of candidate rows checked, e.g. "500". */
					__(
						'Filter and sort applied to the first %s candidates. Other matches may exist beyond this set.',
						'woocommerce-subscriptions'
					),
					number_format_i18n( self::SIGNAL_PHP_FALLBACK_CAP )
				)
			)
		);
	}

	/**
	 * Render a single filter `<select>` with an "any" default row and
	 * the provided value → label pairs. Called from `extra_tablenav`.
	 *
	 * @param string                $name     Form field name / query-arg key.
	 * @param string                $any_label Translated label for the "no filter" row.
	 * @param array<string, string> $options  Value → translated label pairs.
	 * @param string                $current  Currently-selected value (empty string = none).
	 */
	private function render_filter_select( string $name, string $any_label, array $options, string $current ): void {
		printf(
			'<label class="screen-reader-text" for="filter-by-%1$s">%2$s</label>',
			esc_attr( $name ),
			esc_html( $any_label )
		);
		printf(
			'<select name="%1$s" id="filter-by-%1$s">',
			esc_attr( $name )
		);
		printf(
			'<option value="">%s</option>',
			esc_html( $any_label )
		);
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * @return array<string, string>
	 */
	private function status_filter_options(): array {
		return array(
			'active'         => __( 'Active', 'woocommerce-subscriptions' ),
			'on-hold'        => __( 'On hold', 'woocommerce-subscriptions' ),
			'pending-cancel' => __( 'Pending cancellation', 'woocommerce-subscriptions' ),
			'pending'        => __( 'Pending', 'woocommerce-subscriptions' ),
			'cancelled'      => __( 'Cancelled', 'woocommerce-subscriptions' ),
			'expired'        => __( 'Expired', 'woocommerce-subscriptions' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function billing_mode_filter_options(): array {
		return array(
			'auto'   => __( 'Auto', 'woocommerce-subscriptions' ),
			'manual' => __( 'Manual', 'woocommerce-subscriptions' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function renewal_order_status_filter_options(): array {
		return array(
			'pending'    => __( 'Pending payment', 'woocommerce-subscriptions' ),
			'processing' => __( 'Processing', 'woocommerce-subscriptions' ),
			'on-hold'    => __( 'On hold', 'woocommerce-subscriptions' ),
			'completed'  => __( 'Completed', 'woocommerce-subscriptions' ),
			'cancelled'  => __( 'Cancelled', 'woocommerce-subscriptions' ),
			'refunded'   => __( 'Refunded', 'woocommerce-subscriptions' ),
			'failed'     => __( 'Failed', 'woocommerce-subscriptions' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function renewal_preference_filter_options(): array {
		return array(
			'default'   => __( 'Default', 'woocommerce-subscriptions' ),
			'opted_out' => __( 'Opted out', 'woocommerce-subscriptions' ),
		);
	}

	/**
	 * Sort the full filtered set by one of the live-lookup columns:
	 * status, billing_mode, renewal_order_status.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @param string                            $orderby
	 * @param string                            $order
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function php_sort_items( array $items, string $orderby, string $order ): array {
		$compare = $this->sort_comparator_for( $orderby );
		if ( null === $compare ) {
			return $items;
		}
		$direction = 'desc' === $order ? -1 : 1;
		usort(
			$items,
			static function ( $a, $b ) use ( $compare, $direction ) {
				return $direction * $compare( $a, $b );
			}
		);
		return $items;
	}

	/**
	 * Return a comparator closure for the given sort column, or null
	 * when we don't know how to compare.
	 *
	 * @param string $orderby
	 *
	 * @return callable|null
	 */
	private function sort_comparator_for( string $orderby ): ?callable {
		$table = $this;

		switch ( $orderby ) {
			case 'subscription_id':
				return static function ( $a, $b ) {
					return ( (int) ( $a['subscription_id'] ?? 0 ) ) <=> ( (int) ( $b['subscription_id'] ?? 0 ) );
				};
			case 'created':
				return static function ( $a, $b ) use ( $table ) {
					$sa = $table->load_subscription( (int) ( $a['subscription_id'] ?? 0 ) );
					$sb = $table->load_subscription( (int) ( $b['subscription_id'] ?? 0 ) );
					$va = $sa && $sa->get_date_created() ? (int) $sa->get_date_created()->getTimestamp() : 0;
					$vb = $sb && $sb->get_date_created() ? (int) $sb->get_date_created()->getTimestamp() : 0;
					return $va <=> $vb;
				};
			case 'status':
				return static function ( $a, $b ) use ( $table ) {
					$sa = $table->load_subscription( (int) ( $a['subscription_id'] ?? 0 ) );
					$sb = $table->load_subscription( (int) ( $b['subscription_id'] ?? 0 ) );
					$va = $sa ? (string) $sa->get_status() : '';
					$vb = $sb ? (string) $sb->get_status() : '';
					return strcmp( $va, $vb );
				};
			case 'billing_mode':
				return static function ( $a, $b ) use ( $table ) {
					$sa = $table->load_subscription( (int) ( $a['subscription_id'] ?? 0 ) );
					$sb = $table->load_subscription( (int) ( $b['subscription_id'] ?? 0 ) );
					$va = $sa && $sa->is_manual() ? 1 : 0;
					$vb = $sb && $sb->is_manual() ? 1 : 0;
					return $va - $vb;
				};
			case 'renewal_preference':
				return static function ( $a, $b ) use ( $table ) {
					// The column renders exactly two values: Opted out
					// and Default. Normalise raw detail values to match
					// so the sort groups visually identical rows together.
					$va = 'opted_out' === ( $table->details_from( (array) $a )['renewal_preference'] ?? '' ) ? 1 : 0;
					$vb = 'opted_out' === ( $table->details_from( (array) $b )['renewal_preference'] ?? '' ) ? 1 : 0;
					return $va - $vb;
				};
			case 'renewal_order_status':
				return static function ( $a, $b ) use ( $table ) {
					$va = (string) ( $table->details_from( (array) $a )['latest_renewal_status'] ?? '' );
					$vb = (string) ( $table->details_from( (array) $b )['latest_renewal_status'] ?? '' );
					return strcmp( $va, $vb );
				};
			case 'next_payment':
				return static function ( $a, $b ) use ( $table ) {
					$va = $table->next_payment_sort_key( (array) $a );
					$vb = $table->next_payment_sort_key( (array) $b );
					return $va <=> $vb;
				};
			case 'last_payment':
				return static function ( $a, $b ) use ( $table ) {
					$va = $table->last_payment_sort_key( (array) $a );
					$vb = $table->last_payment_sort_key( (array) $b );
					return $va <=> $vb;
				};
		}
		return null;
	}

	/**
	 * Sort key for the Last successful payment column — Unix timestamp
	 * of the prior auto-renewal order's creation date. Rows without a
	 * resolvable date sort to the end so "no successful payment yet"
	 * lands below real timestamps in ascending order.
	 *
	 * Protected for test subclasses; comparator closures declared in this
	 * class can call it on the captured table instance.
	 *
	 * @param array<string, mixed> $item
	 *
	 * @return int
	 */
	/**
	 * Sort key for the Next payment date column — Unix timestamp of
	 * the subscription's `_schedule_next_payment`. Uses the stashed
	 * `details.next_payment_timestamp` when present (Missing-renewal
	 * rows); falls back to a live `get_time('next_payment')` lookup
	 * for candidate rows whose signal didn't stash it (Supports-
	 * auto-renewal rows).
	 *
	 * Rows with no resolvable timestamp sort to the end — "Missing"
	 * lands below real dates in ascending order, which reads as
	 * "missing is the least-scheduled thing possible."
	 *
	 * Protected for test subclasses; comparator closures declared in
	 * this class can call it on the captured table instance.
	 *
	 * @param array<string, mixed> $item
	 *
	 * @return int
	 */
	protected function next_payment_sort_key( array $item ): int {
		$details = $this->details_from( $item );
		$stashed = (int) ( $details['next_payment_timestamp'] ?? 0 );
		if ( $stashed > 0 ) {
			return $stashed;
		}

		$subscription_id = (int) ( $item['subscription_id'] ?? 0 );
		if ( $subscription_id <= 0 ) {
			return PHP_INT_MAX;
		}
		$sub = $this->load_subscription( $subscription_id );
		if ( ! $sub instanceof WC_Subscription ) {
			return PHP_INT_MAX;
		}
		$ts = (int) $sub->get_time( 'next_payment' );
		return $ts > 0 ? $ts : PHP_INT_MAX;
	}

	protected function last_payment_sort_key( array $item ): int {
		$subscription_id = (int) ( $item['subscription_id'] ?? 0 );
		if ( $subscription_id <= 0 ) {
			return PHP_INT_MAX;
		}

		$order_id = $this->lookup_prior_auto_renewal_id( $subscription_id );
		if ( $order_id <= 0 ) {
			$order_id = $this->lookup_prior_parent_payment_id( $subscription_id );
		}
		if ( $order_id <= 0 ) {
			return PHP_INT_MAX;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return PHP_INT_MAX;
		}
		$date = $order->get_date_created();
		return $date ? (int) $date->getTimestamp() : PHP_INT_MAX;
	}

	//
	// ───── Private helpers ───────────────────────────────────────────────
	//

	/**
	 * Load a subscription by id, caching results along the way.
	 *
	 * @internal Public only for static sort comparator closures; not extension API.
	 *
	 * @param int $subscription_id
	 *
	 * @return WC_Subscription|null
	 */
	public function load_subscription( int $subscription_id ): ?WC_Subscription {
		if ( $subscription_id > 0 ) {
			if ( ! array_key_exists( $subscription_id, $this->subscription_cache ) ) {
				$sub = wcs_get_subscription( $subscription_id );
				$this->subscription_cache[ $subscription_id ] = $sub instanceof WC_Subscription ? $sub : null;
			}

			return $this->subscription_cache[ $subscription_id ];
		}
		return null;
	}

	private function subscription_title( int $subscription_id ): string {
		$subscription = $this->load_subscription( $subscription_id );

		if ( null !== $subscription ) {
			foreach ( $subscription->get_items() as $item ) {
				if ( $item instanceof \WC_Order_Item ) {
					$name = (string) $item->get_name();
					if ( '' !== $name ) {
						return $name;
					}
				}
			}
		}

		return '';
	}

	private function subscription_edit_url( int $subscription_id ): string {
		// Go through `load_subscription()` so the per-request cache is
		// populated by the first column that needs the subscription.
		// `render_subscription_link()` calls this immediately before
		// `subscription_title()` — bypassing the cache here would force
		// two `wcs_get_subscription()` loads per row.
		$subscription = $this->load_subscription( $subscription_id );
		if ( $subscription instanceof WC_Subscription ) {
			return $subscription->get_edit_order_url();
		}

		return admin_url( sprintf( 'post.php?post=%d&action=edit', $subscription_id ) );
	}

	/**
	 * @return array{has_token:bool, last4:string}
	 */
	private function resolve_payment_token( WC_Subscription $subscription ): array {
		$gateway  = (string) $subscription->get_payment_method();
		$customer = (int) $subscription->get_customer_id();
		if ( '' === $gateway || $customer <= 0 || ! class_exists( '\WCS_Payment_Tokens' ) ) {
			return array(
				'has_token' => false,
				'last4'     => '',
			);
		}

		$tokens = $this->resolve_tokens( $customer, $gateway );
		if ( empty( $tokens ) ) {
			return array(
				'has_token' => false,
				'last4'     => '',
			);
		}

		$first = reset( $tokens );
		$last4 = '';
		if ( $first instanceof WC_Payment_Token_CC ) {
			$last4 = (string) $first->get_last4();
		}

		return array(
			'has_token' => true,
			'last4'     => $last4,
		);
	}

	/**
	 * Resolve payment tokens for a (customer, gateway) pair via the
	 * WCS wrapper, which maintains a request-level static cache keyed
	 * on those two values. Repeated calls for the same pair within a
	 * single admin render or scan batch hit the DB only once.
	 *
	 * The `woocommerce_get_customer_payment_tokens_limit` filter bump
	 * raises the query limit for this call so a customer with a long
	 * token history still has their currently-active card returned
	 * (WC core defaults the limit to `posts_per_page`, which can be as
	 * low as 10). The filter is torn down immediately after so other
	 * admin code unaffected by the Health Check path keeps the default.
	 *
	 * @param int    $customer_id Customer user id.
	 * @param string $gateway     Payment gateway id.
	 *
	 * @return array<int, \WC_Payment_Token>
	 */
	private function resolve_tokens( int $customer_id, string $gateway ): array {
		$tokens = array();

		if ( '' !== $gateway && $customer_id > 0 && class_exists( '\WCS_Payment_Tokens' ) ) {
			$limit_filter = static function () {
				return 100;
			};

			add_filter( 'woocommerce_get_customer_payment_tokens_limit', $limit_filter );
			try {
				$tokens = WCS_Payment_Tokens::get_customer_tokens( $customer_id, $gateway );
			} finally {
				remove_filter( 'woocommerce_get_customer_payment_tokens_limit', $limit_filter );
			}
		}

		return $tokens;
	}

	protected function details_from( array $item ): array {
		$raw = $item['signal_summary'] ?? null;
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || empty( $decoded['details'] ) || ! is_array( $decoded['details'] ) ) {
			return array();
		}
		return $decoded['details'];
	}

	/**
	 * Extract the per-row signal flags from a candidate row's
	 * `signal_summary` JSON. Sibling to `details_from()`, but returns the
	 * `signals` array (e.g. `['has_token']`) used by cell renderers to
	 * decide whether to surface signal-specific UI like the
	 * Manual-with-token warning triangle on the Billing mode cell.
	 *
	 * @param array<string, mixed> $item Candidate row.
	 *
	 * @return string[]
	 */
	protected function signals_from( array $item ): array {
		$raw = $item['signal_summary'] ?? null;
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || empty( $decoded['signals'] ) || ! is_array( $decoded['signals'] ) ) {
			return array();
		}
		return array_values( array_filter( $decoded['signals'], 'is_string' ) );
	}

	/**
	 * Whether the Resolve action should be available for candidates.
	 *
	 * Disabled on staging/duplicate sites where automatic payments are
	 * locked to manual — remediation actions would have no effect and
	 * could confuse merchants reviewing a cloned environment.
	 *
	 * @return bool
	 */
	private function can_resolve(): bool {
		return ! \WCS_Staging::is_duplicate_site();
	}

	/**
	 * Render an inline warning-triangle icon with an accessible tooltip.
	 *
	 * Used to flag suspect rows (e.g. a manual subscription whose customer
	 * has a payment token, a past-due next-payment date with no matching
	 * renewal order). The icon carries the tooltip copy as `aria-label`
	 * for screen readers and as `data-tooltip` for the CSS bubble surfaced
	 * on hover or `:focus-visible`. The wrapper is `tabindex="0"` so a
	 * keyboard user can land on it. We deliberately omit a native `title`
	 * attribute — the browser bubble would race the CSS one and produce a
	 * double-tooltip.
	 *
	 * @param string $tooltip Plain-text tooltip copy.
	 *
	 * @return string
	 */
	private function render_warning_icon( string $tooltip ): string {
		$svg = '<svg class="woocommerce-subscriptions-health-check-warning-icon" width="14" height="14" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M12 3 L1.5 21.5 L22.5 21.5 Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M12 10 V14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="17" r="1" fill="currentColor"/></svg>';

		return sprintf(
			'<span class="woocommerce-subscriptions-health-check-warning" role="img" aria-label="%1$s" tabindex="0" data-tooltip="%1$s">%2$s</span>',
			esc_attr( $tooltip ),
			$svg
		);
	}

	private function human_time_since_mysql_utc( string $mysql_utc ): string {
		if ( '' === $mysql_utc ) {
			return (string) __( 'recently', 'woocommerce-subscriptions' );
		}
		$ts = strtotime( $mysql_utc . ' UTC' );
		if ( false === $ts ) {
			return (string) __( 'recently', 'woocommerce-subscriptions' );
		}
		return human_time_diff( $ts, time() );
	}
}
