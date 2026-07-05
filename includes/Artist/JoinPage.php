<?php
/**
 * Public join form — agnosis/join dynamic block.
 *
 * Renders an application form that POSTs to the unauthenticated
 * POST /agnosis/v1/admission/apply REST endpoint.
 *
 * No WP account is required to submit. Rate limiting is handled by the
 * endpoint itself. If the current user is already an admitted artist the
 * block renders a short confirmation message instead of the form.
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

use Agnosis\AI\SubmissionTranslator;
use Agnosis\Core\Turnstile;

class JoinPage {

	/**
	 * Register the agnosis/join dynamic block.
	 *
	 * block.json lives in blocks/join/ relative to the plugin root.
	 */
	public function register_block(): void {
		register_block_type(
			AGNOSIS_DIR . 'blocks/join',
			[ 'render_callback' => [ $this, 'render_block' ] ]
		);
	}

	/**
	 * PHP render_callback for the agnosis/join block.
	 *
	 * @param array<string, mixed> $attributes Block attributes (none defined).
	 * @return string HTML output.
	 */
	public function render_block( array $attributes = [] ): string {
		return $this->render();
	}

	// -------------------------------------------------------------------------
	// Renderer
	// -------------------------------------------------------------------------

	private function render(): string {
		if ( is_user_logged_in() && $this->is_artist( get_current_user_id() ) ) {
			return '<div class="agnosis-join agnosis-join--admitted"><p>'
				. esc_html__( 'You are already an admitted Agnosis artist.', 'agnosis' )
				. '</p></div>';
		}

		$this->enqueue_assets();

		ob_start();
		?>
		<div class="agnosis-join" id="agnosis-join">

			<div id="agnosis-join-notice" class="agnosis-join__notice" hidden></div>

			<form class="agnosis-join__form" id="agnosis-join-form" novalidate>

				<div class="agnosis-join__field">
					<label for="agnosis-join-name">
						<?php esc_html_e( 'Your name', 'agnosis' ); ?>
						<abbr title="<?php esc_attr_e( 'required', 'agnosis' ); ?>"> *</abbr>
					</label>
					<input
						type="text"
						id="agnosis-join-name"
						name="display_name"
						autocomplete="name"
						required
					/>
				</div>

				<div class="agnosis-join__field">
					<label for="agnosis-join-email">
						<?php esc_html_e( 'Email address', 'agnosis' ); ?>
						<abbr title="<?php esc_attr_e( 'required', 'agnosis' ); ?>"> *</abbr>
					</label>
					<input
						type="email"
						id="agnosis-join-email"
						name="email"
						autocomplete="email"
						required
					/>
				</div>

				<div class="agnosis-join__field">
					<label for="agnosis-join-bio"><?php esc_html_e( 'Short bio', 'agnosis' ); ?></label>
					<textarea
						id="agnosis-join-bio"
						name="bio"
						rows="4"
					></textarea>
					<span class="agnosis-join__hint">
						<?php esc_html_e( 'A few sentences about your practice, medium, and background.', 'agnosis' ); ?>
					</span>
				</div>

				<div class="agnosis-join__field">
					<label for="agnosis-join-portfolio"><?php esc_html_e( 'Portfolio URL', 'agnosis' ); ?></label>
					<input
						type="url"
						id="agnosis-join-portfolio"
						name="portfolio_url"
						autocomplete="url"
						placeholder="https://"
					/>
				</div>

				<div class="agnosis-join__field">
					<label for="agnosis-join-statement"><?php esc_html_e( 'Why do you want to join?', 'agnosis' ); ?></label>
					<textarea
						id="agnosis-join-statement"
						name="statement"
						rows="5"
					></textarea>
				</div>

				<div class="agnosis-join__field">
					<label for="agnosis-join-language"><?php esc_html_e( 'Language you work in', 'agnosis' ); ?></label>
					<select id="agnosis-join-language" name="language" required>
						<?php
						// No auto-detect option: an artist's language must be one Lingua
						// Forge is actually configured to support on this site (Settings →
						// Language Router). Guessing from the browser's Accept-Language and
						// silently accepting it risks telling an artist their language
						// works when it doesn't — that only surfaces as a broken experience
						// later (untranslated content, no matching locale). The artist must
						// explicitly pick one of the languages this instance really
						// supports; the placeholder is disabled so it can't be submitted
						// as-is and the select is required.
						printf(
							'<option value="" disabled selected>%s</option>',
							esc_html__( 'Select your language', 'agnosis' )
						);
						// Language list is exactly what Lingua Forge is configured for on
						// this site — 3 active languages means 3 options here, 50 means 50.
						// Falls back to just the site's own locale if Lingua Forge isn't
						// active at all. Sorted alphabetically by ISO code (not display
						// label — labels come from PHP's intl display-language lookup and
						// aren't guaranteed sortable together across scripts/alphabets,
						// while codes always are) so the list reads predictably regardless
						// of how many languages are configured.
						$languages = SubmissionTranslator::language_names();
						ksort( $languages );
						foreach ( $languages as $code => $label ) {
							printf(
								'<option value="%s">%s</option>',
								esc_attr( (string) $code ),
								esc_html( $label )
							);
						}
						?>
					</select>
					<span class="agnosis-join__hint">
						<?php esc_html_e( 'Helps us communicate and translate content in your language.', 'agnosis' ); ?>
					</span>
				</div>

				<?php echo Turnstile::render_widget(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_widget() escapes the site key internally. ?>

				<button type="submit" class="agnosis-join__submit">
					<?php esc_html_e( 'Apply', 'agnosis' ); ?>
				</button>

			</form>

		</div><!-- #agnosis-join -->
		<?php
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	private function enqueue_assets(): void {
		Turnstile::enqueue_script();

		wp_enqueue_script(
			'agnosis-join',
			AGNOSIS_URL . 'blocks/join/frontend.js',
			[],
			AGNOSIS_VERSION,
			[ 'in_footer' => true ]
		);

		wp_localize_script( 'agnosis-join', 'agnosisJoin', [
			'apiUrl' => rest_url( 'agnosis/v1/admission/apply' ),
			'i18n'   => [
				'success'   => __( 'Your application has been received. The community will be in touch!', 'agnosis' ),
				'error'     => __( 'Something went wrong. Please try again.', 'agnosis' ),
			],
		] );

		wp_enqueue_style(
			'agnosis-join',
			AGNOSIS_URL . 'blocks/join/frontend.css',
			[],
			AGNOSIS_VERSION
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function is_artist( int $user_id ): bool {
		$user = get_userdata( $user_id );
		return $user
			&& ( in_array( 'agnosis_artist', (array) $user->roles, true )
				|| user_can( $user_id, 'manage_options' ) );
	}
}
