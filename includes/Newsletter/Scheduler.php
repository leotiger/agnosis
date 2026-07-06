<?php
/**
 * Newsletter scheduler — decides when an issue is due, builds it, and fans it
 * out to the send queue.
 *
 * Runs daily via 'agnosis_prepare_newsletters'. Building an issue and queuing
 * its recipients happen together (no separate "draft awaiting review" state):
 * the admin sets an optional intro whenever they like via Settings →
 * Newsletter, and whatever is saved there at prepare time is what goes out.
 * Actual delivery is then handled in small batches by QueueProcessor on its
 * own, more frequent cron tick.
 *
 * This same daily tick also carries Subscriber::expire_stale_pending(), a
 * small piece of unrelated subscriber-table housekeeping (security audit
 * §2d) piggybacked here rather than given its own scheduled event.
 *
 * Clock handling (security audit §3e): every timestamp this class writes or
 * reads for its own scheduling decisions (sent_at, since_window(), is_due())
 * is a WP site-local wall-clock string/timestamp, the same clock post_date
 * and resolved_at already use elsewhere in the newsletter/digest pipeline —
 * never true UTC/gmdate() mixed in. is_due() converts $last_sent to a real
 * Unix epoch via get_gmt_from_date() (DST-correct, via wp_timezone()) rather
 * than a bare strtotime(), which would silently substitute PHP's own ini
 * timezone for the site's configured one.
 *
 * @package Agnosis\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Newsletter;

use Agnosis\AI\SubmissionTranslator;
use Agnosis\Core\Logger;

class Scheduler {

	private const TYPES = [ 'artist', 'public' ];

	/**
	 * Hook callback for 'agnosis_prepare_newsletters' — checks both newsletter
	 * types and prepares any that are due.
	 */
	public function prepare(): void {
		// Unrelated to newsletter issues themselves, but this is the existing
		// daily tick nearest to the subscribe table it maintains (security
		// audit §2d) — not worth a dedicated scheduled event of its own.
		Subscriber::expire_stale_pending();

		foreach ( self::TYPES as $type ) {
			if ( ! get_option( "agnosis_newsletter_{$type}_enabled" ) ) {
				continue;
			}
			if ( $this->is_due( $type ) ) {
				$this->prepare_type( $type );
			}
		}
	}

	/**
	 * Manually trigger an issue right now, bypassing the schedule check.
	 * Used by the "Send Now" button on Settings → Newsletter.
	 *
	 * Still refuses to start a second issue while one is already sending, to
	 * avoid overlapping sends to the same audience.
	 *
	 * @return true|string True on success, or an error message.
	 */
	public function send_now( string $type ): true|string {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return __( 'Unknown newsletter type.', 'agnosis' );
		}

		if ( $this->has_issue_in_flight( $type ) ) {
			return __( 'A previous issue is still sending — please wait for it to finish.', 'agnosis' );
		}

		$this->prepare_type( $type );
		return true;
	}

	/**
	 * Send a one-off preview of the next issue to a single address.
	 *
	 * Uses the same digest window and current draft intro that a real issue
	 * would use, but writes nothing to the issues/queue tables and does not
	 * touch the schedule — it is purely a preview, safe to run at any time,
	 * including while a real issue is mid-send.
	 *
	 * @return true|string True on success, or an error message.
	 */
	public function send_test( string $type, string $to_email ): true|string {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return __( 'Unknown newsletter type.', 'agnosis' );
		}
		if ( ! is_email( $to_email ) ) {
			return __( 'Please enter a valid email address to send the test to.', 'agnosis' );
		}

		$since       = $this->since_window( $type );
		$digest_html = 'artist' === $type ? Digest::build_artist( $since ) : Digest::build_public( $since );
		$intro       = (string) get_option( "agnosis_newsletter_{$type}_intro", '' );

		$test_notice = __( 'This is a TEST send — it previews the next issue using the current draft intro and recent activity. It was not counted as a real send and no subscribers were emailed. The unsubscribe link below is a non-functional placeholder.', 'agnosis' );
		$combined_intro = trim( $test_notice . "\n\n" . $intro );

		// Dummy token — this is a preview, not a real recipient, so the
		// unsubscribe link intentionally does not resolve to anything.
		$unsubscribe_url = add_query_arg(
			[
				'agnosis_newsletter' => '1',
				'action'             => 'unsubscribe',
				'type'               => $type,
				'token'              => 'test-preview',
			],
			home_url( '/' )
		);

		$body    = Mailer::build_email( $type, $combined_intro, $digest_html, $unsubscribe_url );
		/* translators: %s: the actual (non-test) subject line */
		$subject = sprintf( __( '[TEST] %s', 'agnosis' ), Mailer::build_subject( $type ) );

		$sent = wp_mail( $to_email, $subject, $body, [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . Mailer::sender_header(),
		] );

		return $sent ? true : __( "wp_mail() reported a failure — check your site's outgoing mail configuration.", 'agnosis' );
	}

	// -------------------------------------------------------------------------
	// Internal
	// -------------------------------------------------------------------------

	private function is_due( string $type ): bool {
		if ( $this->has_issue_in_flight( $type ) ) {
			return false;
		}

		$last_sent = $this->last_sent_at( $type );
		if ( null === $last_sent ) {
			return true; // Never sent — the first issue is always due.
		}

		$frequency_days = max( 1, (int) get_option( "agnosis_newsletter_{$type}_frequency_days", 30 ) );

		// $last_sent is a WP site-local wall-clock string (sent_at is written via
		// current_time('mysql')). A bare strtotime($last_sent) would silently
		// parse it using PHP's own ini date.timezone — a setting that has
		// nothing to do with, and often differs from, the site's configured
		// WordPress timezone — double-converting the offset against time()'s
		// true UTC epoch below (security audit §3e). get_gmt_from_date() is WP
		// core's own local-wall-clock -> UTC converter (goes through
		// wp_timezone(), so it's DST-correct for the actual date in question),
		// making this comparison correct for whatever timezone the site uses.
		$last_sent_ts = strtotime( get_gmt_from_date( $last_sent ) . ' +0000' );
		$elapsed      = time() - $last_sent_ts;

		return $elapsed >= $frequency_days * DAY_IN_SECONDS;
	}

	/** Public for the Settings → Newsletter dashboard ("currently sending" display). */
	public function has_issue_in_flight( string $type ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_newsletter_issues WHERE newsletter_type = %s AND status = 'sending'",
				$type
			)
		);

		return $count > 0;
	}

	/** Public for the Settings → Newsletter dashboard ("last sent" display). */
	public function last_sent_at( string $type ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sent_at = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT sent_at FROM {$wpdb->prefix}agnosis_newsletter_issues
				 WHERE newsletter_type = %s AND status = 'sent'
				 ORDER BY sent_at DESC LIMIT 1",
				$type
			)
		);

		return $sent_at ? (string) $sent_at : null;
	}

	/**
	 * The digest content window: everything since the last sent issue, or
	 * (for a newsletter that has never sent) one frequency-period back from now.
	 *
	 * Both branches return a WP site-local wall-clock string — the same clock
	 * Digest::recent_posts() compares against post_date (site-local) and
	 * Digest::newly_admitted_artists() compares against resolved_at (also
	 * written via current_time('mysql')). Previously the never-sent-before
	 * fallback used gmdate() (true UTC) while the common case returned
	 * $last_sent (site-local), so a site with a non-zero UTC offset got its
	 * very first digest window shifted by that offset against post_date —
	 * security audit §3e.
	 */
	private function since_window( string $type ): string {
		$frequency_days = max( 1, (int) get_option( "agnosis_newsletter_{$type}_frequency_days", 30 ) );
		$last_sent      = $this->last_sent_at( $type );

		if ( $last_sent ) {
			return $last_sent;
		}

		// wp_timezone() + DateTime gives the site-local wall-clock string
		// directly, without current_time('timestamp')'s "fake UTC" timestamp
		// trick (discouraged by WPCS — WordPress.DateTime.CurrentTimeTimestamp
		// — since it's easy to misuse; here it would need pairing with gmdate(),
		// not date(), to avoid a second, server-ini-timezone shift on top).
		$fallback = new \DateTime( 'now', wp_timezone() );
		$fallback->modify( sprintf( '-%d seconds', $frequency_days * DAY_IN_SECONDS ) );

		return $fallback->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Build the digest, insert the issue row, and fan out the current
	 * recipient list into the send queue.
	 *
	 * Recipients are grouped by their resolved locale (their own locale, or the
	 * site default when none is known), and the digest + intro are rendered once
	 * per locale group — not once per recipient, and not once globally — so each
	 * recipient reads the newsletter in their own language while a group sharing
	 * a locale still only costs one render (and, for the intro, one AI
	 * translation call) rather than one per person.
	 */
	private function prepare_type( string $type ): void {
		global $wpdb;

		$since      = $this->since_window( $type );
		$recipients = 'artist' === $type ? $this->artist_recipients() : $this->public_recipients();

		$default_locale = get_locale();
		$admin_intro    = (string) get_option( "agnosis_newsletter_{$type}_intro", '' );

		// Group by resolved locale. The default-locale group always exists, even
		// with zero recipients in it, so the issue row always has a base render
		// (used as the fallback and by the "Send Test" preview).
		$locales_present = [ $default_locale => true ];
		foreach ( $recipients as $recipient ) {
			$locales_present[ $this->effective_locale( $recipient, $default_locale ) ] = true;
		}

		$locale_content = [];
		foreach ( array_keys( $locales_present ) as $locale ) {
			$locale_content[ $locale ] = $this->render_locale_content( $type, $locale, $default_locale, $since, $admin_intro );
		}

		$base = $locale_content[ $default_locale ];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_newsletter_issues',
			[
				'newsletter_type' => $type,
				'status'          => 'sending',
				'intro'           => $base['intro'],
				'digest_html'     => $base['digest_html'],
				'locale_content'  => wp_json_encode( $locale_content ),
				'recipient_count' => count( $recipients ),
				'scheduled_at'    => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
		);
		$issue_id = (int) $wpdb->insert_id;

		if ( empty( $recipients ) ) {
			// Nobody to send to — mark as sent immediately so it doesn't block the next cycle.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'agnosis_newsletter_issues',
				[ 'status' => 'sent', 'sent_at' => current_time( 'mysql' ) ],
				[ 'id' => $issue_id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
			Logger::info( sprintf( 'Newsletter (%s): issue #%d prepared with zero recipients — nothing to send.', $type, $issue_id ), 'newsletter' );
			return;
		}

		foreach ( $recipients as $recipient ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$wpdb->prefix . 'agnosis_newsletter_queue',
				[
					'issue_id'          => $issue_id,
					'recipient_id'      => $recipient['id'],
					'recipient_email'   => $recipient['email'],
					'recipient_type'    => $type,
					'unsubscribe_token' => $recipient['token'],
					'locale'            => $this->effective_locale( $recipient, $default_locale ),
				],
				[ '%d', '%d', '%s', '%s', '%s', '%s' ]
			);
		}

		// The intro is a one-shot note for this issue only — clear it so it
		// doesn't silently repeat on the next cycle unless the admin re-writes it.
		update_option( "agnosis_newsletter_{$type}_intro", '' );

		Logger::info(
			sprintf( 'Newsletter (%s): issue #%d prepared and queued for %d recipient(s) across %d locale(s).', $type, $issue_id, count( $recipients ), count( $locale_content ) ),
			'newsletter'
		);
	}

	/**
	 * @param array{id: int|null, email: string, token: string, locale: string} $recipient
	 */
	private function effective_locale( array $recipient, string $default_locale ): string {
		return '' !== $recipient['locale'] ? $recipient['locale'] : $default_locale;
	}

	/**
	 * Render the digest + intro for one locale group.
	 *
	 * @return array{intro: string, digest_html: string}
	 */
	private function render_locale_content( string $type, string $locale, string $default_locale, string $since, string $admin_intro ): array {
		$switched = false;
		if ( $locale !== $default_locale ) {
			$switched = switch_to_locale( $locale );
		}

		$lf_lang     = $this->resolve_lf_lang( $locale );
		$digest_html = 'artist' === $type ? Digest::build_artist( $since, $lf_lang ) : Digest::build_public( $since, $lf_lang );

		$intro = $admin_intro;
		if ( '' !== $admin_intro && $locale !== $default_locale && '' !== $lf_lang ) {
			$translator = SubmissionTranslator::from_settings();
			if ( null !== $translator ) {
				$intro = $translator->translate_text( $admin_intro, $lf_lang );
			}
			// No AI provider configured — leave the admin's original text. A
			// same-language intro for everyone beats blocking the send on it.
		}

		if ( $switched ) {
			restore_current_locale();
		}

		return [ 'intro' => $intro, 'digest_html' => $digest_html ];
	}

	/**
	 * Resolve a WP locale (e.g. 'es_ES') to a Lingua Forge language code (e.g.
	 * 'es') — the same primary-subtag heuristic SubmissionTranslator's own
	 * resolve_target_language() uses. Returns '' when LF isn't active, which
	 * callers treat as "no translation lookup possible."
	 *
	 * Deliberately does NOT validate against linguaforge_is_valid_lang()/
	 * languages() — that list is tied to which WP language packs happen to be
	 * installed, which has nothing to do with whether translated agnosis_artwork
	 * posts actually exist for this code. Digest::localized_post() already
	 * handles "no translation for this code" by falling back to the original
	 * post, so an over-eager validity check here would only cause false
	 * negatives (skipping a lookup that would have found a real translation).
	 */
	private function resolve_lf_lang( string $locale ): string {
		if ( ! function_exists( 'linguaforge_get_translations' ) ) {
			return '';
		}

		return strtolower( substr( $locale, 0, 2 ) );
	}

	/**
	 * @return array<int, array{id: int|null, email: string, token: string, locale: string}>
	 */
	private function artist_recipients(): array {
		$query = new \WP_User_Query( [
			'role'       => 'agnosis_artist',
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small table (admitted artists only), acceptable.
				'relation' => 'OR',
				[ 'key' => '_agnosis_newsletter_optout', 'compare' => 'NOT EXISTS' ],
				[ 'key' => '_agnosis_newsletter_optout', 'value' => '1', 'compare' => '!=' ],
			],
			'fields'     => [ 'ID', 'user_email' ],
			'number'     => -1,
		] );

		$recipients = [];
		foreach ( $query->get_results() as $user ) {
			$recipients[] = [
				'id'     => (int) $user->ID,
				'email'  => $user->user_email,
				'token'  => Tokens::artist_unsubscribe_token( (int) $user->ID ),
				'locale' => (string) get_user_meta( (int) $user->ID, 'locale', true ),
			];
		}

		return $recipients;
	}

	/**
	 * @return array<int, array{id: null, email: string, token: string, locale: string}>
	 */
	private function public_recipients(): array {
		$recipients = [];
		foreach ( Subscriber::confirmed_recipients() as $row ) {
			$recipients[] = [
				'id'     => null,
				'email'  => (string) $row['email'],
				'token'  => (string) $row['token'],
				'locale' => (string) ( $row['locale'] ?? '' ),
			];
		}

		return $recipients;
	}
}
