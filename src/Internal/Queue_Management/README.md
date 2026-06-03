# Queue Management

WooCommerce Subscriptions relies heavily on the Action Scheduler library in order to provide many of its services. 

However, the pipeline offered by that library is often shared with other plugins and, if there is a high degree of contention, then this can lead to unwarranted delays in terms of processing key subscription lifecycle events. The goal of the various classes in this namespace is therefore to offer a range of mitigations for this risk, all building on the Action Scheduler API.

This namespace acts as a set of building blocks that can be combined in varying ways and with different configurations. However, the corresponding settings UI is deliberately simplified to avoid overwhelming merchants with granular controls over a low-level system. 

## Background

WooCommerce Subscriptions already schedules its actions at AS priority `1` (versus the default of `10`). In a well-behaved ecosystem, the priority sort already places subscription work ahead of other plugins' default-priority actions. This subsystem is **defence-in-depth** for failure modes that priority alone does not cover:

- **Undisciplined priority use across the ecosystem.** Priority `1` is a unilateral choice; any plugin can claim it, and some do. Once two plugins share a priority tier, AS's tiebreaker keys decide arbitrarily, and the priority advantage collapses.
- **Long-running same-priority actions already in flight.** Priority decides who gets *claimed* into the next batch; it has no effect on what is already running. With AS's default `concurrent_batches = 1`, slow non-WCS actions can block the runner end-to-end.
- **Same-priority same-second floods.** A flood of non-WCS priority-1 actions due at the same instant defeats `priority ASC` entirely; the remaining tiebreaker keys decide arbitrarily.
- **Low-traffic stores and `DISABLE_WP_CRON`.** If WP-Cron is starved or disabled, subscription processing waits on whatever does drive the queue. The external-trigger endpoint gives the merchant a predictable, traffic-independent cadence.

## The pieces

All classes live under `Automattic\WooCommerce_Subscriptions\Internal\Queue_Management`. Each is inert until its `setup()` method is called.

### Orchestration
- **`Manager`** — the entry point. `setup()` always registers `Settings` and `External_Trigger_Settings` so merchants can opt in. Conditionally wires up `Dedicated_Queue` + `Queue_Isolator` + `Concurrent_Batches_Booster` when "Dedicated processing" is on, and `External_Trigger_Endpoint` when "Web cron support" is on. Bootstrap call:
  ```php
  ( new Manager() )->setup();
  ```

### Merchant-facing settings
- **`Settings`** — opens the "Processing reliability" section in WC > Settings > Subscriptions with an intro paragraph + the "Dedicated processing" checkbox. Exposes `Settings::FILTER_ROTATION` (`woocommerce_subscriptions_queue_rotation`) so code consumers can override the rotation interval; there is no merchant-facing dial.
- **`External_Trigger_Settings`** — appends the "Web cron support" checkbox, a read-only URL field, and a "Generate a new URL" affordance into the same section. Generates a token on first enable; handles the admin-post + nonce flow for regeneration.

### Processing channels
- **`Dedicated_Queue`** — rotation-based queue scoping. On every *N*th run, sets a `group` claim filter so that run claims only subscription actions. Hooks `action_scheduler_before_process_queue` at priority `100`. Default rotation is `3` (every third run is a focus turn).
- **`External_Trigger_Endpoint`** — REST route at `/wp-json/wc/v3/subscriptions/job-queue?wcs_token=<token>`. Accepts `GET`/`POST`/`PUT`. On a valid + non-rate-limited hit, returns `200 { "status": "dispatched" }` immediately and registers a `shutdown` callback that fires `action_scheduler_run_queue` with the subscription `group` filter set. Token-gated (courtesy, not authentication); rate-limited (default 60s, filterable).

### Supporting tools
- **`Queue_Isolator`** — asserts an `exclude-groups` claim filter on regular queue runs so the run skips subscription work. Hooks at priority `101` — one step later than `Dedicated_Queue` so it observes `Dedicated_Queue`'s focus-turn filter and defers silently. By default, this will be active whenever the dedicated queue is on, because isolating subscription work from regular runs only makes sense alongside a dedicated path to process it.
    - Note that if the merchant does not enable dedicated queues (the "Dedicated processing" setting) then queue isolation will not take place, *even if web cron support is enabled.* This is intentional: isolating subscription work from regular runs without a dedicated channel to process it would starve WCS work — `exclude-groups` says "skip this" with nothing on the other side to handle it. Coupling the two also keeps the settings surface simpler.
- **`Concurrent_Batches_Booster`** — raises AS's `action_scheduler_queue_runner_concurrent_batches` from its default of `1` to `2`. Hooks at priority `1000` so any earlier configuration wins — only the still-default value of `1` is bumped. The bump matters when a dedicated turn would otherwise have to queue behind an in-flight regular batch.

## How they cooperate

Two notable conventions:

- **Don't override foreign filters.** Each class checks `get_claim_filter('group' | 'hooks' | 'exclude-groups')` before acting; if any filter is already set by someone else, the class defers.
- **Don't log intra-WCS coordination.** If a filter is already set but its value matches our own scope, the class defers *silently* — that's expected hand-off between WCS components (`Dedicated_Queue` → `Queue_Isolator`, or external trigger → both). Logging it would mistake coordination for conflict.

The priority ordering is deliberate:

| Hook | Priority | What it does |
|---|---|---|
| `Dedicated_Queue::maybe_apply_scope` | 100 | On a focus turn: set `group` filter to our scope. Otherwise: defer. |
| `Queue_Isolator::maybe_isolate` | 101 | If no filter set: assert `exclude-groups`. If our own filter set: defer silently. If foreign filter set: defer + log. |

Three scenarios:

- **Regular run (not our turn).** Priority 100: no filter set. Priority 101: `Queue_Isolator` asserts `exclude-groups` against the subscriptions group. AS's claim query skips subscription actions; they stay queued for the next focus turn.
- **Focus turn (every Nth run).** Priority 100: `Dedicated_Queue` sets the `group` filter. Priority 101: `Queue_Isolator` sees an existing filter carrying our own groups and defers silently. AS's claim query takes only subscription actions.
- **External trigger.** The endpoint sets the `group` filter from its shutdown handler before firing `action_scheduler_run_queue`. When the resulting run reaches `before_process_queue`, both classes observe the pre-set filter and defer — `Dedicated_Queue`'s rotation counter does **not** advance for this run. External-trigger dispatches are additive; they don't consume rotation turns.

WP-CLI's `--group` / `--exclude-groups` flags populate the same filters, so the same deferral logic applies — no WP-CLI special-casing is needed.

## Diagnostics

Three log sources, all at WC's `debug` level:

| Source | Emitter | Shapes |
|---|---|---|
| `woocommerce-subscriptions-dedicated-queue` | `Dedicated_Queue` | *applied* / *blocked (foreign filter)* / *not yet (counter < rotation)* |
| `woocommerce-subscriptions-queue-isolator` | `Queue_Isolator` | *applied* / *deferred (foreign filter)* / *no capable store* |
| `woocommerce-subscriptions-external-trigger` | `External_Trigger_Endpoint` | *dispatched* / *rate-limited* / *invalid token* / *disabled* |

Every `Dedicated_Queue` entry is prefixed with `[scope=<colon-joined-groups>]` so multiple co-resident scopes (current or future) can be distinguished. The *blocked* / *deferred* shapes include the offending filter name and its value, so an operator chasing a "feature enabled, no observable effect" report has a breadcrumb.

## Extension points

| Filter | Type | Default | Purpose |
|---|---|---|---|
| `woocommerce_subscriptions_queue_rotation` | int | `3` (or stored option) | Override the dedicated-queue rotation interval. Clamped to `2..6`. |
| `wcs_dedicated_queue_enabled` | bool | `false` | Code-level opt-in. `Manager` flips this on for its own scope when the merchant has opted in via settings; mu-plugins can use it for force-on without touching options. |
| `wcs_external_trigger_rate_limit_window` | int | `60` | Seconds between accepted external-trigger hits. |
| `wcs_external_trigger_rate_limit_bypass` | bool | `false` | Disable the rate limit for the current request (per-request, not global). |
| `action_scheduler_queue_runner_concurrent_batches` | int | `1` (AS's value) | AS's own filter; `Concurrent_Batches_Booster` hooks it at priority `1000` and bumps `1` to `2`, leaving any other value alone. |

## Store-implementation safety

All three classes that touch claim filters — `Dedicated_Queue`, `Queue_Isolator`, and `External_Trigger_Endpoint` — gate their claim-filter calls on a capability check (`is_callable()` on `get_claim_filter()` / `set_claim_filter()`) rather than coupling to a specific store class. Any future store that adopts the same interface engages automatically.

Behaviour on an incapable store differs by class:

- **`Dedicated_Queue`** and **`Queue_Isolator`** no-op entirely — they emit a "no capable store" log entry and return without setting any filter.
- **`External_Trigger_Endpoint`** still dispatches the queue run, but skips the `group` filter. The resulting run is non-scoped: AS processes whatever is at the front of the queue rather than subscription work specifically. This is a deliberate degradation — the endpoint's contract is "run subscription work right now," and a non-scoped run is closer to that contract than no run at all.

As of writing, only `ActionScheduler_DBStore` exposes the claim-filter API. Sites running on alternative stores still see the merchant settings UI but observe the degraded behaviours above at the queue layer.
