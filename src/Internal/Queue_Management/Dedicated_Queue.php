<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Queue_Management;

use ActionScheduler_Store;

/**
 * Represents a single dedicated queue scope for scheduled actions.
 *
 * Each instance is a named registration that, on every Nth invocation of the Action Scheduler queue runner, narrows
 * that run to a designated set of Action Scheduler groups. Correspondingly, it can also be configured to try and remove
 * those same groups from 'regular' queue runs.
 *
 * One instance corresponds to one registered scope. The class is inert until {@see setup()} is called: the
 * constructor only captures dependencies and never adds hooks or otherwise reaches into WordPress / Action
 * Scheduler.
 *
 * See `README.md` in this directory for the subsystem's motivation and the cooperation model with the other
 * Queue_Management classes.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Dedicated_Queue {

	use Resolves_Existing_Groups;

	/**
	 * Prefix for the per-scope option key that persists the turn counter. Suffixed with `$this->name`.
	 */
	private const COUNTER_OPTION_PREFIX = 'wcs_dedicated_queue_counter_';

	/**
	 * Late hook priority so any other code with scoping intent (e.g. the WP-CLI command, another plugin) gets to
	 * populate claim filters before we look at them.
	 */
	private const HOOK_PRIORITY = 100;

	/**
	 * Identifier for this scope. Used to namespace per-scope state (e.g. the turn counter option key).
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Action Scheduler group(s) this scope claims a share of runs for.
	 *
	 * @var string[]
	 */
	private array $groups;

	/**
	 * Rescope every Nth run of the queue runner to this scope. A value of 2 means "every other run".
	 *
	 * @var int
	 */
	private int $rotation;

	/**
	 * Whether the most recent before-process invocation set the `group` claim filter for this scope. Drives
	 * the after-process cleanup decision: if we did not set it, we have nothing to clean up. Reset
	 * unconditionally at the start of each cleanup so subsequent runs start from a known baseline.
	 *
	 * @var bool
	 */
	private bool $filter_applied = false;

	/**
	 * @param string   $name     Identifier for this scope.
	 * @param string[] $groups   Action Scheduler group(s) to scope rescoped runs to.
	 * @param int      $rotation Rescope every Nth run. Defaults to 2.
	 */
	public function __construct( string $name, array $groups, int $rotation = 2 ) {
		$this->name     = $name;
		$this->groups   = $groups;
		$this->rotation = $rotation;
	}

	/**
	 * Integrates this dedicated queue with Action Scheduler by registering the listener pair that decides whether
	 * to rescope each run.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_action( 'action_scheduler_before_process_queue', array( $this, 'maybe_apply_scope' ), self::HOOK_PRIORITY );
		add_action( 'action_scheduler_after_process_queue', array( $this, 'maybe_clear_scope' ), self::HOOK_PRIORITY );
	}

	/**
	 * Reverses {@see setup()} by removing the listener pair. The class becomes inert again; subsequent queue
	 * runs are unaffected by this scope until {@see setup()} is called once more.
	 *
	 * @return void
	 */
	public function teardown(): void {
		remove_action( 'action_scheduler_before_process_queue', array( $this, 'maybe_apply_scope' ), self::HOOK_PRIORITY );
		remove_action( 'action_scheduler_after_process_queue', array( $this, 'maybe_clear_scope' ), self::HOOK_PRIORITY );
	}

	/**
	 * Decide whether the current queue run should be rescoped to this dedicated queue's group(s); apply if so.
	 *
	 * Applies only when all gates pass: feature is enabled, the active store exposes the claim-filter API, no
	 * pre-existing claim filters are populated, and the turn counter has reached the configured rotation. The
	 * counter advances on every non-deferred run and resets on the run we apply. Runs deferred because of a
	 * foreign claim filter do not consume a turn. Each invocation that gets past the enable check emits
	 * exactly one debug log entry capturing the outcome.
	 *
	 * Isolating subscription work from non-rescoped runs (by asserting an `exclude-groups` filter) is the
	 * job of {@see Queue_Isolator}, not this class. This class only ever sets the `group` filter.
	 *
	 * @return void
	 */
	public function maybe_apply_scope(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$store = $this->get_capable_store();
		if ( null === $store ) {
			$this->log(
				sprintf(
					'Dedicated queue runner "%1$s" could not be created: active store does not support claim filtering.',
					$this->name
				)
			);
			return;
		}

		// A `group` claim for a slug that has never been used makes Action Scheduler throw when it resolves the
		// slug at claim time, aborting the entire focus run. Skip without consuming a rotation turn (return
		// before the counter is touched), so we apply the scope on a later run once the group exists. See
		// Resolves_Existing_Groups for the full rationale.
		if ( empty( $this->existing_groups( $this->groups ) ) ) {
			$this->log( sprintf( 'Dedicated queue runner "%1$s" not applied: none of its groups exist yet.', $this->name ) );
			return;
		}

		$existing_filter = $this->find_existing_claim_filter( $store );
		$cycle           = $this->read_counter() + 1;
		$applied         = false;

		if ( null === $existing_filter ) {
			if ( $cycle >= $this->rotation ) {
				// @phpstan-ignore method.notFound (see earlier safety check using $this->get_capable_store())
				$store->set_claim_filter( 'group', $this->groups );
				$this->filter_applied = true;
				$applied              = true;
				$this->write_counter( 0 );
			} else {
				$this->write_counter( $cycle );
			}
		}

		$this->log( $this->format_outcome( $existing_filter, $cycle, $applied ) );
	}

	/**
	 * Best-effort cleanup of the claim filter we set in the matching before-process invocation.
	 *
	 * The filter is cleared only if its current value still matches what we set, so we do not clobber a value
	 * written by something else between the two hooks. The `$applied_filter` field is reset unconditionally so
	 * a subsequent run starts from a clean slate.
	 *
	 * @return void
	 */
	public function maybe_clear_scope(): void {
		if ( ! $this->filter_applied ) {
			return;
		}
		$this->filter_applied = false;

		$store = $this->get_capable_store();

		// If $store is null, then it does not support setting/getting claim filters.
		if ( null === $store ) {
			return;
		}

		// @phpstan-ignore method.notFound (see earlier safety check using $this->get_capable_store())
		if ( $this->groups === $store->get_claim_filter( 'group' ) ) {
			// @phpstan-ignore method.notFound (see earlier safety check using $this->get_capable_store())
			$store->set_claim_filter( 'group', '' );
		}
	}

	/**
	 * Whether the feature is enabled for this scope. Default `false` (opt-in).
	 *
	 * @return bool
	 */
	private function is_enabled(): bool {
		/**
		 * Filter the enabled state of dedicated Action Scheduler queues. Default `false` — opt in by returning
		 * `true`. Receives the scope name and groups so a single filter callback can make per-scope decisions.
		 *
		 * @since 8.8.0
		 *
		 * @param bool     $enabled Whether the dedicated queue mechanism is enabled. Default false.
		 * @param string   $name    Scope identifier.
		 * @param string[] $groups  Scope's Action Scheduler groups.
		 */
		return (bool) apply_filters( 'wcs_dedicated_queue_enabled', false, $this->name, $this->groups );
	}

	/**
	 * Returns the active Action Scheduler store, but only if it exposes the claim-filter API. Otherwise null.
	 *
	 * The capability gate is method-based (`is_callable`) rather than class-based: any future store that adopts
	 * the same interface engages automatically.
	 *
	 * @return ActionScheduler_Store|null
	 */
	private function get_capable_store(): ?ActionScheduler_Store {
		$store = ActionScheduler_Store::instance();

		if ( ! is_callable( array( $store, 'get_claim_filter' ) ) || ! is_callable( array( $store, 'set_claim_filter' ) ) ) {
			return null;
		}

		return $store;
	}

	/**
	 * Locate the first pre-populated claim filter on the store, if any. The three known filters (`group`,
	 * `hooks`, `exclude-groups`) are checked in turn; the first one with a non-empty value is returned.
	 *
	 * If anything comes back, someone (the WP-CLI command, another plugin, an earlier-priority listener) has
	 * already declared an intent for this run, and we must defer. Returning the filter name/value (rather than a
	 * bool) lets the caller include diagnostic detail in the outcome log.
	 *
	 * @param ActionScheduler_Store $store Capable store, as returned by {@see get_capable_store()}.
	 *
	 * @return array{name: string, value: mixed}|null
	 */
	private function find_existing_claim_filter( ActionScheduler_Store $store ): ?array {
		foreach ( array( 'group', 'hooks', 'exclude-groups' ) as $filter_name ) {
			// @phpstan-ignore method.notFound (see safety check made in the calling method using $this->get_capable_store())
			$value = $store->get_claim_filter( $filter_name );
			if ( ! empty( $value ) ) {
				return array(
					'name'  => $filter_name,
					'value' => $value,
				);
			}
		}

		return null;
	}

	/**
	 * Build the outcome line for a {@see maybe_apply_scope()} invocation that got past the enable and
	 * capable-store gates. Three shapes:
	 *
	 *  - Applied: "Dedicated queue runner "scope-name" created. Cycle: 2/2."
	 *  - Blocked: "Dedicated queue runner "scope-name" could not be created. Existing claim: group=foo. Cycle: 2/2."
	 *  - Not yet: "Dedicated queue runner "scope-name" could not be created. Existing claim: none. Cycle: 1/2."
	 *
	 * `$cycle` reflects the in-memory turn value for the run (i.e. what would have been written had we
	 * proceeded), which keeps the log meaningful for both deferred and pre-rotation outcomes.
	 *
	 * @param array{name: string, value: mixed}|null $existing_filter Pre-set claim filter, or null if none.
	 * @param int                                    $cycle           Turn counter as observed in this run.
	 * @param bool                                   $applied         Whether the scope was applied this run.
	 *
	 * @return string
	 */
	private function format_outcome( ?array $existing_filter, int $cycle, bool $applied ): string {
		if ( $applied ) {
			return sprintf( 'Dedicated queue runner "%1$s" created. Cycle: %2$d/%3$d.', $this->name, $cycle, $this->rotation );
		}

		$claim = 'none';
		if ( null !== $existing_filter ) {
			$value = is_array( $existing_filter['value'] )
				? implode( ',', $existing_filter['value'] )
				: (string) $existing_filter['value'];
			$claim = sprintf( '%s=%s', $existing_filter['name'], $value );
		}

		return sprintf(
			'Dedicated queue runner "%1$s" could not be created. Existing claim: %2$s. Cycle: %3$d/%4$d.',
			$this->name,
			$claim,
			$cycle,
			$this->rotation
		);
	}

	/**
	 * Emit a debug-level entry to the WooCommerce logger, prefixed with this scope's identifier (a
	 * colon-concatenated list of group names) so multiple co-resident Dedicated_Queue instances can be told
	 * apart in the log.
	 *
	 * @param string $message Pre-formatted message body.
	 *
	 * @return void
	 */
	private function log( string $message ): void {
		wc_get_logger()->debug(
			sprintf( '[scope=%s] %s', implode( ':', $this->groups ), $message ),
			array( 'source' => 'woocommerce-subscriptions-dedicated-queue' )
		);
	}

	/**
	 * Read the persisted turn counter for this scope.
	 *
	 * @return int
	 */
	private function read_counter(): int {
		return (int) get_option( self::COUNTER_OPTION_PREFIX . $this->name, 0 );
	}

	/**
	 * Persist the turn counter for this scope. Stored as a non-autoloaded option to keep the autoload payload
	 * lean — the value is only consulted on queue-runner invocations.
	 *
	 * @param int $counter The new counter value.
	 *
	 * @return void
	 */
	private function write_counter( int $counter ): void {
		update_option( self::COUNTER_OPTION_PREFIX . $this->name, $counter, false );
	}
}
