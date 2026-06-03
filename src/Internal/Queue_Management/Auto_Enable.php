<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Queue_Management;

/**
 * Decides whether the dedicated-queue feature ({@see Settings::OPTION_ENABLED}) can be safely auto-enabled on a
 * given store, and (when every probe agrees) flips the option to `'yes'`.
 *
 * Designed for one-shot invocation from the plugin upgrade routine. Composed of small probes — each one a
 * single conservative signal that the site is in a pristine, stock configuration. The composite
 * {@see maybe_enable()} ANDs the probes together: if even one says "this site has been tuned," the
 * auto-enable bails. The intent is to err on the side of *not* auto-enabling: the setting remains one click
 * away, and the cost of auto-enabling onto a hand-tuned store is much higher than the cost of leaving a stock
 * store with the feature off.
 *
 * Returns a structured outcome rather than logging directly so callers in different surfaces (legacy upgrade
 * shim, future CLI tool, debug tool, etc.) can phrase the breadcrumb in their own log.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Auto_Enable {

	/**
	 * Class-name and namespace prefixes we treat as "ours" (Action Scheduler — which we vendor and ship —
	 * and WooCommerce Subscriptions itself) when classifying callbacks hooked into the AS hooks we probe.
	 * Callbacks matching one of these prefixes are not treated as a tuning signal; everything else is.
	 *
	 * Deliberately narrow: WooCommerce core and other sister-team plugins are *not* on this list. If a sister
	 * team independently introduces a system that hooks AS, the auto-enable should bail — their tuning is
	 * legitimately a sign of an environment we should leave alone, just like a third-party plugin's tuning.
	 *
	 * Each entry exists for a concrete reason:
	 *   - `ActionScheduler`                            — {@see \ActionScheduler_QueueRunner::run} on `action_scheduler_run_queue`.
	 *   - `WC_Subscriptions`                           — {@see \WC_Subscriptions_Core_Plugin::reduce_multisite_action_scheduler_batch_size} hooks `action_scheduler_queue_runner_batch_size` unconditionally on boot.
	 *   - `WCS_`                                       — defensive: legacy WCS-prefixed classes are ours and may hook these in future.
	 *   - `Automattic\WooCommerce_Subscriptions\`      — defensive: modern WCS code in our own namespace.
	 */
	private const INTERNAL_CALLBACK_PREFIXES = array(
		'ActionScheduler',
		'WCS_',
		'WC_Subscriptions',
		'Automattic\\WooCommerce_Subscriptions\\',
	);

	/**
	 * Inspect the environment and, if every probe reports a pristine stock configuration, flip the
	 * dedicated-queue option to `'yes'`.
	 *
	 * Returns the outcome:
	 *  - `enabled`: whether the option was just flipped to `'yes'` by this call.
	 *  - `reason`:  the disqualifying signal (when not enabled), or a confirmation string (when enabled).
	 *
	 * @return array{enabled: bool, reason: string}
	 */
	public function maybe_enable(): array {
		$reason = $this->find_disqualifying_signal();

		if ( null !== $reason ) {
			return array(
				'enabled' => false,
				'reason'  => $reason,
			);
		}

		update_option( Settings::OPTION_ENABLED, 'yes' );

		return array(
			'enabled' => true,
			'reason'  => 'all probes pristine',
		);
	}

	/**
	 * Walk the probes in order and return the first disqualifying signal, or null if every probe is pristine.
	 *
	 * Order is informative-first: cheap option checks before more expensive filter / global-state inspection,
	 * and short-circuit reasons ("already enabled") before tuning-detection reasons. Each branch returns its
	 * own reason string so callers can surface a useful breadcrumb.
	 *
	 * Public for testability — callers should normally use {@see maybe_enable()}.
	 *
	 * @return string|null
	 */
	public function find_disqualifying_signal(): ?string {
		if ( $this->is_dedicated_queue_already_enabled() ) {
			return 'dedicated queue option already enabled';
		}
		if ( $this->is_external_trigger_already_enabled() ) {
			return 'external trigger option already enabled';
		}
		if ( ! $this->has_expected_store() ) {
			return 'non-stock Action Scheduler store class';
		}
		if ( ! $this->is_concurrent_batches_filter_pristine() ) {
			return 'action_scheduler_queue_runner_concurrent_batches has been filtered';
		}
		if ( ! $this->is_batch_size_filter_pristine() ) {
			return 'action_scheduler_queue_runner_batch_size has been filtered';
		}
		if ( ! $this->is_run_queue_action_pristine() ) {
			return 'action_scheduler_run_queue has non-AS-internal callbacks attached';
		}

		return null;
	}

	/**
	 * Short-circuit: the feature is already on, so no further change is needed regardless of any other signal.
	 *
	 * @return bool
	 */
	private function is_dedicated_queue_already_enabled(): bool {
		return 'yes' === get_option( Settings::OPTION_ENABLED, 'no' );
	}

	/**
	 * Short-circuit: the merchant has already engaged with the subsystem by enabling the external trigger. We
	 * don't want to silently flip an adjacent toggle on top of a settings choice they've consciously made.
	 *
	 * @return bool
	 */
	private function is_external_trigger_already_enabled(): bool {
		return 'yes' === get_option( External_Trigger_Settings::OPTION_ENABLED, 'no' );
	}

	/**
	 * Whether the active Action Scheduler store is exactly {@see \ActionScheduler_DBStore} — not a subclass,
	 * not a replacement. A subclass might satisfy the runtime capability gate but still represents a non-stock
	 * site we don't want to silently tune.
	 *
	 * @return bool
	 */
	private function has_expected_store(): bool {
		if ( ! class_exists( '\ActionScheduler', false ) || ! class_exists( '\ActionScheduler_DBStore', false ) ) {
			return false;
		}

		return \ActionScheduler_DBStore::class === $this->get_active_store_class();
	}

	/**
	 * Returns the fully-qualified class name of the active Action Scheduler store, or `''` if unavailable.
	 *
	 * Carved out as a protected seam so tests can override it without having to wrangle AS's singleton.
	 *
	 * @return string
	 */
	protected function get_active_store_class(): string {
		$store = \ActionScheduler::store();

		return is_object( $store ) ? get_class( $store ) : '';
	}

	/**
	 * Pristine when no foreign callback subscribes to the concurrent-batches filter. Stack callbacks (AS, WC,
	 * WCS itself) are skipped — their presence is a normal stack-boot artefact, not a sign of merchant tuning.
	 *
	 * @return bool
	 */
	private function is_concurrent_batches_filter_pristine(): bool {
		return ! $this->hook_has_foreign_callback( 'action_scheduler_queue_runner_concurrent_batches' );
	}

	/**
	 * Pristine when no foreign callback subscribes to the batch-size filter. Same shape as the
	 * concurrent-batches probe — and note that WCS itself hooks this filter at boot (see
	 * {@see \WC_Subscriptions_Core_Plugin::reduce_multisite_action_scheduler_batch_size}), so a naive
	 * {@see has_filter()} check would never see a pristine site.
	 *
	 * @return bool
	 */
	private function is_batch_size_filter_pristine(): bool {
		return ! $this->hook_has_foreign_callback( 'action_scheduler_queue_runner_batch_size' );
	}

	/**
	 * Pristine when no foreign callback subscribes to `action_scheduler_run_queue`. AS hooks its own
	 * {@see \ActionScheduler_QueueRunner::run} at default priority, so a flat {@see has_action()} would always
	 * be truthy on a stock install — we need to walk the registered callbacks and filter the stack's out.
	 *
	 * @return bool
	 */
	private function is_run_queue_action_pristine(): bool {
		return ! $this->hook_has_foreign_callback( 'action_scheduler_run_queue' );
	}

	/**
	 * Walk the registered callbacks for the given hook and return true if any of them is foreign — i.e. not
	 * matched by {@see is_internal_callback()}. Hooks with no subscribers (or no `$wp_filter` entry at all)
	 * return false, since there's nothing foreign to find.
	 *
	 * @param string $hook_name Hook name to inspect.
	 *
	 * @return bool
	 */
	private function hook_has_foreign_callback( string $hook_name ): bool {
		global $wp_filter;

		$hook = $wp_filter[ $hook_name ] ?? null;
		if ( ! is_object( $hook ) || empty( $hook->callbacks ) ) {
			return false;
		}

		foreach ( $hook->callbacks as $callbacks_at_priority ) {
			foreach ( $callbacks_at_priority as $callback ) {
				if ( ! $this->is_internal_callback( $callback['function'] ?? null ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Classify a single callback entry from `$wp_filter` as belonging to us (Action Scheduler or WooCommerce
	 * Subscriptions itself) or to foreign code. Class-method callbacks are classified by their target class
	 * name; plain function-name callbacks by the function name itself. Closures and other anonymous callables
	 * are always classified as foreign: neither AS nor WCS uses closures for persistent hook subscriptions,
	 * so any closure on these hooks is third-party code.
	 *
	 * Protected for testability — exercised directly by the unit tests so the classification rules are pinned
	 * down without having to construct stack callbacks in `$wp_filter`.
	 *
	 * @param mixed $function_ref The `function` entry from $wp_filter's callback record.
	 *
	 * @return bool
	 */
	protected function is_internal_callback( $function_ref ): bool {
		if ( is_array( $function_ref ) && isset( $function_ref[0] ) ) {
			$target = is_object( $function_ref[0] ) ? get_class( $function_ref[0] ) : $function_ref[0];
			return is_string( $target ) && $this->matches_internal_prefix( $target );
		}

		if ( is_string( $function_ref ) ) {
			return $this->matches_internal_prefix( $function_ref );
		}

		return false;
	}

	/**
	 * Whether the supplied class or function name starts with any of {@see INTERNAL_CALLBACK_PREFIXES}.
	 *
	 * @param string $identifier Class FQCN or function name.
	 *
	 * @return bool
	 */
	private function matches_internal_prefix( string $identifier ): bool {
		foreach ( self::INTERNAL_CALLBACK_PREFIXES as $prefix ) {
			if ( 0 === strpos( $identifier, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
