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
			'agnosis_quality_threshold' => [
				'tab'      => 'ai',
				'label'    => __( 'Enhancement threshold (quality score)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 7,
				'min'      => 1,
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
			'agnosis_vouches_required' => [
				'tab'     => 'network',
				'label'   => __( 'Vouches required for admission', 'agnosis' ),
				'input'   => 'number',
				'default' => 2,
				'min'     => 1,
				'desc'    => __( 'How many existing artists must vouch before a new artist is admitted.', 'agnosis' ),
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


	private function ai_test_js(): string {
		$ajax_url = esc_js( admin_url( 'admin-ajax.php' ) );
		return <<<JS
document.addEventListener('click', function(e) {
	var btn = e.target.closest('.agnosis-test-ai');
	if (!btn) return;
	var provider = btn.dataset.provider;
	var nonce    = btn.dataset.nonce;
	var result   = document.querySelector('.agnosis-test-result[data-provider="' + provider + '"]');
	if (!result) return;

	btn.disabled = true;
	result.style.color = '#666';
	result.textContent = 'Testing…';

	var body = new URLSearchParams({ action: 'agnosis_test_ai', provider: provider, nonce: nonce });
	fetch('{$ajax_url}', { method: 'POST', credentials: 'same-origin', body: body })
		.then(function(r) { return r.json(); })
		.then(function(res) {
			if (res && res.success) {
				result.style.color = '#0a7c48';
				result.textContent = '✓ ' + ((res.data && res.data.message) || 'OK');
			} else {
				result.style.color = '#c0392b';
				result.textContent = '✗ ' + ((res.data && res.data.message) || 'Request failed');
			}
		})
		.catch(function() {
			result.style.color = '#c0392b';
			result.textContent = '✗ Request failed';
		})
		.finally(function() { btn.disabled = false; });
});
JS;
	}

	private function admin_css(): string {
		return '
		.agnosis-settings h1 { display:flex; align-items:baseline; gap:.4rem; }
		.agnosis-settings .nav-tab-active { border-bottom-color:#7c6af7; color:#7c6af7; }
		';
	}
}
