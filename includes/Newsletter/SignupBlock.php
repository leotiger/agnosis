<?php
/**
 * Public newsletter signup form — agnosis/newsletter-signup dynamic block.
 *
 * Renders an email-only form that POSTs to the unauthenticated
 * POST /agnosis/v1/newsletter/subscribe REST endpoint. Mirrors
 * Artist\JoinPage in structure and conventions.
 *
 * @package Agnosis\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Newsletter;

use Agnosis\Core\Turnstile;

class SignupBlock {

	/**
	 * Register the agnosis/newsletter-signup dynamic block.
	 *
	 * block.json lives in blocks/newsletter-signup/ relative to the plugin root.
	 */
	public function register_block(): void {
		register_block_type(
			AGNOSIS_DIR . 'blocks/newsletter-signup',
			[ 'render_callback' => [ $this, 'render_block' ] ]
		);
	}

	/**
	 * PHP render_callback for the agnosis/newsletter-signup block.
	 *
	 * @param array<string, mixed> $attributes Block attributes (none defined).
	 */
	public function render_block( array $attributes = [] ): string {
		if ( ! get_option( 'agnosis_newsletter_public_enabled' ) ) {
			return '';
		}

		$this->enqueue_assets();

		ob_start();
		?>
		<div class="agnosis-newsletter-signup">

			<div class="agnosis-newsletter-signup__notice" hidden></div>

			<form class="agnosis-newsletter-signup__form" novalidate>
				<div class="agnosis-newsletter-signup__field">
					<label for="agnosis-newsletter-signup-email">
						<?php esc_html_e( 'Email address', 'agnosis' ); ?>
					</label>
					<input
						type="email"
						id="agnosis-newsletter-signup-email"
						name="email"
						autocomplete="email"
						placeholder="<?php esc_attr_e( 'you@example.com', 'agnosis' ); ?>"
						required
					/>
				</div>
				<?php echo Turnstile::render_widget(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_widget() escapes the site key internally. ?>
				<button type="submit" class="agnosis-newsletter-signup__submit">
					<span><?php esc_html_e( 'Subscribe', 'agnosis' ); ?></span>
				</button>
			</form>

		</div><!-- .agnosis-newsletter-signup -->
		<?php
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	private function enqueue_assets(): void {
		Turnstile::enqueue_script();

		wp_enqueue_script(
			'agnosis-newsletter-signup',
			AGNOSIS_URL . 'blocks/newsletter-signup/frontend.js',
			[],
			AGNOSIS_VERSION,
			[ 'in_footer' => true ]
		);

		wp_localize_script( 'agnosis-newsletter-signup', 'agnosisNewsletter', [
			'apiUrl' => rest_url( 'agnosis/v1/newsletter/subscribe' ),
			'i18n'   => [
				'success' => __( 'Almost there — check your inbox for a confirmation link.', 'agnosis' ),
				'error'   => __( 'Something went wrong. Please try again.', 'agnosis' ),
			],
		] );

		wp_enqueue_style(
			'agnosis-newsletter-signup',
			AGNOSIS_URL . 'blocks/newsletter-signup/frontend.css',
			[],
			AGNOSIS_VERSION
		);
	}
}
