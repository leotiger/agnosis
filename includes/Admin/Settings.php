<?php
/**
 * Admin settings page.
 *
 * Single settings screen under Settings → Agnosis.
 * Tabbed: General | Email | AI Providers | Network | Commerce.
 *
 * @package Agnosis\Admin
 */

declare(strict_types=1);

namespace Agnosis\Admin;

use Agnosis\Admin\Dashboards\AdmissionDashboard;
use Agnosis\Admin\Dashboards\AiTestTools;
use Agnosis\Admin\Dashboards\BiographyTitleCache;
use Agnosis\Admin\Dashboards\BrandingTestForm;
use Agnosis\Admin\Dashboards\DeliverabilityCard;
use Agnosis\Admin\Dashboards\InvitationCard;
use Agnosis\Admin\Dashboards\LogsTab;
use Agnosis\Admin\Dashboards\MembersDashboard;
use Agnosis\Admin\Dashboards\NewsletterDashboard;
use Agnosis\Compat\LinguaForge;
use Agnosis\Core\Debug;
use Agnosis\Core\Secrets;
use Agnosis\Core\Turnstile;

class Settings {

	private const PAGE   = 'agnosis-settings';

	/** Each tab gets its own option group so saving one tab never clobbers another. */
	private const GROUPS = [
		'general'     => 'agnosis_general_options',
		'branding'    => 'agnosis_branding_options',
		'email'       => 'agnosis_email_options',
		'ai'          => 'agnosis_ai_options',
		'behavior'    => 'agnosis_behavior_options',
		'network'     => 'agnosis_network_options',
		'community'   => 'agnosis_community_options',
		'commerce'    => 'agnosis_commerce_options',
		'newsletter'  => 'agnosis_newsletter_options',
	];

	public function register_menu(): void {
		add_submenu_page(
			'agnosis',
			__( 'Configuration', 'agnosis' ),
			__( 'Configuration', 'agnosis' ),
			'manage_options',
			self::PAGE,
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		foreach ( $this->field_definitions() as $key => $field ) {
			$tab   = $field['tab'] ?? 'general';
			$group = self::GROUPS[ $tab ] ?? self::GROUPS['general'];
			register_setting( $group, $key, [
				'type'              => $field['type'] ?? 'string',
				'sanitize_callback' => $field['sanitize'] ?? 'sanitize_text_field',
				'default'           => $field['default'] ?? '',
			] );
		}
	}

	public function enqueue_assets( string $hook ): void {
		// Hook name for submenu under 'agnosis': agnosis_page_{slug}
		if ( $hook !== 'agnosis_page_' . self::PAGE ) {
			return;
		}
		wp_add_inline_style( 'wp-admin', $this->admin_css() );
		// wp-util is always available in the WP admin and provides no-jQuery baseline.
		wp_add_inline_script( 'wp-util', $this->ai_test_js() );
		wp_add_inline_script( 'wp-util', $this->reset_default_js() );

		// Registers the 'media-editor' handle (and everything else the core
		// media modal needs) so the Email logo field's "Select Image" button
		// can open it — only loaded on this settings screen, not site-wide.
		wp_enqueue_media();
		wp_add_inline_script( 'media-editor', $this->media_picker_js() );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading tab slug for display only, no data mutation.
		$active_tab = sanitize_key( $_GET['tab'] ?? 'general' );
		$tabs       = $this->tabs();

		// Success notices from admin-post redirects.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- integer/flag from our own redirect, display only.
		if ( isset( $_GET['reprocessed'] ) ) {
			$reset    = (int) $_GET['reprocessed'];
			$enqueued = isset( $_GET['enqueued'] ) ? (int) $_GET['enqueued'] : 0;
			$msg      = sprintf(
				/* translators: 1: queue rows reset, 2: messages newly enqueued */
				__( 'Force-reprocess complete. %1$d queue row(s) reset, %2$d new message(s) enqueued. Click "Process Pending Queue" to run the AI pipeline.', 'agnosis' ),
				$reset,
				$enqueued
			);
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		if ( isset( $_GET['cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Log cleared.', 'agnosis' )
				. '</p></div>';
		}
		if ( isset( $_GET['debug_cleared'] ) ) {
			$removed = (int) $_GET['debug_cleared'];
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html( sprintf(
					/* translators: %d: number of debug files removed */
					_n( 'Debug files cleared. %d file was removed.', 'Debug files cleared. %d files were removed.', $removed, 'agnosis' ),
					$removed
				) )
				. '</p></div>';
		}
		if ( isset( $_GET['term_cache_cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Term translation cache cleared. Terms will be re-translated the next time they\'re needed.', 'agnosis' )
				. '</p></div>';
		}
		if ( isset( $_GET['processed'] ) ) {
			$count = (int) $_GET['processed'];
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html( sprintf(
					/* translators: %d: number of queue items processed */
					__( 'Processed %d pending queue item(s). Check the Logs tab for details.', 'agnosis' ),
					$count
				) )
				. '</p></div>';
		}
		if ( isset( $_GET['polled'] ) ) {
			$count = (int) $_GET['polled'];
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html( sprintf(
					/* translators: %d: number of new messages enqueued */
					__( 'Poll complete. %d new message(s) enqueued. Check the Logs tab, then click Process Pending Queue.', 'agnosis' ),
					$count
				) )
				. '</p></div>';
		}
		if ( isset( $_GET['inbox_test'] ) ) {
			$ok  = 'ok' === $_GET['inbox_test'];
			$msg = isset( $_GET['inbox_message'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['inbox_message'] ) ) ) : '';
			$cls = $ok ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $cls ) . ' is-dismissible"><p>'
				. ( $ok ? '✅ ' : '❌ ' )
				. esc_html( $msg )
				. '</p></div>';
		}
		if ( isset( $_GET['agnosis_message'] ) ) {
			$notice_map = [
				'admitted'            => [ 'success', __( 'Applicant admitted. A welcome email has been sent.', 'agnosis' ) ],
				'admit_failed'        => [ 'error', __( 'Could not admit — the application may no longer be pending.', 'agnosis' ) ],
				'rejected'            => [ 'success', __( 'Application rejected.', 'agnosis' ) ],
				'reject_failed'       => [ 'error', __( 'Could not reject — the application may no longer be pending.', 'agnosis' ) ],
				'banned'              => [ 'success', __( 'Artist suspended. A notification email has been sent.', 'agnosis' ) ],
				'ban_failed'          => [ 'error', __( 'Could not suspend the artist — they may no longer be active.', 'agnosis' ) ],
				'deleted'             => [ 'success', __( 'Artist permanently deleted. Their account and all content have been removed.', 'agnosis' ) ],
				'delete_failed'       => [ 'error', __( 'Could not delete the artist — please try again.', 'agnosis' ) ],
				'vote_opened'         => [ 'success', __( 'Community removal vote opened. All artists have been emailed.', 'agnosis' ) ],
				'vote_open_failed'    => [ 'error', __( 'Could not open a removal vote — one may already be active for this artist.', 'agnosis' ) ],
				'newsletter_sent'        => [ 'success', __( 'Issue prepared and queued — it will go out over the next few cron cycles.', 'agnosis' ) ],
				'newsletter_send_failed' => [ 'error', __( 'Could not start this issue — a previous one may still be sending.', 'agnosis' ) ],
				'newsletter_test_sent'   => [ 'success', __( 'Test email sent — check the inbox you sent it to.', 'agnosis' ) ],
				'newsletter_test_failed' => [ 'error', __( 'Could not send the test email — check the address and your site\'s outgoing mail configuration.', 'agnosis' ) ],
				'invitation_sent'        => [ 'success', __( 'Invitation sent.', 'agnosis' ) ],
				'invitation_failed'      => [ 'error', __( 'Could not send the invitation — check the address and your site\'s outgoing mail configuration.', 'agnosis' ) ],
				'invitation_test_sent'   => [ 'success', __( 'Test invitation sent — check the inbox you sent it to.', 'agnosis' ) ],
				'invitation_test_failed' => [ 'error', __( 'Could not send the test invitation — check the address and your site\'s outgoing mail configuration.', 'agnosis' ) ],
				'deliverability_test_sent'   => [ 'success', __( 'Test email sent — check whether it lands in your inbox or your spam folder.', 'agnosis' ) ],
				'deliverability_test_failed' => [ 'error', __( 'Could not send the test email — check the address and your site\'s outgoing mail configuration.', 'agnosis' ) ],
				'branding_test_sent'         => [ 'success', __( 'Branding preview sent — check the inbox you sent it to.', 'agnosis' ) ],
				'branding_test_failed'       => [ 'error', __( 'Could not send the branding preview — check the address and your site\'s outgoing mail configuration.', 'agnosis' ) ],
				'newsletter_retry_queued'    => [ 'success', __( 'Failed recipients reset to pending — they will be retried on the next few cron cycles.', 'agnosis' ) ],
				'newsletter_retry_none'      => [ 'error', __( 'No failed recipients to retry for this newsletter.', 'agnosis' ) ],
			];
			$key = sanitize_key( wp_unslash( $_GET['agnosis_message'] ) );
			if ( isset( $notice_map[ $key ] ) ) {
				[ $type, $text ] = $notice_map[ $key ];
				echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		?>
		<div class="wrap agnosis-settings">
			<h1>
				<span style="color:#7c6af7">✦</span> <?php esc_html_e( 'Agnosis', 'agnosis' ); ?>
				<small style="font-size:.6em;font-weight:400;color:#888">— <?php esc_html_e( 'Art blooming out of oblivion', 'agnosis' ); ?></small>
			</h1>

			<nav class="nav-tab-wrapper" style="margin-bottom:1.5rem">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=' . $slug ) ); ?>"
					   class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php if ( 'logs' === $active_tab ) : ?>
				<?php ( new LogsTab() )->render(); ?>
			<?php elseif ( 'community' === $active_tab ) : ?>
				<?php $this->render_community_tab(); ?>
			<?php else : ?>
				<form method="post" action="options.php">
					<?php
					settings_fields( self::GROUPS[ $active_tab ] ?? self::GROUPS['general'] );
					$this->render_tab( $active_tab );
					submit_button( __( 'Save Changes', 'agnosis' ) );
					?>
				</form>
				<?php $this->render_tab_tools( $active_tab ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Sub-tabs within the Community tab.
	 *
	 * Split out of the main tab because a growing community makes the
	 * combined page an endless scroll: the Members table (Pending
	 * Applications + admitted/banned members, both unbounded lists) and the
	 * Rules settings form used to render on top of each other on one page.
	 *
	 * 'members' is listed first and is the default — landing on Community
	 * should show who's actually in it, not a settings form.
	 *
	 * @return array<string, string>
	 */
	private function community_subtabs(): array {
		return [
			'members' => __( 'Members', 'agnosis' ),
			'rules'   => __( 'Rules',   'agnosis' ),
		];
	}

	/**
	 * Render the Community tab: a sub-tab nav, then either the Members
	 * dashboards (Pending Applications, Members, Invite an Artist — via the
	 * existing render_tab_tools( 'community' ) branch) or the Rules settings
	 * form (the admission/community-cap/removal-vote/invitation-intro fields),
	 * never both at once.
	 */
	private function render_community_tab(): void {
		$subtabs = $this->community_subtabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading subtab slug for display only, no data mutation.
		$subtab = sanitize_key( $_GET['subtab'] ?? 'members' );
		if ( ! isset( $subtabs[ $subtab ] ) ) {
			$subtab = 'members';
		}
		?>
		<ul class="subsubsub">
			<?php
			$slugs = array_keys( $subtabs );
			$last  = end( $slugs );
			foreach ( $subtabs as $slug => $label ) {
				$url = admin_url( 'admin.php?page=' . self::PAGE . '&tab=community&subtab=' . $slug );
				printf(
					'<li><a href="%s"%s>%s</a>%s</li>',
					esc_url( $url ),
					$subtab === $slug ? ' class="current"' : '',
					esc_html( $label ),
					$slug !== $last ? ' |' : ''
				);
			}
			?>
		</ul>
		<br class="clear">

		<?php if ( 'rules' === $subtab ) : ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUPS['community'] );
				$this->render_tab( 'community' );
				submit_button( __( 'Save Changes', 'agnosis' ) );
				?>
			</form>
		<?php else : ?>
			<?php $this->render_tab_tools( 'community' ); ?>
		<?php endif; ?>
		<?php
	}

	// -------------------------------------------------------------------------

	/** @return array<string, string> */
	private function tabs(): array {
		return [
			'general'    => __( 'General',      'agnosis' ),
			'branding'   => __( 'Branding',     'agnosis' ),
			'email'      => __( 'Email Inbox',  'agnosis' ),
			'ai'         => __( 'AI Providers', 'agnosis' ),
			'behavior'   => __( 'Behavior',     'agnosis' ),
			'network'    => __( 'Network',      'agnosis' ),
			'community'  => __( 'Community',    'agnosis' ),
			'commerce'   => __( 'Commerce',     'agnosis' ),
			'newsletter' => __( 'Newsletter',   'agnosis' ),
			'logs'       => __( 'Logs',         'agnosis' ),
		];
	}

	private function render_tab( string $tab ): void {
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $this->field_definitions() as $key => $field ) {
			if ( ( $field['tab'] ?? 'general' ) !== $tab ) {
				continue;
			}
			$this->render_field( $key, $field );
		}
		echo '</tbody></table>';
	}

	/**
	 * @param array<string, mixed> $field
	 *
	 * P-4 (AUDIT-0.9.39.md §3c): any field whose option name is one of
	 * Secrets::MAP's five keys renders a locked, non-editable notice instead
	 * of its normal input whenever the matching wp-config.php constant is
	 * actually defined — the constant always wins over whatever's saved
	 * here, so letting an operator type a new value into this field would
	 * silently save something that's then ignored. Generic on
	 * Secrets::override_constant_name() rather than special-casing five
	 * field keys by name here.
	 */
	private function render_field( string $key, array $field ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $field['label'] ) . '</label></th><td>';

		$override_constant = Secrets::override_constant_name( $key );
		if ( null !== $override_constant && Secrets::is_overridden( $key ) ) {
			echo '<input type="text" value="' . esc_attr__( '••••••••  (defined in wp-config.php)', 'agnosis' ) . '" class="regular-text" readonly disabled>';
			$override_notice = sprintf(
				/* translators: %s: PHP constant name, e.g. AGNOSIS_OPENAI_KEY, wrapped in <code> */
				esc_html__( 'Set via the %s constant in wp-config.php — this field is inert until that constant is removed.', 'agnosis' ),
				'<code>' . esc_html( $override_constant ) . '</code>'
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $override_notice is built from esc_html()'d pieces above, same pattern as $desc below.
			echo '<p class="description">' . $override_notice . '</p></td></tr>';
			return;
		}

		$value = get_option( $key, $field['default'] ?? '' );
		$type  = $field['input'] ?? 'text';
		$desc  = isset( $field['desc'] ) ? '<p class="description">' . esc_html( $field['desc'] ) . '</p>' : '';

		switch ( $type ) {
			case 'password':
				echo '<input type="password" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="regular-text" autocomplete="new-password">';
				break;
			case 'number':
				echo '<input type="number" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="small-text" step="' . esc_attr( $field['step'] ?? '1' ) . '" min="' . esc_attr( $field['min'] ?? '0' ) . '">';
				break;
			case 'checkbox':
				echo '<input type="checkbox" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="1" ' . checked( 1, (int) $value, false ) . '>';
				break;
			case 'select':
				echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">';
				foreach ( $field['options'] as $opt_val => $opt_label ) {
					echo '<option value="' . esc_attr( $opt_val ) . '" ' . selected( $value, $opt_val, false ) . '>' . esc_html( $opt_label ) . '</option>';
				}
				echo '</select>';
				break;
			case 'page':
				wp_dropdown_pages( [
					'name'              => esc_attr( $key ),
					'id'                => esc_attr( $key ),
					'selected'          => (int) $value,
					'show_option_none'  => esc_html__( '— None (inline message only) —', 'agnosis' ),
					'option_none_value' => '0',
				] );
				break;
			case 'textarea':
				$rows = $field['rows'] ?? 6;
				echo '<textarea id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" rows="' . esc_attr( (string) $rows ) . '" class="large-text code">' . esc_textarea( $value ) . '</textarea>';
				if ( ! empty( $field['resettable'] ) && isset( $field['default'] ) ) {
					echo '<p><button type="button" class="button agnosis-reset-default" data-target="' . esc_attr( $key ) . '" data-default="' . esc_attr( (string) $field['default'] ) . '">'
						. esc_html__( 'Reset to default', 'agnosis' )
						. '</button> <span class="description">' . esc_html__( 'Replaces the text above with the plugin\'s built-in default — click Save Changes below to keep it.', 'agnosis' ) . '</span></p>';
				}
				break;
			case 'readonly':
				echo '<input type="text" value="' . esc_attr( $value ) . '" class="regular-text" readonly>';
				break;
			case 'color':
				echo '<input type="color" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" style="width:60px;height:36px;padding:2px;">';
				if ( isset( $field['default'] ) ) {
					echo '<button type="button" class="button agnosis-reset-default" data-target="' . esc_attr( $key ) . '" data-default="' . esc_attr( (string) $field['default'] ) . '">'
						. esc_html__( 'Reset to default', 'agnosis' )
						. '</button>';
				}
				break;
			case 'media':
				$attachment_id = (int) $value;
				$image_src     = $attachment_id ? wp_get_attachment_image_src( $attachment_id, 'medium' ) : false;
				$image_url     = $image_src ? $image_src[0] : '';
				echo '<div class="agnosis-media-field">';
				echo '<input type="hidden" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $attachment_id ) . '" class="agnosis-media-field__id">';
				echo '<div class="agnosis-media-field__preview" style="margin-bottom:8px;' . ( $image_url ? '' : 'display:none;' ) . '">';
				echo '<img src="' . esc_url( $image_url ) . '" alt="" style="display:block;max-width:240px;max-height:120px;border:1px solid #ddd;border-radius:4px;">';
				echo '</div>';
				echo '<button type="button" class="button agnosis-media-select">' . esc_html__( 'Select Image', 'agnosis' ) . '</button> ';
				echo '<button type="button" class="button agnosis-media-remove" style="' . ( $image_url ? '' : 'display:none;' ) . '">' . esc_html__( 'Remove', 'agnosis' ) . '</button>';
				echo '</div>';
				break;
			default:
				echo '<input type="text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="regular-text">';
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $desc is built from esc_html() above; the <p> wrapper is static markup.
		echo $desc . '</td></tr>';
	}

	/**
	 * All Settings field definitions, tab-by-tab.
	 *
	 * Delegates to SettingsFields::all() — the ~1000-line data table itself
	 * moved out of this class in the 2026-07-17 god-class refactor
	 * (AUDIT-1.0.0.md §4d), but this method's name and visibility are kept
	 * as a thin wrapper rather than removed outright: both
	 * EmailBrandingColorFieldsTest and SettingsResettableFieldsTest reflect
	 * into `Settings::field_definitions()` via ReflectionMethod, and there's
	 * no benefit to churning that test surface for a rename.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function field_definitions(): array {
		return SettingsFields::all();
	}

	/**
	 * Turnstile-unconfigured warning (security/ops audit §4a, layer (2) of
	 * that finding's fix shape). The join application form (and newsletter
	 * signup) are always publicly reachable by design — there is no "close
	 * the join page" mode in this plugin, the whole model is community
	 * vouching, not a gatekeeper — so the only thing standing between the
	 * form and a spam bot is whichever of the two layers below is active:
	 *
	 * - Turnstile::is_enabled() — opt-in, off until both keys are set here.
	 * - RateLimiter's fixed 5/min/IP cap — always on, but per §4a itself,
	 *   trivially distributed around.
	 *
	 * Nothing before this shipped ever told the operator that skipping the
	 * Turnstile keys above left the form running on IP-limiting alone.
	 * Read-only — configuring Turnstile is still just filling in the two
	 * fields already on this tab; this card only makes the current state
	 * visible instead of silent.
	 */
	private function render_turnstile_warning(): void {
		$enabled = Turnstile::is_enabled();
		?>
		<div class="card" style="max-width:800px;margin-top:1.5rem;padding:1rem 1.5rem">
			<h2 style="margin-top:0"><?php esc_html_e( 'Bot Protection', 'agnosis' ); ?></h2>
			<?php if ( $enabled ) : ?>
				<p>
					<strong style="color:#0a7c48">✓ <?php esc_html_e( 'Turnstile is configured.', 'agnosis' ); ?></strong>
					<?php esc_html_e( 'The Join application form and the Newsletter Subscribe form both require human verification before they accept a submission.', 'agnosis' ); ?>
				</p>
			<?php else : ?>
				<div class="notice notice-warning inline" style="margin:0">
					<p>
						<strong><?php esc_html_e( 'Turnstile is not configured.', 'agnosis' ); ?></strong>
						<?php esc_html_e( 'Your Join application form is public — anyone can submit an application, and a submitted (and confirmed) application still emails every admitted artist and admin. Right now the only thing standing between that form and an automated bot is a fixed 5-submissions-per-minute-per-IP limit, which a distributed bot can work around entirely. Add a Cloudflare Turnstile site key and secret key above to require human verification on both the Join form and the Newsletter Subscribe form.', 'agnosis' ); ?>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * AI-processor privacy-policy reminder (seventh audit §4d — "optionally,
	 * an admin notice when an AI provider is configured but no
	 * privacy-policy page is set... consider a note pointing operators to
	 * obtain a DPA"). Only rendered at all when at least one AI provider's
	 * API key is actually configured — with none configured, no artist or
	 * visitor content is being sent anywhere yet, so there's nothing to
	 * warn about. Mirrors render_turnstile_warning()'s own
	 * green-confirmation/amber-warning shape.
	 */
	private function render_privacy_policy_notice(): void {
		$openai_configured    = '' !== Secrets::openai_api_key();
		$anthropic_configured = '' !== Secrets::anthropic_api_key();

		if ( ! $openai_configured && ! $anthropic_configured ) {
			return;
		}

		$providers = array_filter( [
			$openai_configured ? __( 'OpenAI', 'agnosis' ) : '',
			$anthropic_configured ? __( 'Anthropic', 'agnosis' ) : '',
		] );
		$provider_list = implode( ', ', $providers );

		$has_policy = function_exists( 'get_privacy_policy_url' ) && '' !== get_privacy_policy_url();
		?>
		<div class="card" style="max-width:800px;margin-top:1.5rem;padding:1rem 1.5rem">
			<h2 style="margin-top:0"><?php esc_html_e( 'AI Processing & Privacy Policy', 'agnosis' ); ?></h2>
			<?php if ( $has_policy ) : ?>
				<p>
					<strong style="color:#0a7c48">✓ <?php esc_html_e( 'A Privacy Policy page is set.', 'agnosis' ); ?></strong>
					<?php
					printf(
						/* translators: %s: comma-separated list of configured AI providers, e.g. "OpenAI, Anthropic" */
						esc_html__( 'Suggested wording covering %s and public federation was added to it automatically — review it under Settings → Privacy → "Guide" before publishing, and check with your provider(s) about their own data processing agreement (DPA) for your compliance obligations.', 'agnosis' ),
						esc_html( $provider_list )
					);
					?>
				</p>
			<?php else : ?>
				<div class="notice notice-warning inline" style="margin:0">
					<p>
						<strong><?php esc_html_e( 'No Privacy Policy page is set.', 'agnosis' ); ?></strong>
						<?php
						printf(
							/* translators: %s: comma-separated list of configured AI providers, e.g. "OpenAI, Anthropic" */
							esc_html__( 'Submitted artwork, text, and visitor contact messages are sent to %s for processing. Create or choose a page under Settings → Privacy — this plugin has already prepared suggested wording you\'ll find there under "Guide". You may also want to review that provider\'s data processing agreement (DPA) for your own compliance obligations.', 'agnosis' ),
							esc_html( $provider_list )
						);
						?>
						<a href="<?php echo esc_url( admin_url( 'options-privacy.php' ) ); ?>"><?php esc_html_e( 'Go to Settings → Privacy', 'agnosis' ); ?></a>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Debug Files panel — shown under Settings → General, below the Save
	 * Changes button. The debug on/off toggle itself is a normal Settings
	 * API field (agnosis_debug_enabled, saved via options.php); this panel
	 * only surfaces read-only state (directory, file count, wp-config
	 * override) and the destructive "Clear Debug Files" action, which needs
	 * its own admin-post round trip rather than the shared settings form.
	 */
	private function render_debug_panel(): void {
		$debug_enabled  = Debug::enabled();
		$debug_dir      = Debug::dir();
		$debug_count    = Debug::file_count();
		$const_defined  = Debug::constant_defined();
		$const_value    = Debug::constant_value();
		?>
		<div class="card" style="max-width:800px;margin-top:1.5rem;padding:1rem 1.5rem">
			<h2 style="margin-top:0"><?php esc_html_e( 'Debug Files', 'agnosis' ); ?></h2>

			<table class="form-table" role="presentation" style="margin-bottom:0"><tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Currently', 'agnosis' ); ?></th>
					<td>
						<?php if ( $debug_enabled ) : ?>
							<strong style="color:#0a7c48">✓ <?php esc_html_e( 'Enabled', 'agnosis' ); ?></strong>
						<?php else : ?>
							<strong style="color:#c0392b">✗ <?php esc_html_e( 'Disabled', 'agnosis' ); ?></strong>
						<?php endif; ?>
						<?php if ( $const_defined ) : ?>
							<p class="description">
								<?php
								if ( $const_value ) {
									esc_html_e( 'Forced ON by the AGNOSIS_DEBUG constant in wp-config.php — the checkbox above has no effect until that line is removed.', 'agnosis' );
								} else {
									esc_html_e( 'Forced OFF by the AGNOSIS_DEBUG constant in wp-config.php — the checkbox above has no effect until that line is removed.', 'agnosis' );
								}
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Directory', 'agnosis' ); ?></th>
					<td>
						<code><?php echo esc_html( $debug_dir ); ?></code>
						<p class="description"><?php esc_html_e( 'Filter with agnosis_debug_dir to redirect debug output elsewhere.', 'agnosis' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Files', 'agnosis' ); ?></th>
					<td>
						<strong><?php echo esc_html( number_format_i18n( $debug_count ) ); ?></strong>
						<?php esc_html_e( '.txt file(s) in the directory', 'agnosis' ); ?>
					</td>
				</tr>
			</tbody></table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem"
				  onsubmit="return confirm('<?php echo esc_js( __( 'Delete all .txt files in the debug directory? The directory itself stays in place so future debug writes still land cleanly.', 'agnosis' ) ); ?>');">
				<input type="hidden" name="action" value="agnosis_clear_debug_files">
				<?php wp_nonce_field( 'agnosis_clear_debug_files' ); ?>
				<?php
				submit_button(
					__( 'Clear Debug Files', 'agnosis' ),
					'secondary', 'submit', false,
					$debug_count > 0 ? [] : [ 'disabled' => 'disabled' ]
				);
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Term Translation Cache panel — shown under Settings → General,
	 * alongside the Debug Files panel. Fourth audit §4d: the AI-translated
	 * term-name cache (`agnosis_term_translations`, used by
	 * Compat\LinguaForge::sync_translated_terms()) previously had no UI at
	 * all — a bad translation of a term label was permanent, since nothing
	 * ever expired the cache or gave an admin a way to clear it (renaming the
	 * source term auto-invalidates that one entry as of this same fix — see
	 * LinguaForge::invalidate_renamed_term_cache() — but a bad translation of
	 * a term an admin does NOT plan to rename still needed a manual escape
	 * hatch). Only ever active if Lingua Forge is installed — the cache
	 * can't exist otherwise.
	 */
	private function render_term_translation_cache_panel(): void {
		if ( ! LinguaForge::is_active() ) {
			return;
		}

		$count = LinguaForge::term_translation_cache_count();
		?>
		<div class="card" style="max-width:800px;margin-top:1.5rem;padding:1rem 1.5rem">
			<h2 style="margin-top:0"><?php esc_html_e( 'Term Translation Cache', 'agnosis' ); ?></h2>

			<table class="form-table" role="presentation" style="margin-bottom:0"><tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cached translations', 'agnosis' ); ?></th>
					<td>
						<strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong>
						<p class="description"><?php esc_html_e( 'AI-translated tag/medium term names, cached so the same term always gets the same translated label instead of a fresh AI phrasing per post. Renaming a term already clears its own cached entry automatically; use the button below only if a cached translation itself is wrong.', 'agnosis' ); ?></p>
					</td>
				</tr>
			</tbody></table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem"
				  onsubmit="return confirm('<?php echo esc_js( __( 'Clear every cached term translation? Nothing is deleted from your site — terms will simply be re-translated by AI the next time they\'re needed.', 'agnosis' ) ); ?>');">
				<input type="hidden" name="action" value="agnosis_clear_term_translations_cache">
				<?php wp_nonce_field( 'agnosis_clear_term_translations_cache' ); ?>
				<?php
				submit_button(
					__( 'Clear Term Translation Cache', 'agnosis' ),
					'secondary', 'submit', false,
					$count > 0 ? [] : [ 'disabled' => 'disabled' ]
				);
				?>
			</form>
		</div>
		<?php
	}

	private function render_tab_tools( string $tab ): void {
		if ( 'general' === $tab ) {
			$this->render_turnstile_warning();
			$this->render_debug_panel();
			$this->render_term_translation_cache_panel();
			( new BiographyTitleCache() )->render();
			return;
		}
		if ( 'ai' === $tab ) {
			( new AiTestTools() )->render();
			$this->render_privacy_policy_notice();
			return;
		}
		if ( 'community' === $tab ) {
			( new AdmissionDashboard() )->render();
			( new MembersDashboard() )->render();
			( new InvitationCard() )->render();
			return;
		}
		if ( 'newsletter' === $tab ) {
			( new NewsletterDashboard() )->render();
			return;
		}
		if ( 'branding' === $tab ) {
			( new BrandingTestForm() )->render();
			return;
		}
		if ( 'email' !== $tab ) {
			return;
		}
		?>
		<div class="card" style="max-width:800px;margin-top:1.5rem;padding:1rem 1.5rem">
			<h2 style="margin-top:0"><?php esc_html_e( 'Inbox Tools', 'agnosis' ); ?></h2>

			<table class="form-table" role="presentation" style="margin-bottom:0"><tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Test connection', 'agnosis' ); ?></th>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="agnosis_test_inbox">
							<?php wp_nonce_field( 'agnosis_test_inbox' ); ?>
							<?php submit_button( __( 'Test IMAP Connection', 'agnosis' ), 'primary', 'submit', false ); ?>
						</form>
						<p class="description"><?php esc_html_e( 'Connects to the configured mailbox and reports the total and unseen message count.', 'agnosis' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Poll inbox now', 'agnosis' ); ?></th>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="agnosis_poll_now">
							<?php wp_nonce_field( 'agnosis_poll_now' ); ?>
							<?php submit_button( __( 'Poll Inbox Now', 'agnosis' ), 'primary', 'submit', false ); ?>
						</form>
						<p class="description"><?php esc_html_e( 'Fetches unseen messages immediately without waiting for WP-Cron. Then click "Process Pending Queue" to run the AI pipeline.', 'agnosis' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Process queue', 'agnosis' ); ?></th>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="agnosis_process_queue">
							<?php wp_nonce_field( 'agnosis_process_queue' ); ?>
							<?php submit_button( __( 'Process Pending Queue', 'agnosis' ), 'primary', 'submit', false ); ?>
						</form>
						<p class="description"><?php esc_html_e( 'Immediately runs the AI pipeline on all pending queue items without waiting for WP-Cron. Use this to test the end-to-end flow after a poll.', 'agnosis' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Force reprocess', 'agnosis' ); ?></th>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="agnosis_force_reprocess">
							<?php wp_nonce_field( 'agnosis_force_reprocess' ); ?>
							<?php submit_button( __( 'Force Reprocess Inbox', 'agnosis' ), 'secondary', 'submit', false ); ?>
						</form>
						<p class="description"><?php esc_html_e( 'Resets failed/stuck/orphaned queue rows back to pending, then immediately polls for unprocessed messages. Then click "Process Pending Queue" to run the AI pipeline. Already-published artworks with valid posts are never re-processed.', 'agnosis' ); ?></p>
					</td>
				</tr>
			</tbody></table>
		</div>
		<?php
		( new DeliverabilityCard() )->render();
	}

	private function ai_test_js(): string {
		$ajax_url = esc_js( admin_url( 'admin-ajax.php' ) );
		return sprintf(
			"document.addEventListener('click', function(e) {\n"
			. "\tvar btn = e.target.closest('.agnosis-test-ai');\n"
			. "\tif (!btn) return;\n"
			. "\tvar provider = btn.dataset.provider;\n"
			. "\tvar nonce    = btn.dataset.nonce;\n"
			. "\tvar result   = document.querySelector('.agnosis-test-result[data-provider=\"' + provider + '\"]');\n"
			. "\tif (!result) return;\n"
			. "\n"
			. "\tbtn.disabled = true;\n"
			. "\tresult.style.color = '#666';\n"
			. "\tresult.textContent = 'Testing…';\n"
			. "\n"
			. "\tvar body = new URLSearchParams({ action: 'agnosis_test_ai', provider: provider, nonce: nonce });\n"
			. "\tfetch('%s', { method: 'POST', credentials: 'same-origin', body: body })\n"
			. "\t\t.then(function(r) { return r.json(); })\n"
			. "\t\t.then(function(res) {\n"
			. "\t\t\tif (res && res.success) {\n"
			. "\t\t\t\tresult.style.color = '#0a7c48';\n"
			. "\t\t\t\tresult.textContent = '✓ ' + ((res.data && res.data.message) || 'OK');\n"
			. "\t\t\t} else {\n"
			. "\t\t\t\tresult.style.color = '#c0392b';\n"
			. "\t\t\t\tresult.textContent = '✗ ' + ((res.data && res.data.message) || 'Request failed');\n"
			. "\t\t\t}\n"
			. "\t\t})\n"
			. "\t\t.catch(function() {\n"
			. "\t\t\tresult.style.color = '#c0392b';\n"
			. "\t\t\tresult.textContent = '✗ Request failed';\n"
			. "\t\t})\n"
			. "\t\t.finally(function() { btn.disabled = false; });\n"
			. '});',
			$ajax_url
		);
	}

	/**
	 * Wires up every `.agnosis-reset-default` button (see render_field()'s
	 * textarea AND color cases) — repopulates its associated field with the
	 * plugin's built-in default value, entirely client-side. Nothing is
	 * saved until the admin clicks the page's own Save Changes button, and a
	 * confirm dialog guards against an accidental click overwriting text
	 * they meant to keep.
	 *
	 * This exists because several textarea settings (system prompt, artist
	 * prompt template, enhancement instructions, trusted embed platforms,
	 * invitation intro) ship with substantial built-in copy that an admin can
	 * freely overwrite — until now, doing so meant losing the original for
	 * good, with no way to compare against or return to it. The two email
	 * branding color fields (header background, accent) reuse the same
	 * button/handler for the same reason — setting `field.value` client-side
	 * works identically for `<input type="color">` as it does for a
	 * `<textarea>`.
	 */
	private function reset_default_js(): string {
		return "document.addEventListener('click', function (e) {\n"
			. "\tvar btn = e.target.closest('.agnosis-reset-default');\n"
			. "\tif (!btn) return;\n"
			. "\tvar field = document.getElementById(btn.dataset.target);\n"
			. "\tif (!field) return;\n"
			. "\tif (!window.confirm('Replace the current text with the built-in default? This will not be saved until you click Save Changes.')) return;\n"
			. "\tfield.value = btn.dataset.default;\n"
			. '});';
	}

	/**
	 * Wires up every `.agnosis-media-field` on the page (currently just the
	 * Email logo field, but written to support more than one) to the core
	 * media modal via `wp.media()`. Vanilla JS delegated off document clicks —
	 * same pattern as ai_test_js() — since wp.media() itself is the only part
	 * of this that actually needs the media-editor script WordPress core
	 * already ships, not jQuery directly.
	 */
	private function media_picker_js(): string {
		return "document.addEventListener('click', function (e) {\n"
			. "\tvar removeBtn = e.target.closest('.agnosis-media-remove');\n"
			. "\tif (removeBtn) {\n"
			. "\t\tvar removeWrap = removeBtn.closest('.agnosis-media-field');\n"
			. "\t\tremoveWrap.querySelector('.agnosis-media-field__id').value = '';\n"
			. "\t\tremoveWrap.querySelector('.agnosis-media-field__preview').style.display = 'none';\n"
			. "\t\tremoveBtn.style.display = 'none';\n"
			. "\t\treturn;\n"
			. "\t}\n"
			. "\n"
			. "\tvar selectBtn = e.target.closest('.agnosis-media-select');\n"
			. "\tif (!selectBtn) return;\n"
			. "\te.preventDefault();\n"
			. "\n"
			. "\tvar wrap = selectBtn.closest('.agnosis-media-field');\n"
			. "\tvar frame = wp.media({\n"
			. "\t\ttitle: 'Select Image',\n"
			. "\t\tbutton: { text: 'Use this image' },\n"
			. "\t\tlibrary: { type: 'image' },\n"
			. "\t\tmultiple: false\n"
			. "\t});\n"
			. "\n"
			. "\tframe.on('select', function () {\n"
			. "\t\tvar attachment = frame.state().get('selection').first().toJSON();\n"
			. "\t\twrap.querySelector('.agnosis-media-field__id').value = attachment.id;\n"
			. "\n"
			. "\t\tvar previewWrap = wrap.querySelector('.agnosis-media-field__preview');\n"
			. "\t\tvar img = previewWrap.querySelector('img');\n"
			. "\t\timg.src = (attachment.sizes && attachment.sizes.medium) ? attachment.sizes.medium.url : attachment.url;\n"
			. "\t\tpreviewWrap.style.display = 'block';\n"
			. "\n"
			. "\t\twrap.querySelector('.agnosis-media-remove').style.display = 'inline-block';\n"
			. "\t});\n"
			. "\n"
			. "\tframe.open();\n"
			. '});';
	}

	private function admin_css(): string {
		return '
		.agnosis-settings h1 { display:flex; align-items:baseline; gap:.4rem; }
		.agnosis-settings .nav-tab-active { border-bottom-color:#7c6af7; color:#7c6af7; }
		';
	}

	// -------------------------------------------------------------------------
	// admin_post handlers
	// -------------------------------------------------------------------------

	/** admin_post handler — delete all debug-directory dumps. */
	public function handle_clear_debug_files(): void {
		check_admin_referer( 'agnosis_clear_debug_files' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$removed = Debug::clear();

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'agnosis-settings', 'tab' => 'general', 'debug_cleared' => (string) $removed ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** admin_post handler — delete the entire term-translation cache (fourth audit §4d). */
	public function handle_clear_term_translations_cache(): void {
		check_admin_referer( 'agnosis_clear_term_translations_cache' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		LinguaForge::clear_term_translations_cache();

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'agnosis-settings', 'tab' => 'general', 'term_cache_cleared' => '1' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
