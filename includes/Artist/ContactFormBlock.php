<?php
/**
 * Visitor-to-artist contact form — agnosis/contact-form dynamic block.
 *
 * The form itself (fields + Turnstile widget), meant to live inside the
 * popover panel Network\SubdomainNavigation::render_breadcrumb_icon_link_block()
 * builds for its `type=contact` breadcrumb icon — same "the popover class
 * embeds the plain form block via render_block()" split
 * Newsletter\PopoverBlock/SignupBlock already established. Kept as its own
 * registered block (not inlined into the popover markup) for the same reason
 * agnosis/newsletter-signup is: a theme or admin can drop
 * `<!-- wp:agnosis/contact-form /-->` anywhere on an artist's own subdomain
 * page directly, without the popover chrome, if that's ever wanted.
 *
 * Like agnosis/artist-name-link and agnosis/breadcrumb-icon-link, this
 * resolves which artist it's for from SubdomainRouter::current_artist_id()
 * rather than a block attribute — there is only ever one "current artist" per
 * subdomain request, so there's nothing for a caller to pass in, and this
 * keeps SubdomainRouter as the single source of truth every other
 * subdomain-aware block already defers to.
 *
 * Renders nothing at all — not an empty element — off an artist subdomain,
 * or when the current artist has turned the form off
 * (Artist\ContactForm::artist_accepts_contact(), the exact same check
 * ContactForm::submit() enforces server-side) — same "nothing, not an empty
 * element" convention every other subdomain-gated block in this plugin uses.
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

use Agnosis\Compat\LinguaForge;
use Agnosis\Core\Privacy;
use Agnosis\Core\Turnstile;
use Agnosis\Network\SubdomainRouter;

class ContactFormBlock {

	/**
	 * Register the agnosis/contact-form dynamic block.
	 *
	 * block.json lives in blocks/contact-form/ relative to the plugin root.
	 */
	public function register_block(): void {
		register_block_type(
			\AGNOSIS_DIR . 'blocks/contact-form',
			[ 'render_callback' => [ $this, 'render_block' ] ]
		);
	}

	/**
	 * PHP render_callback for the agnosis/contact-form block.
	 *
	 * @param array<string, mixed> $attributes Block attributes (none defined).
	 * @return string
	 */
	public function render_block( array $attributes = [] ): string {
		$artist_id = (int) SubdomainRouter::current_artist_id();

		if ( ! $artist_id || ! ContactForm::artist_accepts_contact( $artist_id ) ) {
			return '';
		}

		$this->enqueue_assets( $artist_id );

		// Unlike the admin-side review forms (which edit a specific post's
		// already-known `_agnosis_native_lang`), the visitor typing here isn't
		// an unknown quantity either — they explicitly picked this page's
		// language via the site's own language routing, so LinguaForge's
		// "what language is this page actually being viewed in" resolver
		// (LF_LANG when LF is active, else the site's own locale) tells us
		// exactly what to spellcheck the message field against.
		$page_lang = LinguaForge::current_lang();
		$lang_attr = '' !== $page_lang ? ' lang="' . esc_attr( $page_lang ) . '"' : '';

		ob_start();
		?>
		<div class="agnosis-contact-form">

			<div class="agnosis-contact-form__notice" hidden></div>

			<form class="agnosis-contact-form__form" novalidate>
				<div class="agnosis-contact-form__field">
					<label for="agnosis-contact-form-name">
						<?php esc_html_e( 'Your name (optional)', 'agnosis' ); ?>
					</label>
					<input
						type="text"
						id="agnosis-contact-form-name"
						name="name"
						autocomplete="name"
					/>
				</div>
				<div class="agnosis-contact-form__field">
					<label for="agnosis-contact-form-email">
						<?php esc_html_e( 'Your email address', 'agnosis' ); ?>
					</label>
					<input
						type="email"
						id="agnosis-contact-form-email"
						name="email"
						autocomplete="email"
						placeholder="<?php esc_attr_e( 'you@example.com', 'agnosis' ); ?>"
						required
					/>
				</div>
				<div class="agnosis-contact-form__field">
					<label for="agnosis-contact-form-message">
						<?php esc_html_e( 'Message', 'agnosis' ); ?>
					</label>
					<textarea
						id="agnosis-contact-form-message"
						name="message"
						rows="5"
						required
						<?php echo $lang_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_attr()'d above. ?>
					></textarea>
				</div>
				<?php echo Turnstile::render_widget(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_widget() escapes the site key internally. ?>
				<p class="agnosis-contact-form__privacy-notice">
					<?php echo Privacy::consent_notice_html( 'contact' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- consent_notice_html() escapes internally. ?>
				</p>
				<button type="submit" class="agnosis-contact-form__submit">
					<span><?php esc_html_e( 'Send message', 'agnosis' ); ?></span>
				</button>
			</form>

		</div><!-- .agnosis-contact-form -->
		<?php
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	private function enqueue_assets( int $artist_id ): void {
		Turnstile::enqueue_script();

		wp_enqueue_script(
			'agnosis-contact-form',
			\AGNOSIS_URL . 'blocks/contact-form/frontend.js',
			[],
			\AGNOSIS_VERSION,
			[ 'in_footer' => true ]
		);

		wp_localize_script( 'agnosis-contact-form', 'agnosisContactForm', [
			// Artist ID is baked into the endpoint URL itself, matching the REST
			// route's own shape (POST /agnosis/v1/contact/{artist_id}) — the
			// frontend script never needs to know or send it separately.
			'apiUrl' => rest_url( 'agnosis/v1/contact/' . $artist_id ),
			'i18n'   => [
				'success' => __( 'Thanks — your message has been sent.', 'agnosis' ),
				'error'   => __( 'Something went wrong. Please try again.', 'agnosis' ),
			],
		] );

		wp_enqueue_style(
			'agnosis-contact-form',
			\AGNOSIS_URL . 'blocks/contact-form/frontend.css',
			[],
			\AGNOSIS_VERSION
		);
	}
}
