<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Queue_Management;

use ActionScheduler_Store;

/**
 * Isolates subscription work from regular Action Scheduler runs by asserting an `exclude-groups` claim filter
 * on the before-process-queue hook, so any run that would otherwise pick up subscription actions skips them.
 *
 * Conceptual pair to {@see Dedicated_Queue}: one isolates by *reserving* turns for subscription work, the
 * other isolates by *excluding* it from everything else. Together they keep subscription processing on its
 * own path. {@see Manager} couples this isolator to the "Dedicated processing" merchant setting:
 * enabling the dedicated queue automatically engages the isolator, because the two only make sense together.
 *
 * Cooperates cleanly with the dedicated-queue rotation and the external-trigger endpoint:
 *
 *  - Hooks at a priority *one step later* than Dedicated_Queue, so on a focus turn we observe the `group`
 *    filter Dedicated_Queue just set and defer (the don't-override-foreign-filters rule).
 *  - The external trigger sets the `group` filter from its shutdown handler before AS's `run_queue` fires;
 *    this isolator sees that filter on the resulting before-process-queue invocation and defers.
 *  - WP-CLI's `--group` / `--exclude-groups` flags pre-populate claim filters the same way; same defer.
 *
 * Inert until {@see setup()} is called. Constructor has no side effects.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Queue_Isolator {

	/**
	 * Hook priority. One step later than {@see Dedicated_Queue}'s 100 so Dedicated_Queue gets first crack
	 * at the run, and we defer to its focus-mode claim filter when one is set.
	 */
	private const HOOK_PRIORITY = 101;

	/**
	 * WC Logger source for diagnostic entries.
	 */
	private const LOG_SOURCE = 'woocommerce-subscriptions-queue-isolator';

	/**
	 * Action Scheduler group(s) to isolate from regular runs.
	 *
	 * @var string[]
	 */
	private array $groups;

	/**
	 * Whether the most recent before-process invocation set the exclude-groups claim filter. Drives the
	 * after-process cleanup decision.
	 *
	 * @var bool
	 */
	private bool $isolation_applied = false;

	/**
	 * @param string[] $groups Groups to isolate from regular queue runs. Typically a one-element array
	 *                         containing `WCS_Action_Scheduler::ACTION_GROUP`.
	 */
	public function __construct( array $groups ) {
		$this->groups = $groups;
	}

	/**
	 * Register the before/after-process-queue hook pair.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_action( 'action_scheduler_before_process_queue', array( $this, 'maybe_isolate' ), self::HOOK_PRIORITY );
		add_action( 'action_scheduler_after_process_queue', array( $this, 'maybe_clear' ), self::HOOK_PRIORITY );
	}

	/**
	 * Reverse {@see setup()} by removing the hooks. Subsequent queue runs are unaffected by this isolator
	 * until {@see setup()} is called again.
	 *
	 * @return void
	 */
	public function teardown(): void {
		remove_action( 'action_scheduler_before_process_queue', array( $this, 'maybe_isolate' ), self::HOOK_PRIORITY );
		remove_action( 'action_scheduler_after_process_queue', array( $this, 'maybe_clear' ), self::HOOK_PRIORITY );
	}

	/**
	 * Decide whether to isolate subscription work from this run. We apply only when:
	 *
	 *  - The store supports the claim-filter API (capability-gated).
	 *  - No foreign claim filter (`group`, `hooks`, `exclude-groups`) is already set — meaning neither
	 *    Dedicated_Queue, nor the external trigger, nor anything else has declared an intent for this run.
	 *
	 * @return void
	 */
	public function maybe_isolate(): void {
		$store = $this->get_capable_store();
		if ( null === $store ) {
			$this->log( 'Isolation not applied: active store does not support claim filtering.' );
			return;
		}

		$existing_filter = $this->find_existing_claim_filter( $store );
		if ( null !== $existing_filter ) {
			// Intra-WCS coordination: if the existing filter carries our own groups, it was set by
			// Dedicated_Queue (focus turn) or the external trigger (shutdown dispatch). The deferral is
			// expected — log nothing, to avoid noise that looks like external interference but isn't.
			if ( $this->is_our_own_filter( $existing_filter ) ) {
				return;
			}

			$this->log(
				sprintf(
					'Isolation deferred: %1$s claim filter already set to %2$s.',
					$existing_filter['name'],
					$this->format_filter_value( $existing_filter['value'] )
				)
			);
			return;
		}

		// @phpstan-ignore method.notFound (see earlier safety check using $this->get_capable_store())
		$store->set_claim_filter( 'exclude-groups', $this->groups );
		$this->isolation_applied = true;

		$this->log( sprintf( 'Isolation applied: regular run will skip groups %1$s.', implode( ',', $this->groups ) ) );
	}

	/**
	 * Best-effort cleanup of the exclude-groups claim filter we set in the matching before-process
	 * invocation. The filter is cleared only if its current value still matches what we set, so we don't
	 * clobber a value written by something else between the two hooks.
	 *
	 * @return void
	 */
	public function maybe_clear(): void {
		if ( ! $this->isolation_applied ) {
			return;
		}
		$this->isolation_applied = false;

		$store = $this->get_capable_store();
		if ( null === $store ) {
			return;
		}

		// @phpstan-ignore method.notFound (see earlier safety check using $this->get_capable_store())
		if ( $this->groups === $store->get_claim_filter( 'exclude-groups' ) ) {
			// @phpstan-ignore method.notFound (see earlier safety check using $this->get_capable_store())
			$store->set_claim_filter( 'exclude-groups', '' );
		}
	}

	/**
	 * Returns the active Action Scheduler store, but only if it exposes the claim-filter API. Otherwise null.
	 *
	 * Capability gate is method-based (`is_callable`) rather than class-coupled: any future store that
	 * adopts the same interface engages automatically.
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
	 * Look for a pre-populated claim filter on the store. Returns the offending name + value (or null).
	 *
	 * @param ActionScheduler_Store $store Capable store, as returned by {@see get_capable_store()}.
	 *
	 * @return array{name: string, value: mixed}|null
	 */
	private function find_existing_claim_filter( ActionScheduler_Store $store ): ?array {
		foreach ( array( 'group', 'hooks', 'exclude-groups' ) as $filter_name ) {
			// @phpstan-ignore method.notFound (see safety check using $this->get_capable_store() made in the calling method)
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
	 * Whether the supplied filter (as returned by {@see find_existing_claim_filter()}) carries this
	 * isolator's own groups. True only when the filter is `group` or `exclude-groups` AND its value matches
	 * `$this->groups` exactly. The `hooks` filter takes action-hook names rather than group slugs, so the
	 * shape can never equal a group list — it's always foreign by construction.
	 *
	 * @param array{name: string, value: mixed} $existing_filter
	 *
	 * @return bool
	 */
	private function is_our_own_filter( array $existing_filter ): bool {
		if ( ! in_array( $existing_filter['name'], array( 'group', 'exclude-groups' ), true ) ) {
			return false;
		}
		return $existing_filter['value'] === $this->groups;
	}

	/**
	 * Format a claim-filter value for inclusion in a log message. Arrays are comma-joined; scalars are cast
	 * to string. Mirrors the formatting in `Dedicated_Queue::format_outcome()` so operators see a consistent
	 * shape across the two log surfaces.
	 *
	 * @param mixed $value
	 *
	 * @return string
	 */
	private function format_filter_value( $value ): string {
		if ( is_array( $value ) ) {
			return implode( ',', $value );
		}
		return (string) $value;
	}

	/**
	 * Emit a debug-level entry to the WC logger.
	 *
	 * @param string $message
	 *
	 * @return void
	 */
	private function log( string $message ): void {
		wc_get_logger()->debug( $message, array( 'source' => self::LOG_SOURCE ) );
	}
}
