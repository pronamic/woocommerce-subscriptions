<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Queue_Management;

/**
 * Registers and renders the merchant-facing settings for the External Trigger Endpoint feature.
 *
 * Appends into the unified "Processing reliability" section opened by {@see Settings}: emits the web-cron rows
 * and the closing sectionend, but no title of its own. The shared section id used to bracket the section is
 * {@see Settings::SECTION_ID}; this class' own SECTION_ID is retained only for internal field-id derivation
 * (the custom URL field's `id`).
 *
 * Like {@see Settings}, this is intentionally narrow: it owns the form definition, the server-side
 * token-generation hook, and the custom-field renderer for the URL display. It does not register the REST
 * route, dispatch any queue run, or read the rate-limit state — those concerns live in
 * {@see External_Trigger_Endpoint} and {@see External_Trigger_Manager}.
 *
 * The public option-key constants are the contract those classes consume.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class External_Trigger_Settings {

	/**
	 * Option key — checkbox controlling whether the external trigger endpoint is active. Defaults to `'no'`.
	 */
	public const OPTION_ENABLED = 'woocommerce_subscriptions_external_trigger_enabled';

	/**
	 * Option key — the secret token that callers must supply in the `wcs_token` query parameter. Auto-generated
	 * on first enable; the merchant never types it.
	 */
	public const OPTION_TOKEN = 'woocommerce_subscriptions_external_trigger_token';

	/**
	 * Option key — Unix timestamp of the most recent successful dispatch. Internal state used by the rate
	 * limiter; not surfaced in the settings form.
	 */
	public const OPTION_LAST_DISPATCH = 'woocommerce_subscriptions_external_trigger_last_dispatch';

	/**
	 * Custom WC settings field type used to render the read-only URL row. Registered as a
	 * `woocommerce_admin_field_<type>` action hook.
	 */
	private const FIELD_TYPE_URL = 'wcs_external_trigger_url';

	/**
	 * Admin-post action name for the "Generate a new URL" link. Reused as the nonce action name and the success
	 * query-arg name. Public so tests and external callers can construct equivalent URLs / verifiers.
	 */
	public const REGENERATE_ACTION = 'wcs_regenerate_external_trigger_token';

	/**
	 * Query parameter set on the redirect after a successful regeneration. Drives the admin-notice render.
	 */
	private const REGENERATED_NOTICE_FLAG = 'wcs_external_token_regenerated';

	/**
	 * Internal identifier used to derive the custom URL field's `id`. Not used for the section title/sectionend
	 * bracket — those use {@see Settings::SECTION_ID} so this class' rows fall inside the shared section.
	 */
	private const SECTION_ID = 'woocommerce_subscriptions_external_trigger_options';

	/**
	 * Hook priority for the settings filter. One step higher than {@see Settings::SETTINGS_PRIORITY} (1000) so
	 * this class' rows append into the same unified section after the dedicated-queue rows.
	 */
	private const SETTINGS_PRIORITY = 1001;

	/**
	 * Number of bytes of randomness used when generating a token. 32 alphanumeric characters from
	 * {@see wp_generate_password()} gives ~190 bits of entropy — comfortably more than enough for a
	 * not-quite-auth URL secret.
	 */
	private const TOKEN_LENGTH = 32;

	/**
	 * Register the settings filter, the custom-field renderer for the URL display, and the post-save hook that
	 * generates a token the first time the feature is enabled.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_filter( 'woocommerce_subscription_settings', array( $this, 'add_settings' ), self::SETTINGS_PRIORITY );
		add_action( 'woocommerce_admin_field_' . self::FIELD_TYPE_URL, array( $this, 'render_url_field' ) );
		add_action( 'update_option_' . self::OPTION_ENABLED, array( $this, 'maybe_generate_token' ), 10, 2 );
		add_action( 'admin_post_' . self::REGENERATE_ACTION, array( $this, 'handle_token_regeneration_request' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_token_notice' ) );
	}

	/**
	 * Append the web-cron rows (plus the closing sectionend) into the unified Processing reliability section
	 * opened by {@see Settings::add_settings()}. No title row is emitted here: the shared section is already
	 * open by the time this filter callback runs.
	 *
	 * @param array<int, array<string, mixed>> $settings Existing settings array.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function add_settings( array $settings ): array {
		$section = array(
			array(
				'id'      => self::OPTION_ENABLED,
				'name'    => __( 'Web cron support', 'woocommerce-subscriptions' ),
				'desc'    => __( 'Allow a web cron service to run pending subscription events.', 'woocommerce-subscriptions' ),
				'default' => 'no',
				'type'    => 'checkbox',
			),
			array(
				'name' => __( 'Web cron URL', 'woocommerce-subscriptions' ),
				'desc' => __( 'Add this URL to your web cron service. Treat it like a password — keep it private and don\'t share it publicly.', 'woocommerce-subscriptions' ),
				'type' => self::FIELD_TYPE_URL,
				'id'   => self::SECTION_ID . '_url_display',
			),
			array(
				'type' => 'sectionend',
				'id'   => Settings::SECTION_ID,
			),
		);

		return array_merge( $settings, $section );
	}

	/**
	 * Render the URL row. With a token, shows the read-only copyable URL plus a "Generate a new URL" button.
	 * Without one (feature enabled but not yet saved), shows an info notice prompting the merchant to save.
	 *
	 * The field id is carried in both states so the admin.js show/hide toggle — which finds this row by that
	 * id and reveals it only when the "Web cron support" checkbox is checked — keeps working.
	 *
	 * @param array<string, mixed> $field WC settings field config as passed by WC's settings renderer.
	 *
	 * @return void
	 */
	public function render_url_field( array $field ): void {
		$token    = (string) get_option( self::OPTION_TOKEN, '' );
		$field_id = isset( $field['id'] ) ? (string) $field['id'] : '';

		if ( '' === $token ) {
			// No `forminp` class on the cell: WC styles `.forminp` as display:block, which collapses the
			// colspan and shrinks the notice. A bare table-cell lets the colspan span the full settings width.
			// Zero the cell's horizontal padding so the notice sits flush with the page's other admin notices.
			?>
			<tr valign="top">
				<td colspan="2" style="padding-left: 0; padding-right: 0;">
					<div <?php echo '' !== $field_id ? 'id="' . esc_attr( $field_id ) . '"' : ''; ?> class="components-notice is-info">
						<div class="components-notice__content">
							<?php esc_html_e( 'Save your changes to generate your web cron URL.', 'woocommerce-subscriptions' ); ?>
						</div>
					</div>
				</td>
			</tr>
			<?php
			return;
		}

		$url            = $this->build_url( $token );
		$regenerate_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::REGENERATE_ACTION ),
			self::REGENERATE_ACTION
		);
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label <?php echo '' !== $field_id ? 'for="' . esc_attr( $field_id ) . '"' : ''; ?>><?php echo esc_html( $field['name'] ?? '' ); ?></label>
			</th>
			<td class="forminp">
				<input
					<?php echo '' !== $field_id ? 'id="' . esc_attr( $field_id ) . '"' : ''; ?>
					type="text"
					readonly
					value="<?php echo esc_attr( $url ); ?>"
					class="regular-text code"
					style="width: 100%; max-width: 600px;"
					onfocus="this.select();"
				/>
				<p>
					<a
						href="<?php echo esc_url( $regenerate_url ); ?>"
						class="button"
					><?php esc_html_e( 'Generate a new URL', 'woocommerce-subscriptions' ); ?></a>
				</p>
				<?php if ( ! empty( $field['desc'] ) ) : ?>
					<p class="description"><?php echo wp_kses_post( $field['desc'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Generate and persist a fresh token when the feature transitions to enabled and no token exists yet.
	 *
	 * Tied to the `update_option_<enabled>` hook so it fires only on a settings save that flips the state.
	 * Idempotent across repeated enables: once a token is set it sticks until a future "regenerate" affordance
	 * (out of scope for v1) explicitly clears it.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 *
	 * @return void
	 */
	public function maybe_generate_token( $old_value, $new_value ): void {
		if ( 'yes' !== $new_value ) {
			return;
		}
		if ( '' !== (string) get_option( self::OPTION_TOKEN, '' ) ) {
			return;
		}

		update_option( self::OPTION_TOKEN, wp_generate_password( self::TOKEN_LENGTH, false ) );
	}

	/**
	 * Compose the full endpoint URL with the supplied token. Exposed so tests can build the expected URL
	 * without duplicating the route shape.
	 *
	 * @param string $token Token value.
	 *
	 * @return string
	 */
	public function build_url( string $token ): string {
		return add_query_arg( 'wcs_token', $token, rest_url( 'wc/v3/subscriptions/job-queue' ) );
	}

	/**
	 * Replace the stored token with a freshly generated one and reset the rate-limit clock.
	 *
	 * Resetting the clock is deliberate: a merchant who rotates the token is typically about to update the
	 * external service and immediately test the new URL, and being rate-limited on the first test ping after
	 * rotation is a frustrating UX trap. Resetting also limits the "abuse via repeated regenerate-and-trigger"
	 * concern to admins, who already have other ways to force queue runs anyway.
	 *
	 * Exposed (public) for tests and for any future code-level rotation paths (e.g. a WP-CLI command).
	 *
	 * @return void
	 */
	public function regenerate_token(): void {
		update_option( self::OPTION_TOKEN, wp_generate_password( self::TOKEN_LENGTH, false ) );
		delete_option( self::OPTION_LAST_DISPATCH );
	}

	/**
	 * Admin-post handler for the "Generate a new URL" link. Gated on the `manage_woocommerce` capability and a
	 * nonce; on success, regenerates the token and redirects back to the referring page with a flag that
	 * causes the success notice to render.
	 *
	 * Public because WordPress's hook system invokes it; not intended for direct consumption.
	 *
	 * @return void
	 */
	public function handle_token_regeneration_request(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to generate a new web cron URL.', 'woocommerce-subscriptions' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::REGENERATE_ACTION );

		$this->regenerate_token();

		$referer = wp_get_referer();
		if ( false === $referer ) {
			$referer = admin_url( 'admin.php?page=wc-settings&tab=subscriptions' );
		}
		$redirect = add_query_arg( self::REGENERATED_NOTICE_FLAG, '1', $referer );

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render a one-time success notice after a regeneration, keyed on the redirect query-arg set by the
	 * handler. The notice only renders on the WC subscriptions settings tab so it doesn't show up on
	 * unrelated admin pages a merchant might happen to navigate to with a stale URL.
	 *
	 * Public because WordPress's hook system invokes it.
	 *
	 * @return void
	 */
	public function maybe_render_token_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice flag is informational, not a state change.
		if ( empty( $_GET[ self::REGENERATED_NOTICE_FLAG ] ) ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>' .
			esc_html__( 'New web cron URL generated. Update your web cron service with the new URL.', 'woocommerce-subscriptions' ) .
			'</p></div>';
	}
}
