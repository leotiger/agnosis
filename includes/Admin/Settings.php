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

class Settings {

	private const PAGE   = 'agnosis-settings';
	private const GROUP  = 'agnosis_options';

	public function register_menu(): void {
		add_menu_page(
			__( 'Agnosis', 'agnosis' ),
			__( 'Agnosis', 'agnosis' ),
			'manage_options',
			self::PAGE,
			[ $this, 'render_page' ],
			$this->menu_icon(),
			58 // Below WooCommerce (56), above Appearance (60).
		);
	}

	public function register_settings(): void {
		$fields = $this->field_definitions();

		foreach ( $fields as $key => $field ) {
			register_setting( self::GROUP, $key, [
				'type'              => $field['type'] ?? 'string',
				'sanitize_callback' => $field['sanitize'] ?? 'sanitize_text_field',
				'default'           => $field['default'] ?? '',
			] );
		}
	}

	public function enqueue_assets( string $hook ): void {
		if ( $hook !== 'toplevel_page_' . self::PAGE ) {
			return;
		}
		// Inline minimal CSS — no external dependency needed for MVP.
		wp_add_inline_style( 'wp-admin', $this->admin_css() );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading tab slug for display only, no data mutation.
		$active_tab = sanitize_key( $_GET['tab'] ?? 'general' );
		$tabs       = $this->tabs();

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

			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUP );
				$this->render_tab( $active_tab );
				submit_button( __( 'Save Changes', 'agnosis' ) );
				?>
			</form>
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
			'network'  => __( 'Network',      'agnosis' ),
			'commerce' => __( 'Commerce',     'agnosis' ),
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
			'agnosis_imap_user' => [
				'tab'   => 'email',
				'label' => __( 'Submission email address', 'agnosis' ),
				'desc'  => __( 'Artists send their work to this address — e.g. submit@agnosis.art', 'agnosis' ),
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

			// --- AI ---
			'agnosis_ai_provider' => [
				'tab'     => 'ai',
				'label'   => __( 'Description provider', 'agnosis' ),
				'input'   => 'select',
				'options' => [ 'openai' => 'OpenAI (GPT-4o)', 'anthropic' => 'Anthropic (Claude)' ],
				'default' => 'openai',
				'desc'    => __( 'Analyses the artwork and writes the title, text and tags.', 'agnosis' ),
			],
			'agnosis_openai_api_key' => [
				'tab'     => 'ai',
				'label'   => __( 'OpenAI API key', 'agnosis' ),
				'input'   => 'password',
				'sanitize' => fn( $v ) => $v,
				'desc'    => __( 'Used for GPT-4o Vision (description) and gpt-image-1 (enhancement).', 'agnosis' ),
			],
			'agnosis_anthropic_api_key' => [
				'tab'     => 'ai',
				'label'   => __( 'Anthropic API key', 'agnosis' ),
				'input'   => 'password',
				'sanitize' => fn( $v ) => $v,
				'desc'    => __( 'Used for Claude Vision — description only.', 'agnosis' ),
			],
			'agnosis_stability_api_key' => [
				'tab'     => 'ai',
				'label'   => __( 'Stability AI API key', 'agnosis' ),
				'input'   => 'password',
				'sanitize' => fn( $v ) => $v,
				'desc'    => __( 'Used for image upscaling and enhancement (preferred over OpenAI for images).', 'agnosis' ),
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

	private function menu_icon(): string {
		// SVG ✦ sparkle in WordPress menu grey (#a7aaad), base64-encoded.
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a7aaad" d="M10 0l1.8 7.2L19 10l-7.2 1.8L10 20l-1.8-8.2L1 10l8.2-1.8z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	private function admin_css(): string {
		return '
		.agnosis-settings h1 { display:flex; align-items:baseline; gap:.4rem; }
		.agnosis-settings .nav-tab-active { border-bottom-color:#7c6af7; color:#7c6af7; }
		#adminmenu .toplevel_page_agnosis-settings .wp-menu-image img { opacity:.7; }
		#adminmenu .toplevel_page_agnosis-settings.current .wp-menu-image img,
		#adminmenu .toplevel_page_agnosis-settings:hover .wp-menu-image img { opacity:1; filter:brightness(0) saturate(100%) invert(52%) sepia(60%) saturate(500%) hue-rotate(220deg); }
		';
	}
}
