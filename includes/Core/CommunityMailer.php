<?php
/**
 * Sender identity for Agnosis's workflow/transactional emails — application
 * received, admission vote links, welcome, departure/ban/reinstatement,
 * removal votes, community size-cap votes, invitations, and every
 * submission-review email (Publishing\Notification).
 *
 * Deliberately separate from Newsletter\Mailer::sender_header(): that address
 * is for periodic digest mail an artist can filter or unsubscribe from. This
 * one is for one-off, time-sensitive actions — a vote link, a review link, a
 * confirmation link — that must read as coming from the community itself,
 * not a mailing list. Configured independently under Settings → Email →
 * "Mail from:" (agnosis_mail_from_name / agnosis_mail_from_email), so a site
 * can use e.g. newsletter@ for digests and hello@ (or no-reply@) for
 * everything else.
 *
 * Renamed from agnosis_community_from_name/email in 0.9.22 — the old name
 * read as though it configured the agnosis_email_community INBOUND
 * announcement endpoint (Settings → Email), when it has never had anything
 * to do with that address; it is purely an outbound sender identity.
 * Core\Activator::migrate_mail_from_option() carries forward any value a site
 * already had configured under the old option names.
 *
 * Before this existed (found 2026-07-08, reported as a vouch email arriving
 * from "WordPress <wordpress@$domain>" — an address that doesn't exist and
 * has no outbound mail configured): most of these emails passed no `From`
 * header to wp_mail() at all — Artist\AdmissionNotification's plain-text vote
 * email, its admission-expiry notices and admin summary, every
 * DepartureNotification/CommunityCapNotification email — so WordPress fell
 * back to its own PHPMailer default, "WordPress <wordpress@$domain>",
 * unrelated to any address the site actually sends mail from. A minority of
 * others (AdmissionNotification's two HTML emails, Artist\Invitation)
 * borrowed Newsletter\Mailer::sender_header() instead — deliverable, but the
 * wrong identity: a vouch vote or welcome email isn't a newsletter.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

class CommunityMailer {

	/**
	 * Per-post-type Settings → Email option holding the intake address an
	 * artist actually sends to for that content type — e.g. bio@agnosis.art
	 * for a biography, event@agnosis.art for an event. agnosis_artwork has no
	 * single option here; it depends on which of the three artwork intake
	 * lanes the submission came in through (see intake_address_for_post()).
	 *
	 * @var array<string, string>
	 */
	private const POST_TYPE_EMAIL_OPTION = [
		'agnosis_biography' => 'agnosis_email_bio',
		'agnosis_event'     => 'agnosis_email_event',
	];

	/**
	 * `_agnosis_intake_endpoint` value (PostCreator::ENDPOINT_*) => Settings →
	 * Email option holding that lane's address — artwork@/photo@/pure@. Only
	 * ever set on agnosis_artwork (see PostCreator::create_post()'s own
	 * docblock); a biography or event has exactly one intake address instead,
	 * covered by POST_TYPE_EMAIL_OPTION above.
	 *
	 * @var array<string, string>
	 */
	private const ARTWORK_ENDPOINT_EMAIL_OPTION = [
		'artwork' => 'agnosis_email_submit',
		'photo'   => 'agnosis_email_photo',
		'pure'    => 'agnosis_email_pure',
	];

	/**
	 * The intake address an artist actually used (or would use again) for
	 * $post_id — the whole point being that an artist replying to a
	 * submission-review email should land back on the exact address that
	 * accepts more of the same kind of content, not a generic community/
	 * one-off-action address that was never meant to receive submissions.
	 *
	 * Returns '' when: the post type isn't recognised, the resolved Settings
	 * → Email field is blank (operator never configured that lane), or the
	 * configured value isn't a syntactically valid email — same
	 * "gracefully do nothing rather than send a broken header" convention
	 * sender_header() itself already uses for a blank From address.
	 */
	public static function intake_address_for_post( int $post_id ): string {
		$post_type = get_post_type( $post_id );
		if ( false === $post_type ) {
			return '';
		}

		if ( isset( self::POST_TYPE_EMAIL_OPTION[ $post_type ] ) ) {
			$option = self::POST_TYPE_EMAIL_OPTION[ $post_type ];
		} elseif ( 'agnosis_artwork' === $post_type ) {
			$endpoint = (string) get_post_meta( $post_id, '_agnosis_intake_endpoint', true );
			$option   = self::ARTWORK_ENDPOINT_EMAIL_OPTION[ $endpoint ] ?? self::ARTWORK_ENDPOINT_EMAIL_OPTION['artwork'];
		} else {
			return '';
		}

		$address = trim( (string) get_option( $option, '' ) );

		return is_email( $address ) ? $address : '';
	}

	/**
	 * A `Reply-To:` header pointing at $post_id's own intake address, or an
	 * empty array when none is configured — merge straight into a wp_mail()
	 * $headers array (`array_merge( CommunityMailer::text_headers(), ...,
	 * CommunityMailer::reply_to_header_for_post( $post_id ) )`), same shape
	 * as text_headers()/html_headers() below.
	 *
	 * @return array<string>
	 */
	public static function reply_to_header_for_post( int $post_id ): array {
		$address = self::intake_address_for_post( $post_id );

		return '' !== $address ? [ 'Reply-To: ' . $address ] : [];
	}

	/**
	 * Build the From header value (Name <email>) for a workflow/transactional
	 * email.
	 *
	 * Falls back to the site name and admin_email when either half is left
	 * blank in Settings — same graceful-degradation convention
	 * Newsletter\Mailer::sender_header() already uses, so an unconfigured site
	 * still sends from something real (the WordPress admin email) rather than
	 * silently falling through to wp_mail()'s own "WordPress <wordpress@…>"
	 * default.
	 *
	 * @return string e.g. "Agnosis <hello@agnosis.art>"
	 */
	public static function sender_header(): string {
		$name  = (string) get_option( 'agnosis_mail_from_name', '' );
		$email = (string) get_option( 'agnosis_mail_from_email', '' );

		if ( '' === $name ) {
			$name = get_bloginfo( 'name' );
		}
		if ( '' === $email || ! is_email( $email ) ) {
			$email = (string) get_option( 'admin_email' );
		}

		return sprintf( '%s <%s>', $name, $email );
	}

	/**
	 * Plain-text wp_mail() headers carrying the community From header.
	 *
	 * @return array<string>
	 */
	public static function text_headers(): array {
		return [
			'Content-Type: text/plain; charset=UTF-8',
			'From: ' . self::sender_header(),
		];
	}

	/**
	 * HTML wp_mail() headers carrying the community From header.
	 *
	 * @return array<string>
	 */
	public static function html_headers(): array {
		return [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . self::sender_header(),
		];
	}
}
