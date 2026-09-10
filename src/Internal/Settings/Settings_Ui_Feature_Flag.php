<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Settings;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Admin\Features\Features;

/**
 * Default {@see Feature_Flag}: reports the experimental React (settings-ui) renderer as active.
 *
 * The redesigned Subscriptions settings screen ships to every store on the classic PHP renderer and
 * is not gated by this flag. This flag gates only the experimental React renderer built on
 * WooCommerce's settings-ui SDK, which is parked in-tree until that SDK stabilizes. It is a
 * code-only opt-in, off by default: activating it requires both Core's `settings-ui` feature and
 * `define( 'WCS_EXPERIMENTAL_MODERN_SETTINGS_UI', 'ENABLE_WHILE_UNSTABLE' )`. The opt-in is a
 * sentinel string, not a boolean, so enabling it is a deliberate acknowledgement that the renderer
 * is experimental and may change or break without notice.
 *
 * @internal
 */
class Settings_Ui_Feature_Flag implements Feature_Flag {
	/**
	 * Whether the experimental React settings renderer is active for the current request.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		// The experimental renderer is a code-only opt-in. The value is a sentinel string rather than a
		// boolean so that enabling it is a deliberate, eyes-open act: a stray `true`/`1` or even `'off'`
		// cannot switch it on - the value must name the acknowledgement exactly.
		if ( 'ENABLE_WHILE_UNSTABLE' !== Constants::get_constant( 'WCS_EXPERIMENTAL_MODERN_SETTINGS_UI' ) ) {
			return false;
		}

		return class_exists( Features::class ) && Features::is_enabled( 'settings-ui' );
	}
}
