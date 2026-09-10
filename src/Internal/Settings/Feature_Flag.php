<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Settings;

/**
 * Describes whether the experimental React (settings-ui) settings renderer is active.
 *
 * The seam fulfilled by {@see Settings_Ui_Feature_Flag}, the production gate. Kept as an interface
 * for future-proofing: call sites currently instantiate the concrete flag inline (there is no
 * injection point today - tests exercise flag-dependent behaviour via the constant + feature-config
 * filter), but the interface preserves the option of substituting an alternative gate without
 * changing consumers.
 *
 * @internal
 */
interface Feature_Flag {
	/**
	 * Whether the modern settings experience is active for the current request.
	 *
	 * @return bool
	 */
	public function is_active(): bool;
}
