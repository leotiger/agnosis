<?php
/**
 * "Biography Title Translation Cache" panel (General tab) — lets an admin
 * view, hand-edit, or clear the AI-translated per-language cache behind
 * Settings → General → "Preset biography title" (Artist\BiographyTitle).
 *
 * Companion to the existing "Term Translation Cache" panel just above it on
 * the same tab, but editable rather than clear-only: unlike the tag/medium
 * term cache (potentially dozens of small labels, not worth hand-editing
 * individually), a preset biography title only ever has one cached entry per
 * configured language — few enough that fixing a specific bad translation by
 * hand is worth a real text field per language, rather than only ever being
 * able to clear it and hope a fresh AI call does better.
 *
 * Prompted by a live bad-translation report (2026-07-18): the German AI
 * translation of a short "Meet the Artist"-style preset came back as
 * ungrammatical, incorrectly gendered text ("...die Künstlerin...") with no
 * way to correct it short of a direct database edit. See
 * Artist\BiographyTitle's own cache-methods docblock for the read/write/clear
 * API this panel is a thin UI over, and AI\SubmissionTranslator's
 * GENDER_NEUTRAL_INSTRUCTION/translate_text() $context param for the prompt
 * side of the same fix.
 *
 * @package Agnosis\Admin\Dashboards
 */

declare(strict_types=1);

namespace Agnosis\Admin\Dashboards;

use Agnosis\AI\SubmissionTranslator;
use Agnosis\Artist\BiographyTitle;

class BiographyTitleCache {

	/**
	 * Render the panel. No-ops entirely when no preset title is configured —
	 * this cache only exists, and only matters, while that setting is in use.
	 */
	public function render(): void {
		$preset_title = trim( (string) get_option( 'agnosis_biography_preset_title', '' ) );
		if ( '' === $preset_title ) {
			return;
		}

		$cached  = BiographyTitle::cached_translations();
		$primary = $this->primary_language();

		// The primary/site language is never translated — BiographyTitle
		// applies the raw, untranslated preset directly to that post
		// (apply_preset_title()) — so listing it here would just be a
		// disabled-looking row with nothing this panel can actually do.
		$languages = SubmissionTranslator::language_names();
		unset( $languages[ $primary ] );

		?>
		<div class="card" style="max-width:800px;margin-top:1.5rem;padding:1rem 1.5rem">
			<h2 style="margin-top:0"><?php esc_html_e( 'Biography Title Translation Cache', 'agnosis' ); ?></h2>

			<p class="description" style="margin-bottom:1rem">
				<?php
				printf(
					/* translators: %s: the currently configured preset biography title */
					esc_html__( 'The AI-translated, per-language cache behind the current preset biography title, "%s" (Settings → General, above). Edit a translation directly and Save, or use Retranslate to discard it and let the AI try again for just that language.', 'agnosis' ),
					esc_html( $preset_title )
				);
				?>
			</p>

			<?php if ( empty( $languages ) ) : ?>
				<p style="color:#666"><?php esc_html_e( 'No other languages are configured in Lingua Forge — nothing to translate.', 'agnosis' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="border-radius:4px;overflow:hidden">
					<thead>
						<tr>
							<th style="width:160px"><?php esc_html_e( 'Language', 'agnosis' ); ?></th>
							<th><?php esc_html_e( 'Cached translation', 'agnosis' ); ?></th>
							<th style="width:1%"></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $languages as $code => $label ) : ?>
						<?php $current = $cached[ $code ] ?? ''; ?>
						<tr>
							<td>
								<?php echo esc_html( $label ); ?>
								<span style="color:#999;font-size:12px">(<?php echo esc_html( $code ); ?>)</span>
							</td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px;align-items:center">
									<input type="hidden" name="action" value="agnosis_save_biography_title_translation">
									<input type="hidden" name="lang" value="<?php echo esc_attr( $code ); ?>">
									<?php wp_nonce_field( 'agnosis_biography_title_cache_' . $code, 'agnosis_nonce' ); ?>
									<input type="text" name="translation" value="<?php echo esc_attr( $current ); ?>"
										placeholder="<?php esc_attr_e( 'Not yet translated — leave blank to let AI translate automatically', 'agnosis' ); ?>"
										style="width:100%;max-width:420px">
									<?php submit_button( __( 'Save', 'agnosis' ), 'small', 'submit', false ); ?>
								</form>
							</td>
							<td style="white-space:nowrap">
								<?php if ( '' !== $current ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
										onsubmit="return confirm('<?php echo esc_js( __( 'Discard the cached translation and have the AI translate this language again?', 'agnosis' ) ); ?>')">
										<input type="hidden" name="action" value="agnosis_retranslate_biography_title">
										<input type="hidden" name="lang" value="<?php echo esc_attr( $code ); ?>">
										<?php wp_nonce_field( 'agnosis_biography_title_cache_' . $code, 'agnosis_nonce' ); ?>
										<?php submit_button( __( '↻ Retranslate', 'agnosis' ), 'small', 'submit', false ); ?>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem"
					onsubmit="return confirm('<?php echo esc_js( __( 'Discard every cached translation for the current preset title and have the AI translate all of them again?', 'agnosis' ) ); ?>')">
					<input type="hidden" name="action" value="agnosis_clear_biography_title_cache">
					<?php wp_nonce_field( 'agnosis_clear_biography_title_cache' ); ?>
					<?php
					submit_button(
						__( 'Clear All (Retranslate All)', 'agnosis' ),
						'secondary', 'submit', false,
						empty( $cached ) ? [ 'disabled' => 'disabled' ] : []
					);
					?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The site's primary/target language code, resolved the same way
	 * AI\SubmissionTranslator::resolve_target_language() does — duplicated
	 * here (3 lines) rather than instantiating a SubmissionTranslator just to
	 * call an instance method that touches no instance state: that class's
	 * constructor requires a configured AI provider, which this render-only
	 * lookup has no reason to depend on (rendering this panel must not break
	 * just because no API key happens to be set).
	 */
	private function primary_language(): string {
		$lang = sanitize_key( (string) get_option( 'linguaforge_primary_language', '' ) );
		if ( '' === $lang ) {
			$lang = sanitize_key( substr( get_locale(), 0, 2 ) );
		}
		return $lang ?: 'en';
	}

	// -------------------------------------------------------------------------
	// admin-post handlers
	// -------------------------------------------------------------------------

	/** admin-post handler: save a hand-typed translation override for one language. */
	public function handle_save(): void {
		$lang = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );

		check_admin_referer( 'agnosis_biography_title_cache_' . $lang, 'agnosis_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$translation = sanitize_text_field( wp_unslash( $_POST['translation'] ?? '' ) );
		BiographyTitle::set_translation_override( $lang, $translation );

		$this->redirect();
	}

	/** admin-post handler: discard the cached translation for one language and let the AI try again. */
	public function handle_retranslate(): void {
		$lang = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );

		check_admin_referer( 'agnosis_biography_title_cache_' . $lang, 'agnosis_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		BiographyTitle::clear_translation( $lang );

		$this->redirect();
	}

	/** admin-post handler: discard every cached translation for the current preset title. */
	public function handle_clear_all(): void {
		check_admin_referer( 'agnosis_clear_biography_title_cache' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		BiographyTitle::clear_all_translations();

		$this->redirect();
	}

	private function redirect(): void {
		wp_safe_redirect( add_query_arg( [ 'page' => 'agnosis-settings', 'tab' => 'general' ], admin_url( 'admin.php' ) ) );
		exit;
	}
}
