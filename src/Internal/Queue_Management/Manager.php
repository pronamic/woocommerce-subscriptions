<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Queue_Management;

use WCS_Action_Scheduler;

/**
 * Top-level entry point for the queue-management subsystem.
 *
 * Two features live here, each composed of a Settings + a behaviour class:
 *
 *  - **Dedicated queue** — rotation-based queue scoping.
 *  - **External trigger** — REST endpoint that lets an external web cron service trigger a scoped queue run.
 *
 * See `README.md` in this directory for the subsystem's motivation and the cooperation model between the
 * collaborating classes.
 *
 * Plus two adjuncts that ride along with the dedicated-queue feature when it is enabled:
 *
 *  - **Queue isolator** — asserts an `exclude-groups` claim filter on regular queue runs so they skip
 *    subscription work. Coupled to the dedicated-queue feature: enabling "Dedicated processing"
 *    automatically engages it, because the two only make sense together (isolating subscription work from
 *    regular runs only makes sense when a dedicated path exists to process it instead).
 *  - **Concurrent batches booster** — raises Action Scheduler's allowed concurrent batches from 1 to 2 when
 *    the default has not been overridden. Lets a dedicated WCS turn proceed in parallel with a regular AS
 *    run rather than queueing behind it.
 *
 * Settings for both features are registered unconditionally so merchants always have a UI to opt in.
 * Each feature's behaviour class is registered only when the merchant has opted into that feature.
 *
 * The class is inert until {@see setup()} is called. The constructor takes no dependencies.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Manager {

	/**
	 * Identifier for the single WCS-default dedicated queue scope managed here. Used both as the scope name
	 * passed into {@see Dedicated_Queue} (which shows up in log entries and namespaces the counter option)
	 * and as the discriminator for the runtime enable filter, so this Manager only turns on its own scope.
	 */
	public const SCOPE_NAME = 'woocommerce-subscriptions';

	/**
	 * Wire up settings for both features unconditionally, and stand up each feature's behaviour class only
	 * when the merchant has opted into that feature.
	 *
	 * @return void
	 */
	public function setup(): void {
		( new Settings() )->setup();
		( new External_Trigger_Settings() )->setup();

		if ( $this->is_dedicated_queue_enabled() ) {
			$this->setup_dedicated_queue();
			$this->setup_queue_isolator();
			$this->setup_concurrent_batches_booster();
		}

		if ( $this->is_external_trigger_enabled() ) {
			$this->setup_external_trigger();
		}
	}

	/**
	 * Runtime callback for `wcs_dedicated_queue_enabled` that turns the enable gate on only for this scope.
	 * Other co-resident Dedicated_Queue instances (if any) see the existing value and are unaffected.
	 *
	 * Public because WordPress's hook system invokes it; not intended for direct consumption by other code.
	 *
	 * @param bool   $enabled Current enable-gate value as passed down the filter chain.
	 * @param string $name    The Dedicated_Queue instance asking whether it is enabled.
	 *
	 * @return bool
	 */
	public function filter_enable_for_our_scope( bool $enabled, string $name ): bool {
		return self::SCOPE_NAME === $name ? true : $enabled;
	}

	/**
	 * Whether the merchant has opted into the dedicated-queue feature.
	 *
	 * @return bool
	 */
	private function is_dedicated_queue_enabled(): bool {
		return 'yes' === get_option( Settings::OPTION_ENABLED, 'no' );
	}

	/**
	 * Whether the merchant has opted into the external-trigger endpoint.
	 *
	 * @return bool
	 */
	private function is_external_trigger_enabled(): bool {
		return 'yes' === get_option( External_Trigger_Settings::OPTION_ENABLED, 'no' );
	}

	/**
	 * Build the Dedicated_Queue from current option values and engage its hooks. The instance itself is not
	 * retained here — once `setup()` is called, the instance stays alive via its bound hook callbacks.
	 *
	 * @return void
	 */
	private function setup_dedicated_queue(): void {
		$queue = new Dedicated_Queue(
			self::SCOPE_NAME,
			array( WCS_Action_Scheduler::ACTION_GROUP ),
			( new Settings() )->get_effective_rotation()
		);

		// Dedicated_Queue's enable gate defaults to false. We've already confirmed the merchant has opted
		// in here, so flip the gate on for our scope only — preserving the gate's negative default for any
		// other co-resident scope someone else might register.
		add_filter( 'wcs_dedicated_queue_enabled', array( $this, 'filter_enable_for_our_scope' ), 10, 2 );

		$queue->setup();
	}

	/**
	 * Stand up the external-trigger REST endpoint, scoped to the same subscription group used by the
	 * dedicated-queue feature. The instance is not retained here — once `setup()` is called, it stays alive
	 * via its bound hook callbacks.
	 *
	 * @return void
	 */
	private function setup_external_trigger(): void {
		( new External_Trigger_Endpoint( array( WCS_Action_Scheduler::ACTION_GROUP ) ) )->setup();
	}

	/**
	 * Stand up the Queue_Isolator with the same subscription group scope as the other features. The instance
	 * is not retained here — once `setup()` is called, it stays alive via its bound hook callbacks.
	 *
	 * @return void
	 */
	private function setup_queue_isolator(): void {
		( new Queue_Isolator( array( WCS_Action_Scheduler::ACTION_GROUP ) ) )->setup();
	}

	/**
	 * Stand up the Concurrent_Batches_Booster. The instance is not retained here — once `setup()` is called,
	 * it stays alive via its bound filter callback.
	 *
	 * @return void
	 */
	private function setup_concurrent_batches_booster(): void {
		( new Concurrent_Batches_Booster() )->setup();
	}
}
