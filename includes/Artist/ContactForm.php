<?php
/**
 * Visitor-to-artist contact form.
 *
 * Closes a promotion gap flagged 2026-07-12: an artist's biography page
 * offered no way for a site visitor to actually reach them — only outbound
 * links (social icons, the portfolio URL) an interested visitor would have
 * to leave the site to use. This is the inbound counterpart: a popover form
 * (see Network\SubdomainNavigation's `type=contact` breadcrumb icon and
 * blocks/contact-form) that lets a visitor write a message in whatever
 * language they're comfortable with, which this class then:
 *
 *   1. Verifies with Cloudflare Turnstile (Core\Turnstile — opt-in, see that
 *      class), exactly like Admission::apply() and Newsletter\Subscription.
 *   2. Rate-limits by IP (RateLimiter::check()), once an email address is
 *      known by that address globally (RateLimiter::check_sender()) — mirrors
 *      Admission::apply()'s two-tier throttle — and by a THIRD tier scoped to
 *      (artist, visitor email): RateLimiter::check_sender() again, keyed by
 *      an action string containing $artist_id, so a visitor can't message the
 *      same artist more than `agnosis_contact_artist_limit` times (default 2)
 *      per `agnosis_contact_artist_limit_window_hours` (default 1 hour) —
 *      configurable in Settings → Email. $artist_id is always the artist's WP
 *      user ID, the same one regardless of which LinguaForge-translated
 *      language version of that artist's page the visitor is on, so this
 *      can't be bypassed by switching languages.
 *   3. Runs the message through Pipeline::classify_text() against the same
 *      admin-configured disallowed-content categories EmbedPolicy uses for
 *      link vetting, plus one contact-form-specific spam/solicitation
 *      category (see disallowed_categories() below) — "we already dispose of
 *      filters" per the feature request, reused rather than duplicated.
 *   4. STORES every submission — sent or rejected — in
 *      {$wpdb->prefix}agnosis_contact_messages (Core\Activator::create_tables())
 *      so an admin has a real audit trail via Admin\ContactMessagesPage, not
 *      just a silent drop. A rejected message is never emailed.
 *   5. Translates an accepted message into the artist's own language
 *      (SubmissionTranslator::resolve_artist_lang() +
 *      SubmissionTranslator::translate_fields(), the same pair
 *      Notification/ReviewConfirm already use elsewhere) before emailing it.
 *   6. Emails the artist via CommunityMailer's headers, with `Reply-To:` set
 *      to the visitor's own address (no such header existed anywhere in this
 *      codebase before — every prior wp_mail() call only ever set `From`/
 *      `Bcc`; this is a direct extension of that same "build up the header
 *      array" convention) so the artist can just hit reply in their own mail
 *      client — this plugin never learns or stores the artist's real address
 *      beyond what WordPress core already has in wp_users.
 *   7. Marks the visitor as having contacted this artist with a short-lived
 *      `Set-Cookie` (mark_contacted(), same window as the per-artist rate
 *      limit above) so ContactFormBlock::render_block() can render an inert
 *      "already contacted" notice instead of the form on the visitor's next
 *      page load — a simple spam deterrent on top of, not instead of, the
 *      rate limit itself. Set unconditionally, sent vs. rejected alike, for
 *      the same "identical response" reason as the REST response itself (see
 *      below) — the form disappearing is not meant to leak moderation status.
 *
 * A rejected message and an accepted one get an IDENTICAL REST response —
 * see submit()'s final return — deliberately, so the response itself can
 * never be used as an oracle to probe what the content filter blocks.
 *
 * An artist can opt out of this entirely (Artist\NotificationPreferences'
 * `_agnosis_contact_optout` toggle) — checked before anything else runs
 * (Turnstile/rate-limit aside), so an opted-out artist's contact icon simply
 * doesn't render (see SubdomainNavigation) and a direct POST still gets a
 * clean rejection rather than silently succeeding into a form nobody reads.
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

use Agnosis\AI\Pipeline;
use Agnosis\AI\SubmissionTranslator;
use Agnosis\Core\CommunityMailer;
use Agnosis\Core\Logger;
use Agnosis\Core\RateLimiter;
use Agnosis\Core\Turnstile;
use Agnosis\Publishing\EmbedPolicy;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_User;

class ContactForm {

	/** Server-side caps mirroring Admission::apply()'s own field-length guards. */
	private const MAX_NAME_LENGTH    = 150;
	private const MAX_MESSAGE_LENGTH = 4000;

	/** Per-IP throttle — same shape as Admission's 5/60s. */
	private const IP_LIMIT           = 5;
	private const IP_WINDOW_SECONDS  = 60;

	/**
	 * Per-visitor-email throttle. Wider window than Admission's per-IP limit
	 * since this is the second, coarser tier — catches an address hammering
	 * the form across multiple IPs/sessions in a way the IP limit alone can't.
	 */
	private const SENDER_LIMIT          = 5;
	private const SENDER_WINDOW_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Third tier: how many times the SAME visitor (by email) may message the
	 * SAME artist within the window — both configurable in Settings → Email,
	 * unlike every other window in this class. See class docblock point 2.
	 */
	private const ARTIST_LIMIT_OPTION               = 'agnosis_contact_artist_limit';
	private const ARTIST_LIMIT_DEFAULT              = 2;
	private const ARTIST_LIMIT_WINDOW_OPTION        = 'agnosis_contact_artist_limit_window_hours';
	private const ARTIST_LIMIT_WINDOW_DEFAULT_HOURS = 1;

	/** Name prefix for the "already contacted this artist" cookie — see mark_contacted(). */
	private const CONTACTED_COOKIE_PREFIX = 'agnosis_contacted_';

	/**
	 * Fixed, non-configurable window after which a stored row's raw `ip`
	 * column is cleared — independent of, and always shorter than,
	 * `agnosis_contact_message_retention_days` (security audit §4b). The
	 * address is only ever useful for investigating abuse in the days right
	 * after a submission; it's never read back for rate-limiting (that's
	 * RateLimiter's own short-lived transient bucket, unrelated to this
	 * column) or shown anywhere in wp-admin today. There's no legitimate
	 * reason to keep it around for the full lifetime an operator might
	 * configure for the message content itself.
	 */
	private const IP_RETENTION_DAYS = 30;

	// -------------------------------------------------------------------------
	// Routes
	// -------------------------------------------------------------------------

	public function register_routes(): void {
		register_rest_route( 'agnosis/v1', '/contact/(?P<artist_id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'submit' ],
			'permission_callback' => [ $this, 'rate_limit' ],
			'args'                => [
				'artist_id'       => [
					'type'     => 'integer',
					'required' => true,
				],
				'name'            => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => fn( string $v ): bool|WP_Error => self::validate_max_length( $v, self::MAX_NAME_LENGTH, __( 'Name', 'agnosis' ) ),
				],
				'email'           => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_email',
					'validate_callback' => fn( string $v ): bool => (bool) is_email( $v ),
				],
				'message'         => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_textarea_field',
					'validate_callback' => fn( string $v ): bool|WP_Error => self::validate_max_length( $v, self::MAX_MESSAGE_LENGTH, __( 'Message', 'agnosis' ) ),
				],
				'turnstile_token' => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );
	}

	/** REST `permission_callback` — coarse per-IP gate, checked before Turnstile/DB work. */
	public function rate_limit( WP_REST_Request $request ): bool|WP_Error {
		return RateLimiter::check( 'contact_form', self::IP_LIMIT, self::IP_WINDOW_SECONDS );
	}

	/**
	 * REST `validate_callback` for a length-capped text field — identical
	 * pattern to Admission::validate_max_length().
	 *
	 * @return true|WP_Error
	 */
	private static function validate_max_length( string $value, int $max, string $field_label ): bool|WP_Error {
		if ( mb_strlen( $value ) > $max ) {
			return new WP_Error(
				'agnosis_field_too_long',
				sprintf(
					/* translators: 1: field name (e.g. "Bio", "Message"), 2: maximum character count */
					__( '%1$s must be %2$d characters or fewer.', 'agnosis' ),
					$field_label,
					$max
				),
				[ 'status' => 400 ]
			);
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// Callback
	// -------------------------------------------------------------------------

	public function submit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$turnstile = Turnstile::verify( (string) ( $request->get_param( 'turnstile_token' ) ?? '' ) );
		if ( is_wp_error( $turnstile ) ) {
			return $turnstile;
		}

		$artist_id = (int) $request->get_param( 'artist_id' );
		$artist    = $this->contactable_artist( $artist_id );
		if ( null === $artist ) {
			return new WP_Error(
				'agnosis_contact_unavailable',
				__( "This artist can't be reached through this form right now.", 'agnosis' ),
				[ 'status' => 404 ]
			);
		}

		$visitor_email = (string) $request->get_param( 'email' );

		$sender_limit = RateLimiter::check_sender( 'contact_form_sender', $visitor_email, self::SENDER_LIMIT, self::SENDER_WINDOW_SECONDS );
		if ( is_wp_error( $sender_limit ) ) {
			return $sender_limit;
		}

		$artist_limit_result = $this->check_artist_limit( $artist_id, $visitor_email );
		if ( is_wp_error( $artist_limit_result ) ) {
			return $artist_limit_result;
		}

		$visitor_name = (string) ( $request->get_param( 'name' ) ?? '' );
		$message      = (string) $request->get_param( 'message' );

		$rejection_reason = $this->moderate( $message );
		$rejected          = '' !== $rejection_reason;

		$translated_message = '';
		if ( ! $rejected ) {
			$translated_message = $this->translate_for_artist( $artist_id, $message );
		}

		$this->store(
			$artist_id,
			$visitor_name,
			$visitor_email,
			$message,
			$translated_message,
			$rejected ? 'rejected' : 'sent',
			$rejection_reason
		);

		if ( ! $rejected ) {
			$this->email_artist( $artist, $visitor_name, $visitor_email, $message, $translated_message );
		} else {
			Logger::info(
				sprintf( 'ContactForm: message to artist #%d rejected by content review — not sent.', $artist_id ),
				'contact-form'
			);
		}

		// Deliberately identical response for a sent vs. a silently-rejected
		// message (see class docblock) — the visitor always sees success.
		$response = new WP_REST_Response( [
			'message' => __( 'Thanks — your message has been sent.', 'agnosis' ),
		], 200 );

		$this->mark_contacted( $response, $artist_id );

		return $response;
	}

	// -------------------------------------------------------------------------
	// Steps
	// -------------------------------------------------------------------------

	/**
	 * Third rate-limit tier: how many times $visitor_email may message
	 * $artist_id within the configured window (Settings → Email,
	 * `agnosis_contact_artist_limit` / `agnosis_contact_artist_limit_window_hours`).
	 *
	 * Reuses RateLimiter::check_sender() as-is — the action string itself
	 * embeds $artist_id, so the transient key it builds is already scoped to
	 * this (artist, visitor) pair without any change to RateLimiter. Because
	 * $artist_id is the artist's WP user ID (constant across every
	 * LinguaForge-translated language version of their page — see
	 * SubdomainRouter::current_artist_id(), which this class's caller
	 * ultimately resolves it from), this can't be sidestepped by messaging
	 * the same artist from a different `/fr/`, `/de/`, etc. page.
	 *
	 * @return true|WP_Error
	 */
	private function check_artist_limit( int $artist_id, string $visitor_email ): bool|WP_Error {
		$limit = max( 1, (int) get_option( self::ARTIST_LIMIT_OPTION, self::ARTIST_LIMIT_DEFAULT ) );

		return RateLimiter::check_sender(
			'contact_form_artist_' . $artist_id,
			$visitor_email,
			$limit,
			self::artist_limit_window_seconds()
		);
	}

	/** Configured per-artist rate-limit window, in seconds — see check_artist_limit(). */
	private static function artist_limit_window_seconds(): int {
		$hours = max( 1, (int) get_option( self::ARTIST_LIMIT_WINDOW_OPTION, self::ARTIST_LIMIT_WINDOW_DEFAULT_HOURS ) );
		return $hours * HOUR_IN_SECONDS;
	}

	/**
	 * Set a short-lived, host-only "already contacted this artist" cookie on
	 * the outgoing REST response — read back by
	 * ContactFormBlock::render_block() to render an inert notice instead of
	 * the form on the visitor's next page load. Purely a client-facing spam
	 * deterrent (a visitor can always clear cookies); the actual limit is
	 * enforced server-side by check_artist_limit() regardless of this cookie's
	 * presence. Mirrors that method's window so the form reappears exactly
	 * when the visitor would be allowed to submit again.
	 */
	private function mark_contacted( WP_REST_Response $response, int $artist_id ): void {
		$response->header(
			'Set-Cookie',
			sprintf(
				'%s%d=1; Max-Age=%d; Path=/; SameSite=Lax%s',
				self::CONTACTED_COOKIE_PREFIX,
				$artist_id,
				self::artist_limit_window_seconds(),
				is_ssl() ? '; Secure' : ''
			)
		);
	}

	/**
	 * Whether the current visitor has already contacted $artist_id per
	 * mark_contacted()'s cookie — used by ContactFormBlock::render_block().
	 */
	public static function already_contacted( int $artist_id ): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- presence check only, the cookie's value is never read or trusted as data.
		return isset( $_COOKIE[ self::CONTACTED_COOKIE_PREFIX . $artist_id ] );
	}

	/**
	 * Resolve $artist_id to a contactable artist — a real user with the
	 * agnosis_artist role who hasn't opted out — or null if either check fails.
	 */
	private function contactable_artist( int $artist_id ): ?WP_User {
		if ( ! self::artist_accepts_contact( $artist_id ) ) {
			return null;
		}

		$artist = get_userdata( $artist_id );

		return $artist instanceof WP_User ? $artist : null;
	}

	/**
	 * Whether $artist_id is a real artist who currently accepts contact-form
	 * messages — a real user with the agnosis_artist role who hasn't set
	 * `_agnosis_contact_optout` (Artist\NotificationPreferences).
	 *
	 * Public and static so Artist\ContactFormBlock (the form itself) and
	 * Network\SubdomainNavigation (the breadcrumb trigger icon) can both gate
	 * on the exact same check submit() ultimately enforces server-side —
	 * neither the trigger icon nor the form should ever appear for an artist
	 * a POST to this class would reject anyway.
	 */
	public static function artist_accepts_contact( int $artist_id ): bool {
		if ( ! $artist_id ) {
			return false;
		}

		$artist = get_userdata( $artist_id );
		if ( ! $artist || ! in_array( 'agnosis_artist', (array) $artist->roles, true ) ) {
			return false;
		}

		return '1' !== get_user_meta( $artist_id, '_agnosis_contact_optout', true );
	}

	/**
	 * Human-readable disallowed-content categories for a contact message.
	 *
	 * Reuses EmbedPolicy::disallowed_categories() (widened to public static
	 * for exactly this purpose — see that method's docblock) so the site's
	 * one admin-configured "disallowed content" list (adult/commercial/
	 * gambling/custom) governs both embedded links AND contact messages,
	 * rather than each feature keeping its own copy of the same option reads.
	 * A spam/solicitation category is always appended on top — unlike the
	 * embed categories (all opt-in/off-by-default except adult), ruling out
	 * spam is exactly what this feature was asked for ("we try to rule out
	 * spam, commercial stuff, etc.") and isn't meant to be toggled off.
	 *
	 * @return string[]
	 */
	private function disallowed_categories(): array {
		return array_merge(
			EmbedPolicy::disallowed_categories(),
			[ __( 'Spam, scams, or unsolicited commercial advertising unrelated to genuinely contacting the artist about their work', 'agnosis' ) ]
		);
	}

	/**
	 * Classify $message against disallowed_categories(). Returns '' when the
	 * message is allowed through, or a human-readable rejection reason when
	 * it should be blocked.
	 *
	 * Unlike EmbedPolicy's fail-closed contract, an inconclusive/unparseable
	 * AI response (Pipeline::classify_text() returning null — provider
	 * failure, empty response, etc.) is treated as ALLOW here, not BLOCK —
	 * see Pipeline::classify_text()'s own docblock for why: the cost of
	 * silently dropping a genuine visitor's message (with no page to retry
	 * from, unlike a link submission an artist can just resubmit) is judged
	 * higher here than the cost of an occasional unfiltered message reaching
	 * an artist's inbox.
	 */
	private function moderate( string $message ): string {
		$categories = $this->disallowed_categories();

		$verdict = $this->pipeline()->classify_text( $message, $categories );

		if ( false === $verdict ) {
			return __( 'Flagged by automatic content review.', 'agnosis' );
		}

		return '';
	}

	/**
	 * Production Pipeline instance. Overridden by an anonymous subclass in
	 * tests (ContactFormTest) to stub classify_text() without a real AI
	 * provider — same "protected factory method, overridden in an anonymous
	 * subclass" convention EmbedPolicyTest uses for the same class.
	 */
	protected function pipeline(): Pipeline {
		return new Pipeline();
	}

	/**
	 * Translate $message into the artist's own language
	 * (SubmissionTranslator::resolve_artist_lang()), or '' when the artist's
	 * language can't be resolved, no AI provider is configured
	 * (SubmissionTranslator::from_settings() returning null), or translation
	 * otherwise fails — callers fall back to the original message, same
	 * graceful-degradation convention every other translate_fields() caller
	 * in this codebase already follows.
	 */
	private function translate_for_artist( int $artist_id, string $message ): string {
		$target_lang = SubmissionTranslator::resolve_artist_lang( $artist_id );
		if ( '' === $target_lang ) {
			return '';
		}

		$translator = $this->submission_translator();
		if ( null === $translator ) {
			return '';
		}

		$translated = $translator->translate_fields( [ 'message' => $message ], $target_lang );

		return $translated['message'] ?? '';
	}

	/**
	 * Production SubmissionTranslator, or null when no AI provider is
	 * configured (SubmissionTranslator::from_settings()'s own contract).
	 * Overridden by an anonymous subclass in tests to inject a
	 * SubmissionTranslator wrapping a stub ProviderInterface, without needing
	 * real AI credentials configured — same convention as pipeline() above.
	 */
	protected function submission_translator(): ?SubmissionTranslator {
		return SubmissionTranslator::from_settings();
	}

	/** Insert one row into {$wpdb->prefix}agnosis_contact_messages. */
	private function store(
		int $artist_id,
		string $visitor_name,
		string $visitor_email,
		string $message,
		string $translated_message,
		string $status,
		string $rejection_reason
	): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off write, no caching applicable.
			$wpdb->prefix . 'agnosis_contact_messages',
			[
				'artist_id'          => $artist_id,
				'visitor_name'       => '' !== $visitor_name ? $visitor_name : null,
				'visitor_email'      => $visitor_email,
				'message'            => $message,
				'translated_message' => '' !== $translated_message ? $translated_message : null,
				'status'             => $status,
				'rejection_reason'   => '' !== $rejection_reason ? $rejection_reason : null,
				'ip'                 => RateLimiter::client_ip(),
				'created_at'         => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Daily cleanup — piggybacked on the existing `agnosis_cleanup_inbox`
	 * cron (security audit §4b) rather than a new scheduled event, matching
	 * the "one daily housekeeping tick, each subsystem prunes its own table"
	 * shape `Email\Inbox::cleanup()` already established for IMAP/queue/log/
	 * debug-dump retention.
	 *
	 * Two independent sweeps:
	 *  1. Whole rows (sent or rejected alike) older than
	 *     `agnosis_contact_message_retention_days` (default 90) are deleted
	 *     outright — there's no reason to keep a visitor's message and
	 *     identity past the retention window an operator has configured.
	 *  2. The `ip` column specifically is cleared on any row still older
	 *     than the fixed, shorter `IP_RETENTION_DAYS` (30) — see that
	 *     constant's own docblock for why this runs independently of, and
	 *     always ahead of, the row-retention sweep above.
	 */
	public function prune_old_messages(): void {
		global $wpdb;

		$retention_days = max( 1, (int) get_option( 'agnosis_contact_message_retention_days', 90 ) );
		$row_cutoff      = gmdate( 'Y-m-d H:i:s', time() - $retention_days * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- daily cron housekeeping, not a per-request query.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}agnosis_contact_messages WHERE created_at < %s",
			$row_cutoff
		) );

		$ip_cutoff = gmdate( 'Y-m-d H:i:s', time() - self::IP_RETENTION_DAYS * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- daily cron housekeeping, not a per-request query.
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}agnosis_contact_messages SET ip = NULL WHERE ip IS NOT NULL AND created_at < %s",
			$ip_cutoff
		) );
	}

	/**
	 * Email the artist. Reply-To is the visitor's own address — new header
	 * territory for this codebase (see class docblock) — so the artist can
	 * reply directly without either party's real address needing to be
	 * exposed anywhere in the message body beyond what the visitor themself
	 * chose to share.
	 */
	private function email_artist(
		WP_User $artist,
		string $visitor_name,
		string $visitor_email,
		string $original_message,
		string $translated_message
	): void {
		$body_message = '' !== $translated_message ? $translated_message : $original_message;
		$from_label   = '' !== $visitor_name ? $visitor_name : $visitor_email;

		$subject = sprintf(
			/* translators: %s: visitor's name, or their email address if no name was given */
			__( 'New message from %s via your Agnosis contact form', 'agnosis' ),
			$from_label
		);

		$lines   = [];
		$lines[] = sprintf(
			/* translators: %s: visitor's name, or a placeholder if none was given */
			__( 'From: %s', 'agnosis' ),
			'' !== $visitor_name ? $visitor_name : __( '(no name provided)', 'agnosis' )
		);
		$lines[] = sprintf(
			/* translators: %s: visitor's own email address, for the artist to reply to directly. */
			__( 'Email: %s', 'agnosis' ),
			$visitor_email
		);
		$lines[] = '';
		$lines[] = $body_message;

		if ( '' !== $translated_message && $translated_message !== $original_message ) {
			$lines[] = '';
			$lines[] = '---';
			$lines[] = __( 'Original message, as submitted:', 'agnosis' );
			$lines[] = $original_message;
		}

		$body = implode( "\n", $lines );

		$headers   = CommunityMailer::text_headers();
		$headers[] = 'Reply-To: ' . $visitor_email;

		wp_mail( $artist->user_email, $subject, $body, $headers );
	}
}
