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
 * not a mailing list. Configured independently under Settings → Community →
 * Rules (agnosis_community_from_name / agnosis_community_from_email), so a
 * site can use e.g. newsletter@ for digests and hello@ (or no-reply@) for
 * everything else.
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
		$name  = (string) get_option( 'agnosis_community_from_name', '' );
		$email = (string) get_option( 'agnosis_community_from_email', '' );

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
