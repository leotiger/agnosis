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

use Agnosis\Artist\Admission;
use Agnosis\Artist\Departure;
use Agnosis\Core\Logger;

class Settings {

	private const PAGE   = 'agnosis-settings';

	/** Each tab gets its own option group so saving one tab never clobbers another. */
	private const GROUPS = [
		'general'  => 'agnosis_general_options',
		'email'    => 'agnosis_email_options',
		'ai'       => 'agnosis_ai_options',
		'behavior' => 'agnosis_behavior_options',
		'network'  => 'agnosis_network_options',
		'commerce' => 'agnosis_commerce_options',
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
				<?php $this->render_logs_tab(); ?>
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

	// -------------------------------------------------------------------------

	/** @return array<string, string> */
	private function tabs(): array {
		return [
			'general'  => __( 'General',      'agnosis' ),
			'email'    => __( 'Email Inbox',  'agnosis' ),
			'ai'       => __( 'AI Providers', 'agnosis' ),
			'behavior' => __( 'Behaviour',    'agnosis' ),
			'network'  => __( 'Network',      'agnosis' ),
			'commerce' => __( 'Commerce',     'agnosis' ),
			'logs'     => __( 'Logs',         'agnosis' ),
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

	/** @param array<string, mixed> $field */
	private function render_field( string $key, array $field ): void {
		$value = get_option( $key, $field['default'] ?? '' );
		$type  = $field['input'] ?? 'text';
		$desc  = isset( $field['desc'] ) ? '<p class="description">' . esc_html( $field['desc'] ) . '</p>' : '';

		echo '<tr><th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $field['label'] ) . '</label></th><td>';

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
			case 'textarea':
				$rows = $field['rows'] ?? 6;
				echo '<textarea id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" rows="' . esc_attr( (string) $rows ) . '" class="large-text code">' . esc_textarea( $value ) . '</textarea>';
				break;
			case 'readonly':
				echo '<input type="text" value="' . esc_attr( $value ) . '" class="regular-text" readonly>';
				break;
			default:
				echo '<input type="text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="regular-text">';
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $desc is built from esc_html() above; the <p> wrapper is static markup.
		echo $desc . '</td></tr>';
	}

	/** @return array<string, array<string, mixed>> */
	private function field_definitions(): array {
		return [
			// --- GENERAL ---
			'agnosis_base_domain' => [
				'tab'     => 'general',
				'label'   => __( 'Base domain', 'agnosis' ),
				'desc'    => __( 'Root domain for artist subdomains, e.g. agnosis.art. Each artist is reachable at nicename.{base_domain}. Requires a wildcard DNS record (*.{base_domain}) pointing to this server. Leave blank to disable subdomain routing.', 'agnosis' ),
				'default' => '',
			],
			'agnosis_node_label' => [
				'tab'     => 'general',
				'label'   => __( 'Node label', 'agnosis' ),
				'desc'    => __( 'How this node introduces itself to the network.', 'agnosis' ),
				'default' => get_bloginfo( 'name' ),
			],

			// --- EMAIL ---
			'agnosis_email_driver' => [
				'tab'     => 'email',
				'label'   => __( 'Email driver', 'agnosis' ),
				'input'   => 'select',
				'options' => [ 'imap' => 'IMAP (poll)', 'webhook' => 'Webhook (push)' ],
				'default' => 'imap',
			],
			// --- Routing addresses ---
			'agnosis_email_submit' => [
				'tab'   => 'email',
				'label' => __( 'Artwork submissions address', 'agnosis' ),
				'desc'  => __( 'Artists send artwork to this address — e.g. submit@agnosis.art', 'agnosis' ),
			],
			'agnosis_email_bio' => [
				'tab'   => 'email',
				'label' => __( 'Biography submissions address', 'agnosis' ),
				'desc'  => __( 'Artists send biography updates to this address — e.g. bio@agnosis.art', 'agnosis' ),
			],
			'agnosis_email_event' => [
				'tab'   => 'email',
				'label' => __( 'Event submissions address', 'agnosis' ),
				'desc'  => __( 'Artists send event announcements to this address — e.g. event@agnosis.art', 'agnosis' ),
			],
			'agnosis_email_replace' => [
				'tab'  => 'email',
				'label' => __( 'Replace artwork address', 'agnosis' ),
				'desc'  => __( 'Artist sends a new version of an existing artwork. Subject must match the original title. Bypasses duplicate detection — e.g. replace@agnosis.art', 'agnosis' ),
			],
			'agnosis_email_remove' => [
				'tab'  => 'email',
				'label' => __( 'Removal request address', 'agnosis' ),
				'desc'  => __( 'Artist requests takedown of an existing artwork. Subject must match the title. Moves the post to draft pending admin review — e.g. remove@agnosis.art', 'agnosis' ),
			],
			'agnosis_email_promote' => [
				'tab'  => 'email',
				'label' => __( 'Promote artwork address', 'agnosis' ),
				'desc'  => __( 'Artist sends an email here (subject = exact artwork title) to mark that artwork as featured in their gallery overview. Any previously featured artwork is automatically demoted — e.g. promote@agnosis.art', 'agnosis' ),
			],
			'agnosis_email_goodbye' => [
				'tab'   => 'email',
				'label' => __( 'Goodbye / self-removal address', 'agnosis' ),
				'desc'  => __( 'Artist emails here (any subject, no attachment needed) to request account deletion. A confirmation link is emailed back before anything is deleted — e.g. goodbye@agnosis.art', 'agnosis' ),
			],

			// --- IMAP connection ---
			'agnosis_imap_host' => [
				'tab'   => 'email',
				'label' => __( 'IMAP host', 'agnosis' ),
				'desc'  => __( 'e.g. imap.yourhost.com', 'agnosis' ),
			],
			'agnosis_imap_port' => [
				'tab'     => 'email',
				'label'   => __( 'IMAP port', 'agnosis' ),
				'input'   => 'number',
				'default' => 993,
				'min'     => 1,
			],
			'agnosis_imap_ssl' => [
				'tab'     => 'email',
				'label'   => __( 'Use SSL', 'agnosis' ),
				'input'   => 'checkbox',
				'default' => 1,
				'type'    => 'boolean',
				'sanitize' => fn( $v ) => (bool) $v,
			],
			'agnosis_imap_novalidate_cert' => [
				'tab'      => 'email',
				'label'    => __( 'Skip SSL certificate validation', 'agnosis' ),
				'input'    => 'checkbox',
				'default'  => 0,
				'type'     => 'boolean',
				'sanitize' => fn( $v ) => (bool) $v,
				'desc'     => __( '⚠ Use only as a temporary workaround when your mail server\'s certificate is not yet trusted (e.g. Plesk self-signed cert before Let\'s Encrypt is applied to the mail daemon). Disable this once the mail server presents a valid certificate.', 'agnosis' ),
			],
			'agnosis_imap_user' => [
				'tab'   => 'email',
				'label' => __( 'IMAP username', 'agnosis' ),
				'desc'  => __( 'Login username for the IMAP account — typically the catch-all mailbox address.', 'agnosis' ),
			],
			'agnosis_imap_pass' => [
				'tab'     => 'email',
				'label'   => __( 'Submission email password', 'agnosis' ),
				'input'   => 'password',
				'sanitize' => fn( $v ) => $v, // Don't sanitize passwords.
			],
			'agnosis_webhook_secret' => [
				'tab'     => 'email',
				'label'   => __( 'Webhook secret', 'agnosis' ),
				'desc'    => __( 'HMAC secret shared with your webhook provider (Mailgun, SendGrid…).', 'agnosis' ),
				'input'   => 'password',
				'sanitize' => fn( $v ) => $v,
			],
			'agnosis_imap_cleanup_days' => [
				'tab'      => 'email',
				'label'    => __( 'Inbox retention (days)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 7,
				'min'      => 1,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, (int) $v ),
				'desc'     => __( 'Seen IMAP messages and processed/failed queue rows older than this are permanently deleted by the daily cleanup. Default: 7 days.', 'agnosis' ),
			],

			// --- AI: Description (text) ---
			'agnosis_description_provider' => [
				'tab'     => 'ai',
				'label'   => __( 'Description provider', 'agnosis' ),
				'input'   => 'select',
				'options' => [
					'openai'    => 'OpenAI (GPT-4o)',
					'anthropic' => 'Anthropic (Claude)',
					'wp_ai'     => 'WordPress AI Services',
				],
				'default' => 'openai',
				'desc'    => __( 'Analyses the artwork image and writes the title, body, tags and alt text. WordPress AI Services delegates to whichever provider the site has configured via the AI Services plugin.', 'agnosis' ),
			],
			'agnosis_openai_description_model' => [
				'tab'     => 'ai',
				'label'   => __( 'OpenAI vision model', 'agnosis' ),
				'default' => 'gpt-4o',
				'desc'    => __( 'Model used for artwork description when OpenAI is the description provider. Must support vision input.', 'agnosis' ),
			],
			'agnosis_anthropic_model' => [
				'tab'     => 'ai',
				'label'   => __( 'Anthropic model', 'agnosis' ),
				'default' => 'claude-opus-4-8',
				'desc'    => __( 'Model used for artwork description when Anthropic is the description provider. Must support vision input.', 'agnosis' ),
			],

			// --- AI: Enhancement (image) ---
			'agnosis_enhancement_provider' => [
				'tab'     => 'ai',
				'label'   => __( 'Enhancement provider', 'agnosis' ),
				'input'   => 'select',
				'options' => [
					'auto'   => __( 'Auto (OpenAI if key is set)', 'agnosis' ),
					'openai' => 'OpenAI (gpt-image-1)',
					'none'   => __( 'Disabled — use original image', 'agnosis' ),
				],
				'default' => 'auto',
				'desc'    => __( 'Enhances the artwork image before publishing. Uses OpenAI gpt-image-1. Set to Disabled to skip enhancement and publish the original.', 'agnosis' ),
			],
			'agnosis_openai_image_model' => [
				'tab'     => 'ai',
				'label'   => __( 'OpenAI image model', 'agnosis' ),
				'default' => 'gpt-image-1',
				'desc'    => __( 'Model used for image enhancement when OpenAI is the enhancement provider.', 'agnosis' ),
			],

			// --- AI: Singleton post polish ---
			'agnosis_ai_polish_biography' => [
				'tab'     => 'ai',
				'label'   => __( 'Polish biography with AI', 'agnosis' ),
				'input'   => 'checkbox',
				'default' => '0',
				'desc'    => __( 'When enabled, biography submissions are passed through the AI to fix spelling and make minor text improvements before saving.', 'agnosis' ),
			],
			'agnosis_ai_polish_event' => [
				'tab'     => 'ai',
				'label'   => __( 'Polish events with AI', 'agnosis' ),
				'input'   => 'checkbox',
				'default' => '0',
				'desc'    => __( 'When enabled, event submissions are passed through the AI to fix spelling and make minor text improvements before saving.', 'agnosis' ),
			],

			// --- AI: Quality detection ---
			'agnosis_quality_rejection_threshold' => [
				'tab'      => 'ai',
				'label'    => __( 'Rejection threshold (quality score)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 3,
				'min'      => 0,
				'max'      => 10,
				'step'     => '1',
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 0, min( 10, (int) $v ) ),
				'desc'     => __( 'Photos scoring at or below this value (1–10) are automatically rejected — the artist receives a friendly email explaining the issue and is invited to resubmit. Score 0 disables automatic rejection. Must be lower than the enhancement threshold. Default: 3.', 'agnosis' ),
			],

			'agnosis_quality_threshold' => [
				'tab'      => 'ai',
				'label'    => __( 'Enhancement threshold (quality score)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 7,
				'min'      => 1,
				'max'      => 10,
				'step'     => '1',
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, min( 10, (int) $v ) ),
				'desc'     => __( 'Photos scoring below this value (1–10) are automatically enhanced. Score 10 = perfect photograph, 1 = technically unusable. Default: 7 — only visibly problematic photos are processed. Set to 1 to disable automatic enhancement entirely.', 'agnosis' ),
			],

			// --- AI: API keys ---
			'agnosis_openai_api_key' => [
				'tab'      => 'ai',
				'label'    => __( 'OpenAI API key', 'agnosis' ),
				'input'    => 'password',
				'sanitize' => fn( $v ) => $v,
				'desc'     => __( 'Required when OpenAI is the description or enhancement provider.', 'agnosis' ),
			],
			'agnosis_anthropic_api_key' => [
				'tab'      => 'ai',
				'label'    => __( 'Anthropic API key', 'agnosis' ),
				'input'    => 'password',
				'sanitize' => fn( $v ) => $v,
				'desc'     => __( 'Required when Anthropic is the description provider.', 'agnosis' ),
			],

			// --- BEHAVIOUR: Image sizes ---
			'agnosis_artwork_size_px' => [
				'tab'      => 'behavior',
				'label'    => __( 'Artwork display width (px)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 1920,
				'min'      => 400,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 400, (int) $v ),
				'desc'     => __( 'Width of the agnosis-artwork image size used in post content and lightbox. Height scales proportionally. Default: 1920. Existing images need to be regenerated after changing this value.', 'agnosis' ),
			],
			'agnosis_thumb_size_px' => [
				'tab'      => 'behavior',
				'label'    => __( 'Thumbnail size (px)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 512,
				'min'      => 64,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 64, (int) $v ),
				'desc'     => __( 'Side length of the square agnosis-thumb size used in submission cards and dashboard. Default: 512. Existing images need to be regenerated after changing this value.', 'agnosis' ),
			],
			'agnosis_email_size_px' => [
				'tab'      => 'behavior',
				'label'    => __( 'Email image width (px)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 420,
				'min'      => 200,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 200, (int) $v ),
				'desc'     => __( 'Width of the agnosis-email size used in artist notification emails. Height scales proportionally — no cropping. Default: 420. Existing images need to be regenerated after changing this value.', 'agnosis' ),
			],

			// --- BEHAVIOUR: Gallery overview ---
			'agnosis_gallery_per_page' => [
				'tab'      => 'behavior',
				'label'    => __( 'Gallery overview — items per page', 'agnosis' ),
				'input'    => 'number',
				'default'  => 12,
				'min'      => 3,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 3, (int) $v ),
				'desc'     => __( 'How many artwork cards the gallery overview shows per page. Pool is built proportionally across all artists; featured artworks are preferred. Default: 12.', 'agnosis' ),
			],

			// --- BEHAVIOUR: AI prompts ---
			'agnosis_prompt_system' => [
				'tab'      => 'behavior',
				'label'    => __( 'System prompt', 'agnosis' ),
				'input'    => 'textarea',
				'rows'     => 12,
				'sanitize' => 'sanitize_textarea_field',
				'default'  => \Agnosis\AI\PromptConfig::default_system_prompt(),
				'desc'     => __( 'Sent to the AI as the system instruction. Use {tag_count} and {excerpt_words} as tokens — they are replaced with the values below.', 'agnosis' ),
			],
			'agnosis_prompt_user_template' => [
				'tab'      => 'behavior',
				'label'    => __( 'Artist prompt template', 'agnosis' ),
				'input'    => 'textarea',
				'rows'     => 4,
				'sanitize' => 'sanitize_textarea_field',
				'default'  => \Agnosis\AI\PromptConfig::default_user_template(),
				'desc'     => __( 'User message sent alongside the artwork image. Use {artist_prompt} where the artist\'s own description should appear.', 'agnosis' ),
			],
			'agnosis_prompt_enhancement' => [
				'tab'      => 'behavior',
				'label'    => __( 'Enhancement instructions', 'agnosis' ),
				'input'    => 'textarea',
				'rows'     => 4,
				'sanitize' => 'sanitize_textarea_field',
				'default'  => \Agnosis\AI\PromptConfig::default_enhancement_instructions(),
				'desc'     => __( 'Instructions passed to the image enhancement provider. The AI-generated artwork description is appended automatically as context.', 'agnosis' ),
			],
			'agnosis_prompt_tag_count' => [
				'tab'      => 'behavior',
				'label'    => __( 'Number of tags', 'agnosis' ),
				'input'    => 'number',
				'default'  => 5,
				'min'      => 1,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, (int) $v ),
				'desc'     => __( 'How many tags the AI should generate per artwork. Referenced as {tag_count} in the system prompt.', 'agnosis' ),
			],
			'agnosis_prompt_excerpt_words' => [
				'tab'      => 'behavior',
				'label'    => __( 'Excerpt word limit', 'agnosis' ),
				'input'    => 'number',
				'default'  => 30,
				'min'      => 5,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 5, (int) $v ),
				'desc'     => __( 'Maximum words for the one-sentence excerpt. Referenced as {excerpt_words} in the system prompt.', 'agnosis' ),
			],

			// --- NETWORK ---
			'agnosis_activitypub_enabled' => [
				'tab'     => 'network',
				'label'   => __( 'Enable ActivityPub federation', 'agnosis' ),
				'input'   => 'checkbox',
				'default' => 1,
				'type'    => 'boolean',
				'desc'    => __( 'Broadcast new artworks to Mastodon, Pixelfed and the Fediverse.', 'agnosis' ),
				'sanitize' => fn( $v ) => (bool) $v,
			],
			'agnosis_public_key' => [
				'tab'   => 'network',
				'label' => __( 'Node public key', 'agnosis' ),
				'input' => 'readonly',
				'desc'  => __( 'Auto-generated RSA public key for this node. Share this with peer nodes.', 'agnosis' ),
			],
			'agnosis_admission_percent' => [
				'tab'     => 'network',
				'label'   => __( 'Admission vote threshold (%)', 'agnosis' ),
				'input'   => 'number',
				'default' => 10,
				'min'     => 0,
				'max'     => 100,
				'desc'    => __( 'Percentage of active artists that must vote yes for admission. Combined with the minimum floor below.', 'agnosis' ),
			],
			'agnosis_admission_minimum' => [
				'tab'     => 'network',
				'label'   => __( 'Admission minimum votes', 'agnosis' ),
				'input'   => 'number',
				'default' => 3,
				'min'     => 1,
				'desc'    => __( 'Absolute minimum positive votes required regardless of the percentage above.', 'agnosis' ),
			],
			'agnosis_admission_window_days' => [
				'tab'     => 'network',
				'label'   => __( 'Voting window (days)', 'agnosis' ),
				'input'   => 'number',
				'default' => 7,
				'min'     => 1,
				'desc'    => __( 'Days an application stays open. If the threshold is not reached within this window the application is rejected.', 'agnosis' ),
			],
			'agnosis_removal_nomination_threshold' => [
				'tab'      => 'network',
				'label'    => __( 'Removal nominations required', 'agnosis' ),
				'input'    => 'number',
				'default'  => 3,
				'min'      => 1,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, (int) $v ),
				'desc'     => __( 'Number of artist nominations needed before a community removal vote opens. Admins can bypass this threshold.', 'agnosis' ),
			],
			'agnosis_removal_window_days' => [
				'tab'      => 'network',
				'label'   => __( 'Removal vote window (days)', 'agnosis' ),
				'input'   => 'number',
				'default'  => 7,
				'min'      => 1,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, (int) $v ),
				'desc'     => __( 'Days a community removal vote stays open. A majority (>50%) of active artists must vote yes for removal to proceed.', 'agnosis' ),
			],

			// --- COMMERCE ---
			'agnosis_tx_fee_percent' => [
				'tab'     => 'commerce',
				'label'   => __( 'Transaction fee (%)', 'agnosis' ),
				'input'   => 'number',
				'default' => 7.0,
				'step'    => '0.5',
				'min'     => '0',
				'desc'    => __( 'Percentage retained by this node on donations and art sales. Artists pay nothing up front.', 'agnosis' ),
				'type'    => 'number',
				'sanitize' => fn( $v ) => (float) $v,
			],
		];
	}

	private function render_ai_test_tools(): void {
		$providers = [
			'openai'    => [
				'label' => 'OpenAI',
				'desc'  => __( 'Sends a minimal ping ("Reply with the word: ping") using the configured API key.', 'agnosis' ),
			],
			'anthropic' => [
				'label' => 'Anthropic',
				'desc'  => __( 'Sends a minimal ping using the configured API key.', 'agnosis' ),
			],
			'wp_ai'     => [
				'label' => __( 'WordPress AI Client', 'agnosis' ),
				'desc'  => __( 'Checks that wp_ai_client_prompt() is available (WordPress 7.0+) and a text-generation model is configured.', 'agnosis' ),
			],
		];
		?>
		<div class="card" style="max-width:800px;margin-top:1.5rem;padding:1rem 1.5rem">
			<h2 style="margin-top:0"><?php esc_html_e( 'Test AI Providers', 'agnosis' ); ?></h2>
			<table class="form-table" role="presentation" style="margin-bottom:0"><tbody>
				<?php foreach ( $providers as $slug => $info ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $info['label'] ); ?></th>
					<td>
						<button type="button"
							class="button button-secondary agnosis-test-ai"
							data-provider="<?php echo esc_attr( $slug ); ?>"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'agnosis_test_ai' ) ); ?>">
							<?php
							printf(
								/* translators: %s: provider label */
								esc_html__( 'Test %s', 'agnosis' ),
								esc_html( $info['label'] )
							);
							?>
						</button>
						<span class="agnosis-test-result" data-provider="<?php echo esc_attr( $slug ); ?>"
							  style="margin-left:.8rem;font-style:italic;color:#666"></span>
						<p class="description"><?php echo esc_html( $info['desc'] ); ?></p>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody></table>
		</div>
		<?php
	}

	private function render_logs_tab(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- integer page offset for display only.
		$page    = max( 1, (int) sanitize_key( wp_unslash( $_GET['log_page'] ?? '1' ) ) );
		$per     = 50;
		$offset  = ( $page - 1 ) * $per;
		$total   = Logger::count();
		$entries = Logger::get_entries( $per, $offset );
		$pages   = (int) ceil( $total / $per );

		$level_colours = [
			'info'    => '#0a7c48',
			'warning' => '#8a6d3b',
			'error'   => '#c0392b',
		];
		$level_bg = [
			'info'    => '#ecfdf5',
			'warning' => '#fef9e7',
			'error'   => '#fdf2f2',
		];

		?>
		<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem">
			<h2 style="margin:0"><?php esc_html_e( 'Pipeline Logs', 'agnosis' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="agnosis_clear_logs">
				<?php wp_nonce_field( 'agnosis_clear_logs' ); ?>
				<button type="submit" class="button button-secondary"
					onclick="return confirm('<?php echo esc_js( __( 'Clear all log entries?', 'agnosis' ) ); ?>')">
					<?php esc_html_e( 'Clear All', 'agnosis' ); ?>
				</button>
			</form>
		</div>

		<p class="description" style="margin-bottom:1rem">
			<?php
			printf(
				/* translators: %d: total log entry count */
				esc_html__( '%d entries total. Logs are pruned automatically according to the Inbox retention setting.', 'agnosis' ),
				(int) $total
			);
			?>
		</p>

		<?php if ( empty( $entries ) ) : ?>
			<p><?php esc_html_e( 'No log entries yet.', 'agnosis' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:1200px">
				<thead>
					<tr>
						<th style="width:160px"><?php esc_html_e( 'Time', 'agnosis' ); ?></th>
						<th style="width:80px"><?php esc_html_e( 'Level', 'agnosis' ); ?></th>
						<th style="width:120px"><?php esc_html_e( 'Context', 'agnosis' ); ?></th>
						<th><?php esc_html_e( 'Message', 'agnosis' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $entries as $entry ) :
						$lvl = $entry['level'] ?? 'info';
						$bg  = $level_bg[ $lvl ]  ?? '';
						$fg  = $level_colours[ $lvl ] ?? '#000';
						?>
						<tr style="background:<?php echo esc_attr( $bg ); ?>">
							<td style="white-space:nowrap;font-family:monospace;font-size:.85em">
								<?php echo esc_html( $entry['created_at'] ); ?>
							</td>
							<td>
								<span style="color:<?php echo esc_attr( $fg ); ?>;font-weight:600;text-transform:uppercase;font-size:.8em">
									<?php echo esc_html( $lvl ); ?>
								</span>
							</td>
							<td style="font-family:monospace;font-size:.85em;color:#555">
								<?php echo esc_html( $entry['context'] ); ?>
							</td>
							<td><?php echo esc_html( $entry['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div style="margin-top:1rem">
					<?php for ( $p = 1; $p <= $pages; $p++ ) : ?>
						<?php if ( $p === $page ) : ?>
							<strong>[<?php echo (int) $p; ?>]</strong>
						<?php else : ?>
							<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'agnosis-settings', 'tab' => 'logs', 'log_page' => $p ], admin_url( 'admin.php' ) ) ); ?>">
								<?php echo (int) $p; ?>
							</a>
						<?php endif; ?>
					<?php endfor; ?>
				</div>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	private function render_tab_tools( string $tab ): void {
		if ( 'ai' === $tab ) {
			$this->render_ai_test_tools();
			return;
		}
		if ( 'network' === $tab ) {
			$this->render_admission_dashboard();
			$this->render_members_dashboard();
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
	}


	// -------------------------------------------------------------------------
	// Admission dashboard (Network tab)
	// -------------------------------------------------------------------------

	/**
	 * Render the pending applications table on the Network tab.
	 *
	 * Shows every application in status='pending' with vouch counts and
	 * admin-override Admit / Reject buttons.
	 */
	private function render_admission_dashboard(): void {
		global $wpdb;

		$admission = new Admission();
		$required  = $admission->calculate_required();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$applications = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}agnosis_applications
			 WHERE status = 'pending'
			 ORDER BY applied_at ASC"
		);

		?>
		<div class="card" style="max-width:900px;margin-top:1.5rem;padding:1rem 1.5rem">
			<h2 style="margin-top:0"><?php esc_html_e( 'Pending Applications', 'agnosis' ); ?></h2>

			<?php if ( empty( $applications ) ) : ?>
				<p style="color:#666"><?php esc_html_e( 'No pending applications.', 'agnosis' ); ?></p>
			<?php else : ?>
				<p class="description" style="margin-bottom:1rem">
					<?php
					printf(
						/* translators: %d: number of positive votes currently required for admission */
						esc_html__( '%d positive vote(s) currently required for admission.', 'agnosis' ),
						(int) $required
					);
					?>
				</p>
				<table class="widefat striped" style="border-radius:4px;overflow:hidden">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Applicant', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'Applied', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'Votes', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'Bio / Portfolio', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'agnosis' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $applications as $app ) : ?>
						<?php
						/** @var object{id: string, display_name: string, email: string, applied_at: string, bio: string|null, portfolio_url: string|null} $app */
						$app_id  = (int) $app->id;
						$yes     = $admission->count_positive_vouches( $app_id );
						$bar_pct = $required > 0 ? min( 100, (int) round( $yes / $required * 100 ) ) : 100;
						$bar_col = $yes >= $required ? '#00a32a' : '#2271b1';
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $app->display_name ); ?></strong><br>
								<span style="color:#666;font-size:12px"><?php echo esc_html( $app->email ); ?></span>
							</td>
							<td style="white-space:nowrap"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $app->applied_at ) ) ); ?></td>
							<td style="min-width:120px">
								<div style="background:#ddd;border-radius:3px;height:6px;margin-bottom:4px">
									<div style="background:<?php echo esc_attr( $bar_col ); ?>;width:<?php echo esc_attr( (string) $bar_pct ); ?>%;height:6px;border-radius:3px"></div>
								</div>
								<?php
								printf(
									/* translators: 1: yes votes received, 2: total votes required */
									esc_html__( '%1$d / %2$d', 'agnosis' ),
									(int) $yes,
									(int) $required
								);
								?>
							</td>
							<td style="max-width:260px;font-size:12px">
								<?php if ( $app->bio ) : ?>
									<span title="<?php echo esc_attr( $app->bio ); ?>"><?php echo esc_html( wp_trim_words( $app->bio, 20 ) ); ?></span>
								<?php endif; ?>
								<?php if ( $app->portfolio_url ) : ?>
									<br><a href="<?php echo esc_url( $app->portfolio_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Portfolio ↗', 'agnosis' ); ?></a>
								<?php endif; ?>
							</td>
							<td style="white-space:nowrap">
								<!-- Admit -->
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<input type="hidden" name="action" value="agnosis_admit_application">
									<input type="hidden" name="application_id" value="<?php echo esc_attr( (string) $app_id ); ?>">
									<?php wp_nonce_field( 'agnosis_admit_' . $app_id, 'agnosis_nonce' ); ?>
									<?php submit_button( __( 'Admit', 'agnosis' ), 'small', 'submit', false ); ?>
								</form>
								<!-- Reject -->
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:4px">
									<input type="hidden" name="action" value="agnosis_reject_application">
									<input type="hidden" name="application_id" value="<?php echo esc_attr( (string) $app_id ); ?>">
									<?php wp_nonce_field( 'agnosis_reject_' . $app_id, 'agnosis_nonce' ); ?>
									<?php submit_button( __( 'Reject', 'agnosis' ), 'small delete', 'submit', false ); ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * admin-post handler: admit an applicant, bypassing the vouch threshold.
	 */
	public function handle_admit_application(): void {
		$app_id = absint( wp_unslash( $_POST['application_id'] ?? 0 ) );

		check_admin_referer( 'agnosis_admit_' . $app_id, 'agnosis_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$admission = new Admission();
		$ok        = $admission->admin_admit( $app_id );

		$redirect = add_query_arg(
			[
				'page'            => 'agnosis-settings',
				'tab'             => 'network',
				'agnosis_message' => $ok ? 'admitted' : 'admit_failed',
			],
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * admin-post handler: reject an applicant.
	 */
	public function handle_reject_application(): void {
		$app_id = absint( wp_unslash( $_POST['application_id'] ?? 0 ) );

		check_admin_referer( 'agnosis_reject_' . $app_id, 'agnosis_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$admission = new Admission();
		$ok        = $admission->admin_reject( $app_id );

		$redirect = add_query_arg(
			[
				'page'            => 'agnosis-settings',
				'tab'             => 'network',
				'agnosis_message' => $ok ? 'rejected' : 'reject_failed',
			],
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	// -------------------------------------------------------------------------
	// Members dashboard (Network tab)
	// -------------------------------------------------------------------------

	/**
	 * Render the admitted (and banned) members table on the Network tab.
	 *
	 * Shows every admitted/banned artist with Ban / Delete / Initiate Vote actions.
	 */
	private function render_members_dashboard(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$members = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}agnosis_applications
			 WHERE status IN ('admitted', 'banned')
			 ORDER BY display_name ASC"
		);

		?>
		<div class="card" style="max-width:960px;margin-top:1.5rem;padding:1rem 1.5rem">
			<h2 style="margin-top:0"><?php esc_html_e( 'Members', 'agnosis' ); ?></h2>

			<?php if ( empty( $members ) ) : ?>
				<p style="color:#666"><?php esc_html_e( 'No admitted members yet.', 'agnosis' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="border-radius:4px;overflow:hidden">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Artist', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'Status', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'Joined', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'agnosis' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $members as $member ) : ?>
						<?php
						/** @var object{id: string, wp_user_id: string|null, display_name: string, email: string, status: string, resolved_at: string|null, banned_until: string|null} $member */
						$app_id     = (int) $member->id;
						$is_banned  = 'banned' === $member->status;
						$status_col = $is_banned ? '#c0392b' : '#0a7c48';
						$status_lbl = $is_banned
							? ( $member->banned_until
								? sprintf(
									/* translators: %s: date until which the artist is banned */
									__( 'Banned until %s', 'agnosis' ),
									date_i18n( get_option( 'date_format' ), strtotime( $member->banned_until ) )
								)
								: __( 'Banned', 'agnosis' ) )
							: __( 'Active', 'agnosis' );
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $member->display_name ); ?></strong><br>
								<span style="color:#666;font-size:12px"><?php echo esc_html( $member->email ); ?></span>
							</td>
							<td>
								<span style="color:<?php echo esc_attr( $status_col ); ?>;font-weight:600;font-size:12px">
									<?php echo esc_html( $status_lbl ); ?>
								</span>
							</td>
							<td style="white-space:nowrap;font-size:12px">
								<?php echo $member->resolved_at ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $member->resolved_at ) ) ) : '—'; ?>
							</td>
							<td style="white-space:nowrap">
								<?php if ( ! $is_banned ) : ?>
									<!-- Temporary ban -->
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
										  style="display:inline;margin-right:4px"
										  onsubmit="return this.querySelector('[name=banned_until]').value !== '' || confirm('<?php echo esc_js( __( 'Leave the date blank to ban indefinitely. Continue?', 'agnosis' ) ); ?>')">
										<input type="hidden" name="action" value="agnosis_ban_artist">
										<input type="hidden" name="application_id" value="<?php echo esc_attr( (string) $app_id ); ?>">
										<input type="date" name="banned_until" style="font-size:12px;padding:2px 4px"
											   min="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( '+1 day' ) ) ); ?>"
											   title="<?php esc_attr_e( 'Leave blank to ban indefinitely', 'agnosis' ); ?>">
										<?php wp_nonce_field( 'agnosis_ban_' . $app_id, 'agnosis_nonce' ); ?>
										<?php submit_button( __( 'Suspend', 'agnosis' ), 'small', 'submit', false ); ?>
									</form>
								<?php endif; ?>
								<!-- Permanent delete -->
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
									  style="display:inline;margin-right:4px"
									  onsubmit="return confirm('<?php echo esc_js( __( 'Permanently delete this artist and all their content? This cannot be undone.', 'agnosis' ) ); ?>')">
									<input type="hidden" name="action" value="agnosis_delete_artist">
									<input type="hidden" name="application_id" value="<?php echo esc_attr( (string) $app_id ); ?>">
									<?php wp_nonce_field( 'agnosis_delete_' . $app_id, 'agnosis_nonce' ); ?>
									<?php submit_button( __( 'Delete', 'agnosis' ), 'small delete', 'submit', false ); ?>
								</form>
								<?php if ( ! $is_banned ) : ?>
									<!-- Initiate community vote -->
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
										  style="display:inline"
										  onsubmit="return confirm('<?php echo esc_js( __( 'Open a community removal vote for this artist? All members will be emailed.', 'agnosis' ) ); ?>')">
										<input type="hidden" name="action" value="agnosis_initiate_removal_vote">
										<input type="hidden" name="application_id" value="<?php echo esc_attr( (string) $app_id ); ?>">
										<?php wp_nonce_field( 'agnosis_vote_' . $app_id, 'agnosis_nonce' ); ?>
										<?php submit_button( __( 'Open Vote', 'agnosis' ), 'small', 'submit', false ); ?>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * admin-post handler: suspend (ban) an artist, optionally until a date.
	 */
	public function handle_ban_artist(): void {
		$app_id = absint( wp_unslash( $_POST['application_id'] ?? 0 ) );

		check_admin_referer( 'agnosis_ban_' . $app_id, 'agnosis_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$raw_until = sanitize_text_field( wp_unslash( $_POST['banned_until'] ?? '' ) );
		$until     = ( '' !== $raw_until && strtotime( $raw_until ) )
			? ( new \DateTimeImmutable( $raw_until ) )
			: null;

		$departure = new Departure();
		$ok        = $departure->admin_ban( $app_id, $until );

		wp_safe_redirect( add_query_arg(
			[
				'page'            => 'agnosis-settings',
				'tab'             => 'network',
				'agnosis_message' => $ok ? 'banned' : 'ban_failed',
			],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * admin-post handler: permanently delete an artist and all their content.
	 */
	public function handle_delete_artist(): void {
		$app_id = absint( wp_unslash( $_POST['application_id'] ?? 0 ) );

		check_admin_referer( 'agnosis_delete_' . $app_id, 'agnosis_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$departure = new Departure();
		$ok        = $departure->admin_delete( $app_id );

		wp_safe_redirect( add_query_arg(
			[
				'page'            => 'agnosis-settings',
				'tab'             => 'network',
				'agnosis_message' => $ok ? 'deleted' : 'delete_failed',
			],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * admin-post handler: open a community removal vote for an artist (admin bypass).
	 */
	public function handle_initiate_removal_vote(): void {
		$app_id = absint( wp_unslash( $_POST['application_id'] ?? 0 ) );

		check_admin_referer( 'agnosis_vote_' . $app_id, 'agnosis_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		global $wpdb;

		// Resolve subject user_id from application.
		$user_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}agnosis_applications WHERE id = %d",
				$app_id
			)
		);

		if ( ! $user_id ) {
			wp_safe_redirect( add_query_arg(
				[ 'page' => 'agnosis-settings', 'tab' => 'network', 'agnosis_message' => 'vote_open_failed' ],
				admin_url( 'admin.php' )
			) );
			exit;
		}

		$departure = new Departure();
		$ok        = $departure->admin_open_removal_vote( $user_id, get_current_user_id() );

		wp_safe_redirect( add_query_arg(
			[
				'page'            => 'agnosis-settings',
				'tab'             => 'network',
				'agnosis_message' => $ok ? 'vote_opened' : 'vote_open_failed',
			],
			admin_url( 'admin.php' )
		) );
		exit;
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

	private function admin_css(): string {
		return '
		.agnosis-settings h1 { display:flex; align-items:baseline; gap:.4rem; }
		.agnosis-settings .nav-tab-active { border-bottom-color:#7c6af7; color:#7c6af7; }
		';
	}

	// -------------------------------------------------------------------------
	// admin_post / wp_ajax handlers
	// -------------------------------------------------------------------------

	/** AJAX handler — ping an AI provider with a minimal request. */
	public function handle_test_ai(): void {
		check_ajax_referer( 'agnosis_test_ai', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'agnosis' ) ] );
		}

		$provider = sanitize_key( wp_unslash( $_POST['provider'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		switch ( $provider ) {

			case 'openai':
				$key = (string) get_option( 'agnosis_openai_api_key', '' );
				if ( empty( $key ) ) {
					wp_send_json_error( [ 'message' => __( 'OpenAI API key not configured.', 'agnosis' ) ] );
				}
				$this->ping_provider(
					'https://api.openai.com/v1/chat/completions',
					[ 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ],
					[ 'model' => 'gpt-4o-mini', 'messages' => [ [ 'role' => 'user', 'content' => 'Reply with the single word: ping' ] ], 'max_tokens' => 5 ],
					__( 'OpenAI connection successful.', 'agnosis' )
				);
				// no break — ping_provider() always calls wp_send_json_* → wp_die().

			case 'anthropic':
				$key = (string) get_option( 'agnosis_anthropic_api_key', '' );
				if ( empty( $key ) ) {
					wp_send_json_error( [ 'message' => __( 'Anthropic API key not configured.', 'agnosis' ) ] );
				}
				$this->ping_provider(
					'https://api.anthropic.com/v1/messages',
					[ 'x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'Content-Type' => 'application/json' ],
					[ 'model' => 'claude-haiku-4-5-20251001', 'max_tokens' => 5, 'messages' => [ [ 'role' => 'user', 'content' => 'Reply with the single word: ping' ] ] ],
					__( 'Anthropic connection successful.', 'agnosis' )
				);
				// no break — ping_provider() always calls wp_send_json_* → wp_die().

			case 'wp_ai':
				if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
					wp_send_json_error( [ 'message' => __( 'WordPress AI Client requires WordPress 7.0 or later.', 'agnosis' ) ] );
				}
				// call_user_func avoids Plugin Check static-analysis flag.
				// @phpstan-ignore-next-line
				$builder = call_user_func( 'wp_ai_client_prompt', 'ping' );
				if ( ! $builder->is_supported_for_text_generation() ) {
					wp_send_json_error( [ 'message' => __( 'No text-generation model configured. Set one up under Settings → Connectors.', 'agnosis' ) ] );
				}
				wp_send_json_success( [ 'message' => __( 'WordPress AI Client is available and a text-generation model is configured.', 'agnosis' ) ] );
				// no break — wp_send_json_success() calls wp_die().

			default:
				wp_send_json_error( [ 'message' => __( 'Unknown provider.', 'agnosis' ) ] );
		}
	}

	/**
	 * POST to an AI provider endpoint and send a JSON response.
	 *
	 * Encodes $body as JSON, POSTs to $url with $headers, then:
	 *  - Calls wp_send_json_error() on transport failure or non-200 status.
	 *  - Calls wp_send_json_success() on 200.
	 *
	 * Both wp_send_json_* functions call wp_die(), so this method never returns.
	 *
	 * @param string               $url         Provider API endpoint.
	 * @param array<string,string> $headers     Request headers (auth + Content-Type).
	 * @param array<string,mixed>  $body        Request payload (JSON-encoded before sending).
	 * @param string               $success_msg Localised success message shown to the admin.
	 */
	private function ping_provider( string $url, array $headers, array $body, string $success_msg ): void {
		$json_body = wp_json_encode( $body );
		$response  = wp_remote_post( $url, [
			'timeout' => 10,
			'headers' => $headers,
			'body'    => false !== $json_body ? $json_body : '{}',
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => $response->get_error_message() ] );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$resp_body = json_decode( wp_remote_retrieve_body( $response ), true );
			/* translators: %d: HTTP response status code */
			$msg = $resp_body['error']['message'] ?? sprintf( __( 'HTTP %d', 'agnosis' ), $code );
			wp_send_json_error( [ 'message' => $msg ] );
		}

		wp_send_json_success( [ 'message' => $success_msg ] );
	}

	/** admin_post handler — clear all pipeline log entries. */
	public function handle_clear_logs(): void {
		check_admin_referer( 'agnosis_clear_logs' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		Logger::clear();

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'agnosis-settings', 'tab' => 'logs', 'cleared' => '1' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
