<?php
/**
 * Admin-initiated "invite a prospective artist" email.
 *
 * Distinct from the newsletter system (Newsletter\Scheduler/Mailer): there is
 * no list, no subscriber row, no queue — an admin picks one email address and
 * a language and sends a single, immediate invitation, the same "fire and
 * forget" shape as Scheduler::send_test(). Unlike the newsletter's admin
 * intro (a one-shot note cleared after the next issue), the invitation intro
 * (agnosis_invitation_intro) is a standing, reusable piece of copy — it
 * describes the community itself, not a single issue's news, so there is
 * nothing to clear after each send.
 *
 * Localisation works the same way Scheduler::render_locale_content() already
 * localises a newsletter issue's admin intro: the admin explicitly picks a
 * target language (there is no recipient locale to read, since this person
 * isn't in the system yet), the static template chrome renders under
 * switch_to_locale() so its own gettext strings pick up a matching .mo when
 * one exists, and the free-text intro itself is machine-translated via the
 * site's configured AI provider when the target isn't the site's source
 * language — falling back to the untranslated original if no provider is
 * configured, same graceful degradation as everywhere else this pattern is used.
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

use Agnosis\AI\SubmissionTranslator;
use Agnosis\Core\CommunityMailer;
use Agnosis\Core\EmailBranding;
use Agnosis\Core\EmailTemplate;
use Agnosis\Core\Logger;

class Invitation {

	/**
	 * Send a real invitation to one prospective artist.
	 *
	 * @return true|string True on success, or a translated error message.
	 */
	public function send( string $email, string $language ): bool|string {
		return $this->deliver( $email, $language, false );
	}

	/**
	 * Send a preview of the invitation to one address (typically the admin's
	 * own). Identical content to a real send, just [TEST]-prefixed — nothing
	 * is tracked either way (see class docblock), so there is no separate
	 * state a test send could leave behind to clean up.
	 *
	 * @return true|string True on success, or a translated error message.
	 */
	public function send_test( string $email, string $language ): bool|string {
		return $this->deliver( $email, $language, true );
	}

	// -------------------------------------------------------------------------
	// Internal
	// -------------------------------------------------------------------------

	/**
	 * @return true|string True on success, or a translated error message.
	 */
	private function deliver( string $email, string $language, bool $is_test ): bool|string {
		if ( ! is_email( $email ) ) {
			return __( 'Please enter a valid email address.', 'agnosis' );
		}

		$default_locale = get_locale();
		$locale         = '' !== $language ? Admission::iso_to_wp_locale( $language ) : '';
		$switched       = ( '' !== $locale && $locale !== $default_locale ) ? switch_to_locale( $locale ) : false;

		$subject = $this->build_subject( $is_test );
		$body    = $this->build_body( $this->localized_intro( $language ) );

		// Community sender, not the newsletter's — an invitation is a one-off
		// action, not digest mail (2026-07-08).
		$sent = wp_mail( $email, $subject, $body, CommunityMailer::html_headers() );

		if ( $switched ) {
			restore_current_locale();
		}

		if ( ! $sent ) {
			Logger::warning( sprintf( 'Invitation: wp_mail() failed for %s.', $email ), 'admission' );
			return __( "wp_mail() reported a failure — check your site's outgoing mail configuration.", 'agnosis' );
		}

		return true;
	}

	/**
	 * Resolve the standing invitation intro (agnosis_invitation_intro),
	 * machine-translated into $language when it differs from the site's own
	 * source language and an AI provider is configured — otherwise returned
	 * as-is, the same graceful degradation Scheduler::render_locale_content()
	 * already uses for the newsletter's admin intro.
	 */
	private function localized_intro( string $language ): string {
		$intro = (string) get_option( 'agnosis_invitation_intro', '' );

		if ( '' === trim( $intro ) || '' === $language ) {
			return $intro;
		}

		$source = function_exists( 'linguaforge_source_language' ) ? linguaforge_source_language() : '';
		if ( $language === $source ) {
			return $intro; // Already the source language — nothing to translate.
		}

		$translator = SubmissionTranslator::from_settings();
		if ( null === $translator ) {
			return $intro; // No AI provider configured — same-language intro beats blocking the send.
		}

		return $translator->translate_text( $intro, $language );
	}

	private function build_subject( bool $is_test ): string {
		$subject = sprintf(
			/* translators: %s: community name */
			__( "You're invited to join %s", 'agnosis' ),
			get_bloginfo( 'name' )
		);

		/* translators: %s: the actual (non-test) subject line */
		return $is_test ? sprintf( __( '[TEST] %s', 'agnosis' ), $subject ) : $subject;
	}

	private function build_body( string $intro ): string {
		$accent = EmailTemplate::accent();

		$header_extra_html = '<div style="font-size:15px;color:' . esc_attr( EmailBranding::header_subtitle_color() ) . ';margin-top:4px;">' . esc_html__( "You're invited", 'agnosis' ) . '</div>';

		ob_start();
		?>
		<?php if ( '' !== trim( $intro ) ) : ?>
		<div style="margin:0 0 28px;font-size:18px;line-height:1.7;color:#333;">
			<?php echo wp_kses_post( wpautop( $intro ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post() escapes/strips internally. ?>
		</div>
		<?php endif; ?>

		<table cellpadding="0" cellspacing="0" style="margin:0 0 8px;">
		<tr><td>
			<a href="<?php echo esc_url( $this->join_url() ); ?>" style="display:inline-block;padding:12px 24px;border-radius:6px;font-size:17px;font-weight:600;text-decoration:none;background:<?php echo esc_attr( $accent ); ?>;color:#fff;">
				<?php esc_html_e( 'Apply to join', 'agnosis' ); ?>
			</a>
		</td></tr>
		</table>

		<p style="margin:0;font-size:15px;color:#999;">
			<?php esc_html_e( 'No account needed to apply — the community reviews and votes on every application.', 'agnosis' ); ?>
		</p>
		<?php
		$body_html = (string) ob_get_clean();

		return EmailTemplate::render( str_replace( '_', '-', get_locale() ), $body_html, '', $header_extra_html );
	}

	/**
	 * The join page's real URL — resolved from the page Activator::create_join_page()
	 * created and recorded (agnosis_join_page_id), so this stays correct even if
	 * the page was later renamed/moved. Falls back to the conventional /join/
	 * slug only if that option or page is somehow missing.
	 */
	private function join_url(): string {
		$page_id = (int) get_option( 'agnosis_join_page_id' );
		if ( $page_id ) {
			$permalink = get_permalink( $page_id );
			if ( $permalink ) {
				return $permalink;
			}
		}

		return home_url( '/join/' );
	}
}
