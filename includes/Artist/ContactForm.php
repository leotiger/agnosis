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
use Agnosis\Core\EmailFooter;
use Agnosis\Core\EmailTemplate;
use Agnosis\Core\Logger;
use Agnosis\Core\RateLimiter;
use Agnosis\Core\Turnstile;
use Agnosis\Publishing\EmbedPolicy;
use Agnosis\Publishing\ReviewConfirm;
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

	/**
	 * Contact-thread reply gateway (CONTACT-FORM-TRANSLATION-ROADMAP.md §3,
	 * CF5/CF6) — same stateless HMAC token shape as ActivityPub's own
	 * reply_gateway_token(), scoped by this purpose string so a token minted
	 * for a contact-message row can never be replayed against an unrelated
	 * feature that also hashes a bare row id against wp_salt('auth').
	 */
	private const REPLY_TOKEN_PURPOSE = 'contact_reply';

	/** Same 15-second per-tick budget as ActivityPub::drain_reply_translation_queue() — see that constant's own docblock. */
	private const DRAIN_TIME_BUDGET_SECONDS = 15;

	/** Settings → Email field (Admin\SettingsFields, CF2) — how many turns the VISITOR may send per thread; the artist is never gated by this. */
	private const REPLY_DEPTH_OPTION  = 'agnosis_contact_reply_depth';
	private const REPLY_DEPTH_DEFAULT = 2;

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
					/* translators: 1: field name (e.g. "Name", "Bio", "Message"), 2: maximum character count */
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
		$sender_lang        = '';
		$sender_lang_name   = '';
		if ( ! $rejected ) {
			[ $translated_message, $sender_lang, $sender_lang_name ] = $this->translate_and_detect_for_artist( $artist_id, $message );
		}

		// A fresh submission is always its own THREAD ROOT (CF3) —
		// thread_root_id/parent_id stay null (this row's own id IS the
		// root; see store()'s own docblock for that default), sender is
		// always 'visitor' (only a visitor can start a new contact
		// thread). The root is always handled synchronously, right here,
		// sent or rejected alike — never through the CF7 async drain cron
		// (that cron only ever walks REPLY rows) — so delivered_at is
		// stamped now rather than left for the drain query's "still
		// pending" signal to pick up.
		$message_id = $this->store(
			$artist_id,
			$visitor_name,
			$visitor_email,
			$message,
			$translated_message,
			$rejected ? 'rejected' : 'sent',
			$rejection_reason,
			[
				'sender_lang'      => $sender_lang,
				'sender_lang_name' => $sender_lang_name,
				'delivered_at'     => current_time( 'mysql', true ),
			]
		);

		if ( ! $rejected ) {
			$this->email_artist( $artist, $message_id, $visitor_name, $visitor_email, $message, $translated_message );
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
	 * Translate the thread ROOT $message into the artist's own language AND
	 * detect the visitor's own source language, in one AI call
	 * (CONTACT-FORM-TRANSLATION-ROADMAP.md §3.5/CF4 —
	 * SubmissionTranslator::detect_and_translate()). Replaces the old
	 * target-only translate_for_artist(): a contact thread's root is the ONE
	 * point where the visitor's language has to be identified at all — every
	 * later hop already knows both parties' languages (see CF3's schema
	 * comment on `sender_lang`/`sender_lang_name`), so nothing later ever
	 * needs to call this again.
	 *
	 * @return array{0: string, 1: string, 2: string} [translated message
	 *   (empty when the artist's language can't be resolved, no AI provider
	 *   is configured, or the call otherwise fails — callers fall back to
	 *   the original message, same graceful-degradation convention every
	 *   other translation method in this codebase follows), detected ISO
	 *   639-1-ish code (empty on failure), detected language's own English
	 *   name (empty on failure)].
	 */
	private function translate_and_detect_for_artist( int $artist_id, string $message ): array {
		$target_lang = SubmissionTranslator::resolve_artist_lang( $artist_id );
		if ( '' === $target_lang ) {
			return [ '', '', '' ];
		}

		$translator = $this->submission_translator();
		if ( null === $translator ) {
			return [ '', '', '' ];
		}

		$result = $translator->detect_and_translate( $message, $target_lang );

		return [ $result['translated'], $result['detected_code'], $result['detected_name'] ];
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

	/**
	 * Insert one row into {$wpdb->prefix}agnosis_contact_messages and return
	 * its new id.
	 *
	 * $thread carries the CF3 thread-model columns
	 * (CONTACT-FORM-TRANSLATION-ROADMAP.md §3.3) — everything in it defaults
	 * to a fresh THREAD ROOT (thread_root_id/parent_id both null, sender
	 * 'visitor'), so submit()'s own call site only needs to pass the keys it
	 * actually knows; a reply-gateway submission
	 * (handle_contact_reply_submission()) passes an explicit override for
	 * all of them instead. `ip` is only ever recorded for a VISITOR-authored
	 * row (root or follow-up) — an artist's reply is only ever reachable via
	 * their own token-verified gateway link, the same "no IP logged for a
	 * trusted artist write" choice ActivityPub::store_artist_gateway_reply()
	 * already makes.
	 *
	 * @param array{thread_root_id?: ?int, parent_id?: ?int, sender?: string, sender_lang?: string, sender_lang_name?: string, delivered_at?: ?string} $thread
	 */
	private function store(
		int $artist_id,
		string $visitor_name,
		string $visitor_email,
		string $message,
		string $translated_message,
		string $status,
		string $rejection_reason,
		array $thread = []
	): int {
		global $wpdb;

		$sender = $thread['sender'] ?? 'visitor';

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off write, no caching applicable.
			$wpdb->prefix . 'agnosis_contact_messages',
			[
				'artist_id'              => $artist_id,
				'visitor_name'           => '' !== $visitor_name ? $visitor_name : null,
				'visitor_email'          => $visitor_email,
				'message'                => $message,
				'translated_message'     => '' !== $translated_message ? $translated_message : null,
				'status'                 => $status,
				'rejection_reason'       => '' !== $rejection_reason ? $rejection_reason : null,
				'ip'                     => 'visitor' === $sender ? RateLimiter::client_ip() : null,
				'created_at'             => current_time( 'mysql', true ),
				'thread_root_id'         => $thread['thread_root_id'] ?? null,
				'parent_id'              => $thread['parent_id'] ?? null,
				'sender'                 => $sender,
				'sender_lang'            => '' !== ( $thread['sender_lang'] ?? '' ) ? $thread['sender_lang'] : null,
				'sender_lang_name'       => '' !== ( $thread['sender_lang_name'] ?? '' ) ? $thread['sender_lang_name'] : null,
				'reply_token_expires_at' => self::reply_token_expiry(),
				'delivered_at'           => $thread['delivered_at'] ?? null,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Every new row's reply-gateway token expiry — same
	 * `agnosis_review_token_expiry_days` option (default 7) every other
	 * stateless emailed action link in the plugin honours
	 * (ApplicationBiography, PostCreator, Notification,
	 * ActivityPub's own reply gateway) — see the CF3 schema comment on
	 * `reply_token_expires_at` for why a contact row needs its own real
	 * column rather than commentmeta.
	 */
	private static function reply_token_expiry(): string {
		$days = max( 1, (int) get_option( 'agnosis_review_token_expiry_days', 7 ) );
		return gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
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
	 * Email the artist — branded HTML (contact-form roadmap CF1,
	 * agnosis-audit/CONTACT-FORM-TRANSLATION-ROADMAP.md §2), matching the
	 * shared template every other artist-facing notification in this plugin
	 * already uses (see Network\ActivityPub::notify_artist_of_reply()'s own
	 * docblock, the reference this method now mirrors). Previously a bare
	 * `implode("\n", …)` plain-text string via CommunityMailer::text_headers()
	 * — no EmailTemplate/EmailFooter, no HTML, no locale-switching around
	 * this email's own chrome strings ("From:", "Email:", the subject line
	 * itself) even though the visitor's MESSAGE was already being
	 * translated — a real, disclosed gap, not a stale-looking-but-branded
	 * email; see the roadmap doc's §1.1 for the full itemized diff against
	 * this reference.
	 *
	 * Reply-To is still the visitor's own address — unchanged from before —
	 * so the artist can still just hit reply in their own mail client for a
	 * plain, untranslated back-and-forth. §3/CF5 adds a SECOND, additive
	 * path alongside it: a "Reply" button below, linking to the token-gated
	 * reply gateway (contact_reply_url()) that translates the artist's own
	 * reply automatically for the visitor, and offers the visitor their own
	 * translated-reply link in turn (subject to CF2's configured depth) —
	 * neither path replaces the other.
	 *
	 * $message_id is the row this email is ABOUT (the thread root on first
	 * contact — submit()'s own call site — or a later visitor follow-up row
	 * when this is called from drain_reply_queue()) — the reply gateway
	 * link always points at this exact row, so an artist's reply chains as
	 * parent_id => $message_id regardless of how deep the thread already is.
	 */
	private function email_artist(
		WP_User $artist,
		int $message_id,
		string $visitor_name,
		string $visitor_email,
		string $original_message,
		string $translated_message
	): void {
		$artist_id = (int) $artist->ID;

		$locale = (string) get_user_meta( $artist_id, 'locale', true );
		if ( '' !== $locale ) {
			switch_to_locale( $locale );
		}

		$body_message = '' !== $translated_message ? $translated_message : $original_message;
		$from_label   = '' !== $visitor_name ? $visitor_name : $visitor_email;

		$subject = sprintf(
			/* translators: %s: visitor's name, or their email address if no name was given */
			__( 'New message from %s via your Agnosis contact form', 'agnosis' ),
			$from_label
		);

		$excerpt = wp_strip_all_tags( $body_message );
		if ( mb_strlen( $excerpt ) > 600 ) {
			$excerpt = mb_substr( $excerpt, 0, 600 ) . '…';
		}

		ob_start();
		?>
		<p style="margin:0 0 20px;font-size:20px;color:#555;">
			<?php
			printf(
				/* translators: %s: the name of the person being greeted (may fall back to a generic greeting if unavailable) */
				esc_html__( 'Hi %s,', 'agnosis' ),
				esc_html( $artist->display_name )
			);
			?>
		</p>
		<p style="margin:0 0 20px;font-size:19px;line-height:1.6;color:#555;">
			<?php
			printf(
				/* translators: %s: visitor's name, or a placeholder if none was given */
				esc_html__( 'Someone reached you through your Agnosis contact form: %s', 'agnosis' ),
				esc_html( '' !== $visitor_name ? $visitor_name : __( '(no name provided)', 'agnosis' ) )
			);
			?>
		</p>
		<p style="margin:0 0 28px;padding:16px 20px;background:<?php echo esc_attr( EmailTemplate::notice_bg() ); ?>;border-left:3px solid <?php echo esc_attr( EmailTemplate::accent() ); ?>;border-radius:4px;font-size:18px;font-style:italic;line-height:1.6;color:#333;">
			&ldquo;<?php echo esc_html( $excerpt ); ?>&rdquo;
		</p>
		<?php if ( '' !== $translated_message && $translated_message !== $original_message ) : ?>
		<p style="margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:0.04em;color:#999;">
			<?php esc_html_e( 'Original message, as submitted', 'agnosis' ); ?>
		</p>
		<p style="margin:0 0 24px;font-size:16px;line-height:1.6;color:#666;">
			<?php echo nl2br( esc_html( $original_message ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html() applied just before nl2br(). ?>
		</p>
		<?php endif; ?>
		<p style="margin:0 0 20px;font-size:16px;line-height:1.6;color:#555;">
			<?php
			printf(
				/* translators: %s: visitor's own email address */
				esc_html__( 'Reply directly to this email to write back to %s.', 'agnosis' ),
				esc_html( $visitor_email )
			);
			?>
		</p>
		<div style="text-align:center;margin:0 0 8px;">
			<?php echo EmailTemplate::button( self::contact_reply_url( $message_id ), __( 'Reply (translated automatically)', 'agnosis' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailTemplate::button() escapes internally. ?>
		</div>
		<?php
		$body_html = (string) ob_get_clean();

		ob_start();
		$prefs_html = EmailFooter::preferences_html( $artist_id );
		if ( '' !== $prefs_html ) :
			?>
		<p style="margin:0;text-align:center;">
			<?php echo $prefs_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailFooter::preferences_html() escapes internally. ?>
		</p>
			<?php
		endif;
		$footer_extra_html = (string) ob_get_clean();

		$html_lang = str_replace( '_', '-', '' !== $locale ? $locale : get_locale() );

		$headers   = CommunityMailer::html_headers();
		$headers[] = 'Auto-Submitted: auto-generated';
		$headers[] = 'X-Auto-Response-Suppress: All';
		$headers[] = 'Reply-To: ' . $visitor_email;

		wp_mail(
			$artist->user_email,
			$subject,
			EmailTemplate::render( '' !== $html_lang ? $html_lang : 'en', $body_html, $footer_extra_html ),
			$headers
		);

		if ( '' !== $locale ) {
			restore_current_locale();
		}
	}

	// -------------------------------------------------------------------------
	// Reply gateway (CONTACT-FORM-TRANSLATION-ROADMAP.md §3, CF5/CF6) — no WP
	// login for either party (class docblock's "no login" convention), so ONE
	// symmetric gateway serves BOTH directions: the row being replied to
	// already records who wrote it (`sender`), so the person clicking the
	// link is always the OTHER party — no separate artist-side/visitor-side
	// code paths, no role parameter ever trusted from the request itself.
	// -------------------------------------------------------------------------

	/** Register the template_redirect handler — called directly from Core\Plugin, same convention as ActivityPub::register_reply_moderation_handler(). */
	public function register_reply_gateway_handler(): void {
		add_action( 'template_redirect', [ $this, 'handle_contact_reply' ] );
	}

	private static function contact_reply_token( int $message_id ): string {
		return hash_hmac( 'sha256', "{$message_id}|" . self::REPLY_TOKEN_PURPOSE, wp_salt( 'auth' ) );
	}

	/**
	 * Build the stateless one-click gateway URL for one message row — reused
	 * for BOTH directions (see section docblock above): the row's own
	 * `sender` column tells the eventual recipient who they're replying to
	 * without this URL needing to say so itself.
	 */
	private static function contact_reply_url( int $message_id ): string {
		return add_query_arg(
			[
				'agnosis_contact_reply' => $message_id,
				'token'                 => self::contact_reply_token( $message_id ),
			],
			home_url( '/' )
		);
	}

	/**
	 * Verify a reply-gateway link's token and expiry. Returns null when
	 * valid, or a user-facing error message when not — same contract as
	 * ActivityPub::verify_reply_gateway_token(), this feature's reference
	 * pattern.
	 *
	 * @param array<string, mixed> $row
	 */
	private function verify_reply_token( array $row, string $token ): ?string {
		$message_id = (int) $row['id'];

		if ( '' === $token || ! hash_equals( self::contact_reply_token( $message_id ), $token ) ) {
			return __( 'This link is invalid or has already expired.', 'agnosis' );
		}

		// reply_token_expires_at is stored as a GMT datetime (gmtime() via
		// reply_token_expiry()) — ' +0000' forces strtotime() to parse it as
		// GMT regardless of the server's own default timezone, same idiom
		// Newsletter\Scheduler already uses for its own stored GMT datetimes.
		$expiry = trim( (string) ( $row['reply_token_expires_at'] ?? '' ) );
		if ( '' !== $expiry && time() > strtotime( $expiry . ' +0000' ) ) {
			return __( 'This link has expired.', 'agnosis' );
		}

		return null;
	}

	/**
	 * Fetch one {$wpdb->prefix}agnosis_contact_messages row by id, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function get_message_row( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single-row lookup by primary key, not a per-page-load query.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}agnosis_contact_messages WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * A row's own thread root id — itself, when thread_root_id is null (CF3:
	 * null means "this row IS the root").
	 *
	 * @param array<string, mixed> $row
	 */
	private function thread_root_id_of( array $row ): int {
		$root = (int) ( $row['thread_root_id'] ?? 0 );
		return $root > 0 ? $root : (int) $row['id'];
	}

	/** Configured (or default) visitor reply-depth cap — see CF2/Admin\SettingsFields. */
	private static function reply_depth_limit(): int {
		return max( 1, (int) get_option( self::REPLY_DEPTH_OPTION, self::REPLY_DEPTH_DEFAULT ) );
	}

	/**
	 * How many turns the VISITOR has already sent in this thread — the root
	 * message plus any ACCEPTED (status='sent') follow-up, never a rejected
	 * one: a message the moderation pipeline blocked was never actually
	 * exchanged, so it shouldn't cost the visitor a turn (a reasoned
	 * extension of CF3's design, not something Ulises was separately asked
	 * to confirm — worth flagging as a design call, not a specified rule).
	 * The artist is never counted here at all — see CF2's own field
	 * description for why only visitor turns count toward the limit.
	 */
	private function visitor_turns_used( int $thread_root_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- small per-thread count, only reached from the reply-gateway page/POST, not a per-page-load query.
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_contact_messages
			 WHERE ( id = %d OR thread_root_id = %d ) AND sender = 'visitor' AND status = 'sent'",
			$thread_root_id,
			$thread_root_id
		) );
	}

	/** Whether the visitor may still send another reply in this thread — re-checked server-side on every POST, never trusted from the rendered page (CF6). */
	private function visitor_can_reply_again( int $thread_root_id ): bool {
		return $this->visitor_turns_used( $thread_root_id ) < self::reply_depth_limit();
	}

	/**
	 * Handle a click on the reply-gateway link from email_artist()/
	 * email_visitor_reply(). GET/POST split for the same reason as
	 * ActivityPub::handle_reply_moderation() (its own docblock is the fuller
	 * explanation, unchanged here): a mail-security scanner's prefetch must
	 * never itself submit a reply, only a real POST may.
	 */
	public function handle_contact_reply(): void {
		$is_post = ReviewConfirm::is_post_request();

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- unauthenticated email-link recipient, no WP session; the HMAC token is this flow's nonce equivalent (see ActivityPub::handle_reply_moderation()'s docblock for the shared convention).
		$source = $is_post ? $_POST : $_GET;

		if ( ! isset( $source['agnosis_contact_reply'] ) ) {
			return;
		}

		$message_id = absint( wp_unslash( $source['agnosis_contact_reply'] ) );
		$token      = sanitize_text_field( wp_unslash( $source['token'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		if ( ! $message_id ) {
			wp_die( esc_html__( 'This link is invalid or has already expired.', 'agnosis' ), esc_html__( 'Link error', 'agnosis' ), [ 'response' => 400 ] );
		}

		$row = $this->get_message_row( $message_id );
		if ( null === $row ) {
			wp_die( esc_html__( 'This conversation no longer exists.', 'agnosis' ), esc_html__( 'Link error', 'agnosis' ), [ 'response' => 404 ] );
		}

		$token_error = $this->verify_reply_token( $row, $token );
		if ( null !== $token_error ) {
			wp_die( esc_html( $token_error ), esc_html__( 'Link error', 'agnosis' ), [ 'response' => 400 ] );
		}

		// The person replying is always the OTHER party from whoever wrote
		// $row — see this section's own docblock.
		$role = 'visitor' === $row['sender'] ? 'artist' : 'visitor';

		if ( ! $is_post ) {
			$this->render_contact_reply_gateway( $row, $token, $role );
			return; // render_contact_reply_gateway() always exits via wp_die().
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- token itself is the auth mechanism, see method docblock.
		$this->handle_contact_reply_submission( $row, $role, $source );
	}

	/**
	 * Render the reply gateway page — the row being replied to (original
	 * and, once translated, its translated_message), a reply textarea, and —
	 * only for a VISITOR replier who has exhausted CF2's configured depth —
	 * an explanatory notice instead of the form (Ulises's "we inform about
	 * the limitation" ask). An ARTIST replier never sees this notice; they
	 * are never depth-limited (CF2).
	 *
	 * @param array<string, mixed> $row
	 */
	private function render_contact_reply_gateway( array $row, string $token, string $role ): void {
		$message_id = (int) $row['id'];
		$original   = (string) $row['message'];
		$translated = (string) ( $row['translated_message'] ?? '' );

		$original_html = '<div style="margin:0 0 16px;">'
			. '<p style="font-size:13px;text-transform:uppercase;letter-spacing:0.04em;color:#999;margin:0 0 6px;">' . esc_html__( 'Original', 'agnosis' ) . '</p>'
			. '<p style="font-size:17px;line-height:1.6;margin:0;">' . nl2br( esc_html( $original ) ) . '</p>'
			. '</div>';

		$translated_html = '' !== $translated
			? '<div style="margin:0 0 24px;padding:16px;background:#f7f7fb;border-radius:6px;">'
				. '<p style="font-size:13px;text-transform:uppercase;letter-spacing:0.04em;color:#999;margin:0 0 6px;">' . esc_html__( 'Translated', 'agnosis' ) . '</p>'
				. '<p style="font-size:17px;line-height:1.6;margin:0;">' . nl2br( esc_html( $translated ) ) . '</p>'
				. '</div>'
			: '<p style="font-size:14px;color:#999;margin:0 0 24px;font-style:italic;">' . esc_html__( 'Translation pending…', 'agnosis' ) . '</p>';

		$thread_root_id = $this->thread_root_id_of( $row );

		if ( 'visitor' === $role && ! $this->visitor_can_reply_again( $thread_root_id ) ) {
			$html = sprintf(
				'<div style="max-width:560px;margin:60px auto;font-family:Georgia,serif;color:#222;">'
				. '<p style="font-size:34px;color:#7c6af7;margin:0 0 16px;text-align:center;">✦</p>'
				. '<h1 style="font-size:24px;font-weight:700;margin:0 0 20px;text-align:center;">%1$s</h1>'
				. '%2$s%3$s'
				. '<p style="font-size:15px;line-height:1.6;color:#888;text-align:center;">%4$s</p>'
				. '</div>',
				esc_html__( 'This conversation', 'agnosis' ),
				$original_html, // Built entirely from esc_html()/nl2br() pieces above.
				$translated_html, // Ditto.
				esc_html__( 'You have already used all of your replies for this conversation.', 'agnosis' )
			);
			wp_die( $html, esc_html__( 'This conversation', 'agnosis' ), [ 'response' => 200 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped above.
		}

		$html = sprintf(
			'<div style="max-width:560px;margin:60px auto;font-family:Georgia,serif;color:#222;">'
			. '<p style="font-size:34px;color:#7c6af7;margin:0 0 16px;text-align:center;">✦</p>'
			. '<h1 style="font-size:24px;font-weight:700;margin:0 0 20px;text-align:center;">%1$s</h1>'
			. '%2$s%3$s'
			. '<form method="post" action="%4$s">'
			. '<input type="hidden" name="agnosis_contact_reply" value="%5$s">'
			. '<input type="hidden" name="token" value="%6$s">'
			. '<label style="display:block;font-size:14px;color:#888;margin:0 0 4px;">%7$s</label>'
			. '<textarea name="reply_message" rows="6" style="width:100%%;box-sizing:border-box;padding:10px;font-size:16px;font-family:inherit;border:1px solid #ddd;border-radius:6px;margin:0 0 16px;"></textarea>'
			. '<div style="text-align:center;">'
			. '<button type="submit" style="background:#7c6af7;color:#fff;border:0;border-radius:6px;padding:12px 28px;font-size:17px;font-family:inherit;cursor:pointer;">%8$s</button>'
			. '</div>'
			. '</form>'
			. '</div>',
			esc_html__( 'Reply to this conversation', 'agnosis' ),
			$original_html, // Built entirely from esc_html()/nl2br() pieces above.
			$translated_html, // Ditto.
			esc_url( home_url( '/' ) ),
			esc_attr( (string) $message_id ),
			esc_attr( $token ),
			esc_html__( 'Your reply (it will be translated automatically, if needed)', 'agnosis' ),
			esc_html__( 'Send reply', 'agnosis' )
		);

		wp_die( $html, esc_html__( 'Reply to this conversation', 'agnosis' ), [ 'response' => 200 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped above.
	}

	/**
	 * Handle the gateway page's POST. An artist's reply is trusted
	 * outright — same "reached only via a token-verified emailed link" trust
	 * model as ActivityPub::store_artist_gateway_reply() — a visitor's
	 * follow-up gets the depth limit AND content moderation re-checked
	 * server-side (Ulises's amplification answer: "we apply for all
	 * incoming stuff from visitors basic checks and AI check if
	 * available"), never trusted from whatever the rendered page happened
	 * to show.
	 *
	 * @param array<string, mixed> $row
	 * @param array<string, mixed> $source Sanitized $_POST superglobal.
	 */
	private function handle_contact_reply_submission( array $row, string $role, array $source ): void {
		$message_id     = (int) $row['id'];
		$thread_root_id = $this->thread_root_id_of( $row );
		$reply          = sanitize_textarea_field( wp_unslash( $source['reply_message'] ?? '' ) );

		if ( '' === trim( $reply ) || mb_strlen( $reply ) > self::MAX_MESSAGE_LENGTH ) {
			wp_die(
				esc_html__( 'Please write a reply before sending.', 'agnosis' ),
				esc_html__( 'Reply error', 'agnosis' ),
				[ 'response' => 400 ]
			);
		}

		if ( 'visitor' === $role && ! $this->visitor_can_reply_again( $thread_root_id ) ) {
			wp_die(
				esc_html__( 'You have already used all of your replies for this conversation.', 'agnosis' ),
				esc_html__( 'Reply error', 'agnosis' ),
				[ 'response' => 400 ]
			);
		}

		$rejected         = false;
		$rejection_reason = '';
		if ( 'visitor' === $role ) {
			$rejection_reason = $this->moderate( $reply );
			$rejected         = '' !== $rejection_reason;
		}

		$root = $this->get_message_row( $thread_root_id ) ?? $row;

		// The visitor's own language never changes across a thread —
		// captured once, at the root, via translate_and_detect_for_artist()
		// (submit()) — so a visitor follow-up simply repeats it rather than
		// re-detecting. An artist's reply uses resolve_artist_lang(), the
		// same always-resolvable code every other artist-facing translation
		// in this class already uses.
		$sender_lang      = 'visitor' === $role ? (string) $root['sender_lang'] : SubmissionTranslator::resolve_artist_lang( (int) $row['artist_id'] );
		$sender_lang_name = 'visitor' === $role ? (string) $root['sender_lang_name'] : '';

		$new_id = $this->store(
			(int) $row['artist_id'],
			(string) $root['visitor_name'],
			(string) $root['visitor_email'],
			$reply,
			'',
			$rejected ? 'rejected' : 'sent',
			$rejection_reason,
			[
				'thread_root_id'   => $thread_root_id,
				'parent_id'        => $message_id,
				'sender'           => $role,
				'sender_lang'      => $sender_lang,
				'sender_lang_name' => $sender_lang_name,
				// A rejected reply is never drained/emailed — same "mark
				// delivered at insert time" reasoning as a rejected thread
				// root (see submit()'s own comment).
				'delivered_at'     => $rejected ? current_time( 'mysql', true ) : null,
			]
		);

		if ( ! $new_id ) {
			wp_die( esc_html__( 'Something went wrong sending your reply — please try again.', 'agnosis' ), esc_html__( 'Reply error', 'agnosis' ), [ 'response' => 500 ] );
		}

		// Deliberately the SAME confirmation regardless of whether the
		// message was actually accepted or silently rejected by moderation —
		// same anti-oracle reasoning as submit()'s own identical-REST-response
		// contract (class docblock point 5): a visitor with a valid reply
		// link could otherwise use the confirmation text itself to probe what
		// the content filter blocks.
		wp_die(
			esc_html__( 'Thanks — your reply has been sent.', 'agnosis' ),
			esc_html__( 'Reply sent', 'agnosis' ),
			[ 'response' => 200 ]
		);
	}

	// -------------------------------------------------------------------------
	// CF7 — drain/translate/deliver
	// -------------------------------------------------------------------------

	/**
	 * Translate and email onward every REPLY row (never a thread root — see
	 * submit()'s own comment on why the root is always synchronous) still
	 * awaiting delivery (`delivered_at IS NULL`). Registered on the
	 * `agnosis_drain_contact_reply_queue` 5-minute cron
	 * (Core\Activator::schedule_events()).
	 *
	 * Direction determines both the translation method and its target:
	 *   - An ARTIST's reply is translated toward the ORIGINAL visitor's own
	 *     language, which may not be one of the site's configured languages
	 *     at all — SubmissionTranslator::translate_to_language_name(), keyed
	 *     off the thread root's own `sender_lang_name` (CF3/CF4).
	 *   - A VISITOR's follow-up is translated toward the artist's own
	 *     language, which — being the artist's own declared site locale — is
	 *     always one SubmissionTranslator::translate_fields() can resolve,
	 *     so that existing (constrained) method is reused as-is, same as the
	 *     thread root's own translation call.
	 *
	 * A missing/unconfigured translator must not silently strand the
	 * recipient's notification forever — same reasoning as
	 * ActivityPub::drain_reply_translation_queue()'s own docblock: every row
	 * is still walked and its recipient still emailed below; only the
	 * translation call itself is skipped (falling back to the original
	 * text).
	 */
	public function drain_reply_queue(): void {
		global $wpdb;

		$deadline = microtime( true ) + self::DRAIN_TIME_BUDGET_SECONDS;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cron-only path, bounded by idx_delivered and the queue's own small, self-draining size.
		$rows = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}agnosis_contact_messages WHERE delivered_at IS NULL ORDER BY id ASC LIMIT 50",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		$translator = $this->submission_translator();

		foreach ( $rows as $row ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}
			$this->deliver_reply_row( $row, $translator );
		}
	}

	/**
	 * One drain_reply_queue() row — translate onward and email the
	 * recipient, then mark delivered.
	 *
	 * @param array<string, mixed> $row
	 */
	private function deliver_reply_row( array $row, ?SubmissionTranslator $translator ): void {
		global $wpdb;

		$message_id     = (int) $row['id'];
		$artist_id      = (int) $row['artist_id'];
		$thread_root_id = $this->thread_root_id_of( $row );
		$root           = 'artist' === $row['sender'] ? ( $this->get_message_row( $thread_root_id ) ?? $row ) : $row;

		$translated = '';

		if ( 'artist' === $row['sender'] ) {
			$visitor_lang_name = (string) ( $root['sender_lang_name'] ?? '' );
			if ( null !== $translator && '' !== $visitor_lang_name ) {
				$artist_lang = SubmissionTranslator::resolve_artist_lang( $artist_id );
				$translated  = $translator->translate_to_language_name( (string) $row['message'], $visitor_lang_name, $artist_lang );
			}

			$this->email_visitor_reply( $root, $message_id, $translated, (string) $row['message'], $thread_root_id );
		} else {
			$artist_lang = SubmissionTranslator::resolve_artist_lang( $artist_id );
			if ( null !== $translator && '' !== $artist_lang ) {
				$result     = $translator->translate_fields( [ 'message' => (string) $row['message'] ], $artist_lang );
				$translated = $result['message'] ?? '';
			}

			$artist = get_userdata( $artist_id );
			if ( $artist instanceof WP_User ) {
				$this->email_artist( $artist, $message_id, (string) $row['visitor_name'], (string) $row['visitor_email'], (string) $row['message'], $translated );
			}
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off write, no caching applicable.
			$wpdb->prefix . 'agnosis_contact_messages',
			[
				'translated_message' => '' !== $translated ? $translated : null,
				'delivered_at'       => current_time( 'mysql', true ),
			],
			[ 'id' => $message_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Email the ORIGINAL visitor an artist's reply — the counterpart to
	 * email_artist(), addressed to the party who has no WP account at all
	 * (class docblock's "no login" convention, mirrored from ActivityPub's
	 * own no-login reply gateway). Same branded shell (EmailTemplate), same
	 * reply-gateway button pattern as email_artist(), offered only when the
	 * visitor still has a reply available (visitor_can_reply_again()) —
	 * otherwise an explanatory note instead (Ulises's "we inform about the
	 * limitation" ask).
	 *
	 * @param array<string, mixed> $root
	 */
	private function email_visitor_reply( array $root, int $reply_message_id, string $translated_message, string $original_message, int $thread_root_id ): void {
		$visitor_email = (string) $root['visitor_email'];
		$visitor_name  = (string) ( $root['visitor_name'] ?? '' );
		if ( '' === $visitor_email ) {
			return;
		}

		$body_message = '' !== $translated_message ? $translated_message : $original_message;

		$subject = sprintf(
			/* translators: %s: site name */
			__( 'New reply via %s', 'agnosis' ),
			get_bloginfo( 'name' )
		);

		$excerpt = wp_strip_all_tags( $body_message );
		if ( mb_strlen( $excerpt ) > 600 ) {
			$excerpt = mb_substr( $excerpt, 0, 600 ) . '…';
		}

		ob_start();
		?>
		<p style="margin:0 0 20px;font-size:20px;color:#555;">
			<?php
			printf(
				/* translators: %s: the name of the person being greeted (may fall back to a generic greeting if unavailable) */
				esc_html__( 'Hi %s,', 'agnosis' ),
				esc_html( '' !== $visitor_name ? $visitor_name : __( 'there', 'agnosis' ) )
			);
			?>
		</p>
		<p style="margin:0 0 20px;font-size:19px;line-height:1.6;color:#555;">
			<?php esc_html_e( 'You have a new reply to your message:', 'agnosis' ); ?>
		</p>
		<p style="margin:0 0 28px;padding:16px 20px;background:<?php echo esc_attr( EmailTemplate::notice_bg() ); ?>;border-left:3px solid <?php echo esc_attr( EmailTemplate::accent() ); ?>;border-radius:4px;font-size:18px;font-style:italic;line-height:1.6;color:#333;">
			&ldquo;<?php echo esc_html( $excerpt ); ?>&rdquo;
		</p>
		<?php if ( '' !== $translated_message && $translated_message !== $original_message ) : ?>
		<p style="margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:0.04em;color:#999;">
			<?php esc_html_e( 'Original message, as written', 'agnosis' ); ?>
		</p>
		<p style="margin:0 0 24px;font-size:16px;line-height:1.6;color:#666;">
			<?php echo nl2br( esc_html( $original_message ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html() applied just before nl2br(). ?>
		</p>
		<?php endif; ?>
		<?php if ( $this->visitor_can_reply_again( $thread_root_id ) ) : ?>
		<div style="text-align:center;margin-top:8px;">
			<?php echo EmailTemplate::button( self::contact_reply_url( $reply_message_id ), __( 'Reply', 'agnosis' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmailTemplate::button() escapes internally. ?>
		</div>
		<?php else : ?>
		<p style="margin:16px 0 0;font-size:14px;line-height:1.6;color:#999;font-style:italic;">
			<?php esc_html_e( "You've used all of your replies for this conversation.", 'agnosis' ); ?>
		</p>
		<?php endif; ?>
		<?php
		$body_html = (string) ob_get_clean();

		$html_lang = '' !== (string) ( $root['sender_lang'] ?? '' ) ? (string) $root['sender_lang'] : 'en';

		$headers   = CommunityMailer::html_headers();
		$headers[] = 'Auto-Submitted: auto-generated';
		$headers[] = 'X-Auto-Response-Suppress: All';

		wp_mail(
			$visitor_email,
			$subject,
			EmailTemplate::render( $html_lang, $body_html ),
			$headers
		);
	}
}
