<?php
/**
 * Artist admission — community vouching system.
 *
 * An artist applies without a WP account. The application is stored in
 * agnosis_applications. Existing artists and the admin vote via email links
 * (yes / no) which are recorded in agnosis_application_vouches.
 *
 * Admission rules (all configurable in Settings → Network):
 *   - Minimum positive votes = max( ceil( active_artists × percent / 100 ), minimum )
 *     Default: 10 % of active artists, at least 3 positive votes.
 *   - Voting window: 7 days. If the threshold is not reached within that window
 *     the application is marked rejected and both parties are notified.
 *   - Negative votes are recorded but do not subtract from the positive count.
 *   - Votes can be changed (clicking the other link overwrites the previous vote).
 *
 * No WP user is ever created before the community approves the application.
 *
 * REST endpoints:
 *   POST /agnosis/v1/admission/apply       — submit application (unauthenticated)
 *   POST /agnosis/v1/admission/vouch/{id}  — cast a vote (artists only, REST)
 *   GET  /agnosis/v1/admission/status/{id} — check application status (admin only)
 *
 * Email-link voting is handled by VouchConfirm (template_redirect).
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

use Agnosis\AI\SubmissionTranslator;
use Agnosis\Core\Logger;
use Agnosis\Core\Privacy;
use Agnosis\Core\RateLimiter;
use Agnosis\Core\Turnstile;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class Admission {

	/**
	 * How long a still-unverified reapplication is throttled (mirrors
	 * Newsletter\Subscriber::RESEND_COOLDOWN_SECONDS — security audit §3a):
	 * resubmitting the join form for the same still-unconfirmed address
	 * within this window neither churns a fresh token nor resends the
	 * confirmation email, so a bot hammering the form (already bounded by
	 * the 5/min/IP rate limit) can't multiply the mail it triggers just by
	 * retrying.
	 */
	private const RESEND_COOLDOWN_SECONDS = 300; // 5 minutes.

	/**
	 * How long an unconfirmed 'unverified' row survives before
	 * expire_stale_unverified() (piggybacked on the existing daily
	 * 'agnosis_check_admissions' cron) deletes it — mirrors
	 * Newsletter\Subscriber::PENDING_EXPIRY_DAYS.
	 */
	private const UNVERIFIED_EXPIRY_DAYS = 14;

	/**
	 * Per-email reapplication cooldown (security audit §4b): a
	 * withdrawn/rejected/left application reapplying is legitimate by
	 * design, but with no per-email limit the same address could cycle
	 * apply → confirm → community vote blast → withdraw → apply again, at
	 * whatever rate the 5/min/IP endpoint limit and the resend cooldown
	 * allow. RateLimiter::check_sender() (the same per-sender-throttle
	 * class/pattern the intake mailbox already uses) is consulted only on
	 * that specific reapplication branch — see apply() — making one lap of
	 * the cycle cost a week, not a few minutes. Brand-new addresses and a
	 * resend of a still-unconfirmed application are unaffected; only a
	 * resolved application's own address is throttled here.
	 */
	private const REAPPLY_LIMIT         = 1;
	private const REAPPLY_WINDOW_SECONDS = WEEK_IN_SECONDS;

	/**
	 * Server-side length caps on application fields (security audit §3b):
	 * bio/statement/display_name were sanitized but uncapped, and the vote
	 * email embeds all three for every recipient — a multi-megabyte
	 * statement is a mail-size and storage nuisance at minimum. Enforced in
	 * register_routes()'s REST `args` validate_callback (never trust the
	 * join form's own `maxlength` alone — see JoinPage.php for the mirrored
	 * client-side hint).
	 */
	private const MAX_DISPLAY_NAME_LENGTH = 100;
	private const MAX_BIO_LENGTH          = 5000;
	private const MAX_STATEMENT_LENGTH    = 5000;

	public function register_routes(): void {
		register_rest_route( 'agnosis/v1', '/admission/apply', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'apply' ],
			'permission_callback' => [ $this, 'rate_limit_apply' ],
			'args'                => [
				'email'         => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_email',
					'validate_callback' => fn( string $v ): bool => (bool) is_email( $v ),
				],
				'display_name'  => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => fn( string $v ): bool|WP_Error => self::validate_max_length( $v, self::MAX_DISPLAY_NAME_LENGTH, __( 'Name', 'agnosis' ) ),
				],
				'bio'           => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'validate_callback' => fn( string $v ): bool|WP_Error => self::validate_max_length( $v, self::MAX_BIO_LENGTH, __( 'Bio', 'agnosis' ) ),
				],
				'portfolio_url' => [
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				],
				'statement'     => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'validate_callback' => fn( string $v ): bool|WP_Error => self::validate_max_length( $v, self::MAX_STATEMENT_LENGTH, __( 'Statement', 'agnosis' ) ),
				],
				'language'      => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				],
				'turnstile_token' => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );

		register_rest_route( 'agnosis/v1', '/admission/vouch/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'vouch' ],
			'permission_callback' => [ $this, 'require_artist' ],
			'args'                => [
				'id'      => [ 'type' => 'integer', 'required' => true ],
				'vote'    => [
					'type'    => 'string',
					'enum'    => [ 'yes', 'no' ],
					'default' => 'yes',
				],
				'message' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
			],
		] );

		register_rest_route( 'agnosis/v1', '/admission/status/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'status' ],
			'permission_callback' => [ $this, 'require_admin' ],
			'args'                => [
				'id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );
	}

	/**
	 * REST `validate_callback` for a length-capped text field (security audit
	 * §3b). Runs before `sanitize_callback`, so `$value` is the raw submitted
	 * string — `mb_strlen()` (not `strlen()`) so multibyte scripts aren't
	 * penalized for their own byte width.
	 *
	 * @param string $value       Raw (unsanitized) field value.
	 * @param int    $max         Maximum character count.
	 * @param string $field_label Human-readable field name for the error message.
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
	// Callbacks
	// -------------------------------------------------------------------------

	public function apply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$turnstile = Turnstile::verify( (string) ( $request->get_param( 'turnstile_token' ) ?? '' ) );
		if ( is_wp_error( $turnstile ) ) {
			return $turnstile;
		}

		$email        = (string) $request->get_param( 'email' );
		$display_name = (string) $request->get_param( 'display_name' );
		$bio          = (string) ( $request->get_param( 'bio' ) ?? '' );
		$portfolio    = (string) ( $request->get_param( 'portfolio_url' ) ?? '' );
		$statement    = (string) ( $request->get_param( 'statement' ) ?? '' );
		$language     = sanitize_key( (string) ( $request->get_param( 'language' ) ?? '' ) );

		// Language is required, not merely preferred: an artist's language
		// gates their own downstream experience (the acknowledgment email's
		// locale, and the WP account locale set on admission — see
		// AdmissionNotification and maybe_admit()/admin_admit() below). It must
		// also be one Lingua Forge is actually configured to support on this
		// site — never guessed from the browser's Accept-Language header, and
		// never trusted if it isn't in that active-language list, since either
		// would risk recording a language this instance doesn't really support,
		// which only surfaces later as a silently broken experience.
		//
		// This used to fail open: an empty or unrecognized code was quietly
		// blanked to '' and the application proceeded anyway. That meant a
		// client that skipped the field (the Join form's <form> uses
		// `novalidate` and, until now, ran no validation of its own before
		// submitting) could create an application with no language at all,
		// which is exactly what silently produced acknowledgment emails and
		// WP accounts in the site's default language regardless of what the
		// artist actually picked. Reject outright instead.
		if ( '' === $language || ! array_key_exists( $language, SubmissionTranslator::language_names() ) ) {
			return new WP_Error(
				'agnosis_language_required',
				__( 'Please select your language.', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		// Block if a WP account already exists for this email.
		if ( get_user_by( 'email', $email ) ) {
			return new WP_Error(
				'agnosis_email_exists',
				__( 'An account with this email address already exists.', 'agnosis' ),
				[ 'status' => 409 ]
			);
		}

		// Check for an existing application row. is_recent is computed by MySQL
		// itself (comparing applied_at against its own NOW()), same clock-safety
		// reasoning as Newsletter\Subscriber::subscribe() — no PHP/MySQL
		// clock-mixing risk.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status, banned_until,
				        ( applied_at > ( NOW() - INTERVAL %d SECOND ) ) AS is_recent
				 FROM {$wpdb->prefix}agnosis_applications WHERE email = %s",
				self::RESEND_COOLDOWN_SECONDS,
				$email
			)
		);

		if ( $existing && in_array( $existing->status, [ 'pending', 'waitlisted', 'admitted' ], true ) ) {
			return new WP_Error(
				'agnosis_already_applied',
				'admitted' === $existing->status
					? __( 'This email address belongs to an admitted artist.', 'agnosis' )
					: __( 'An application for this email address is already pending.', 'agnosis' ),
				[ 'status' => 409 ]
			);
		}

		// A banned application must block reapplication too (security audit
		// §2c) — before this branch existed, 'banned' fell all the way through
		// to the generic "every other case" branch below, which silently
		// re-parked the row as 'unverified' with a fresh confirm token,
		// overwriting the ban record outright. In practice the get_user_by()
		// check above already blocks the common case, since admin_ban() only
		// removes the agnosis_artist role — it doesn't delete the WP account —
		// but that account can still be deleted independently (e.g. a site
		// admin using wp-admin's own Users screen rather than
		// Departure::admin_delete()), which leaves a 'banned' application row
		// with no WP account behind it and nothing else to stop this method's
		// reapplication path.
		//
		// The response here is deliberately enumeration-neutral: identical to
		// a brand-new application's "pending_confirmation" response, with
		// nothing actually created or modified and no email sent — a caller
		// probing this endpoint cannot distinguish "this address is banned"
		// from "your application is being processed", the same
		// information-leak concern the double-opt-in rework above already
		// guards against for unverified rows.
		//
		// banned_until is honored (optional per the audit, implemented here):
		// a lapsed temporary ban — check_expired_bans()' own daily-cron expiry
		// test, mirrored here — is treated like any other resolved
		// withdrawn/rejected/left row and allowed to fall through to the
		// normal reapply path below, rather than making the artist wait for
		// that cron's next tick just to resubmit the join form. A
		// still-future or permanent (banned_until IS NULL) ban stays blocked.
		$ban_lapsed = false;
		if ( $existing && 'banned' === $existing->status ) {
			$ban_lapsed = null !== $existing->banned_until && strtotime( (string) $existing->banned_until ) <= time();

			if ( ! $ban_lapsed ) {
				return new WP_REST_Response( [
					'status'           => 'pending_confirmation',
					'application_id'   => (int) $existing->id,
					'vouches_required' => $this->calculate_required(),
				], 201 );
			}
		}

		// Double opt-in (security audit §3a/§4a): a still-unverified row for
		// this address, resubmitted within the resend cooldown — an impatient
		// double-click, or a bot retrying within the existing 5/min/IP rate
		// limit — gets the same response with no new token and no second
		// email. Mirrors Newsletter\Subscriber::subscribe()'s §2d hardening.
		if ( $existing && 'unverified' === $existing->status && $existing->is_recent ) {
			return new WP_REST_Response( [
				'status'         => 'pending_confirmation',
				'application_id' => (int) $existing->id,
			], 201 );
		}

		// Per-email reapplication cooldown (security audit §4b): a
		// withdrawn/rejected/left application reapplying is legitimate — the
		// exposure is specifically that address cycling apply → confirm →
		// vote blast → withdraw → apply again, bounded only by the 5/min/IP
		// endpoint limit and the (much shorter) unverified-resend cooldown
		// above. Scoped narrowly to a *resolved* prior application reapplying
		// — a brand-new address ($existing === null) and a still-unconfirmed
		// resend past its own cooldown are untouched by this guard. A lapsed
		// ban ($ban_lapsed, set above) reapplying is included here too — it's
		// the same "resolved application, address proven reachable, address
		// cycling risk" shape as withdrawn/rejected/left, just reached via a
		// different prior status.
		if ( $existing && ( in_array( $existing->status, [ 'withdrawn', 'rejected', 'left' ], true ) || $ban_lapsed ) ) {
			$sender_rate = RateLimiter::check_sender( 'admission_apply_email', $email, self::REAPPLY_LIMIT, self::REAPPLY_WINDOW_SECONDS );
			if ( is_wp_error( $sender_rate ) ) {
				return $sender_rate;
			}
		}

		// Every other case — brand new address, an unverified row past its
		// cooldown (resend), a withdrawn/rejected/left row reapplying, or a
		// lapsed-ban row reapplying ($ban_lapsed above) — parks (or re-parks)
		// the row as 'unverified' with a fresh single-use
		// token. Nothing becomes 'pending'/'waitlisted' here, and neither the
		// acknowledgment email nor the community vote blast fire yet — only
		// confirm_application() (reached by clicking the link in the
		// "confirm your application" email below) does that. This is what
		// closes the two lanes the audit flagged: a forged address can no
		// longer trigger backscatter to a victim, and no attacker-controlled
		// content reaches the community without the sender first proving they
		// control the inbox.
		$token = bin2hex( random_bytes( 32 ) );

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}agnosis_applications
					 SET display_name = %s, bio = %s, portfolio_url = %s, statement = %s,
					     language = %s, status = 'unverified', confirm_token = %s,
					     wp_user_id = NULL, applied_at = %s, resolved_at = NULL,
					     banned_until = NULL
					 WHERE id = %d",
					$display_name,
					$bio,
					$portfolio,
					$statement,
					$language,
					$token,
					current_time( 'mysql' ),
					$existing->id
				)
			);

			$application_id = (int) $existing->id;
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$wpdb->prefix . 'agnosis_applications',
				[
					'email'         => $email,
					'display_name'  => $display_name,
					'bio'           => $bio,
					'portfolio_url' => $portfolio,
					'statement'     => $statement,
					// $language is guaranteed non-empty here — apply() returns a 400
					// WP_Error earlier when it's missing or unrecognized (see above).
					'language'      => $language,
					'status'        => 'unverified',
					'confirm_token' => $token,
				],
				[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
			);

			$application_id = (int) $wpdb->insert_id;
		}

		// The one and only email apply() itself triggers: a short "confirm your
		// application" link. No acknowledgment, no vote blast — see
		// AdmissionNotification::on_application_unverified().
		do_action( 'agnosis_application_unverified', $application_id, $email, $display_name, $token );

		$response_data = [
			'status'           => 'pending_confirmation',
			'application_id'   => $application_id,
			'vouches_required' => $this->calculate_required(),
		];

		// Resolved against the language the artist just selected above (already
		// validated against Lingua Forge's active languages) — the "what
		// happens next" page, if one is configured, is sent to the artist in
		// their own language when a translation exists. Omitted entirely when
		// nothing is configured, so the frontend's own static fallback
		// (JoinPage::success_redirect_url(), baked in at page render) applies
		// instead — see JoinPage::resolve_success_url()'s docblock.
		$redirect_url = JoinPage::resolve_success_url( $language );
		if ( '' !== $redirect_url ) {
			$response_data['redirect_url'] = $redirect_url;
		}

		return new WP_REST_Response( $response_data, 201 );
	}

	/**
	 * Confirm a pending application by its single-use token (double opt-in
	 * click — security audit §3a/§4a).
	 *
	 * Flips 'unverified' → 'pending' or 'waitlisted', re-checking
	 * CommunityCap at confirmation time rather than trusting whatever it was
	 * at apply() time — the count can change in the interval, same reasoning
	 * maybe_admit() already applies at the admission end of this flow. This
	 * is the one and only place agnosis_artist_applied / agnosis_artist_waitlisted
	 * fire for a freshly submitted application: a forged address can never
	 * trigger the acknowledgment email or the community vote blast, only
	 * whoever actually controls the inbox that received the confirm link can.
	 *
	 * @param string $token The confirm_token from the confirmation email link.
	 * @return array{id: int, status: string, display_name: string, email: string}|false False when the token is unknown, already used, or the row is no longer 'unverified'.
	 */
	public function confirm_application( string $token ): array|false {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$application = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agnosis_applications WHERE confirm_token = %s AND status = 'unverified'",
				$token
			)
		);

		if ( ! $application ) {
			return false;
		}

		/** @var object{id: int, email: string, display_name: string, language: string|null} $application */

		$waitlisted = ( new CommunityCap() )->is_full();
		$new_status = $waitlisted ? 'waitlisted' : 'pending';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'agnosis_applications',
			[
				'status'        => $new_status,
				'confirm_token' => null,
				// Restart the clock from confirmation, not from whenever the
				// unverified row was first created — the voting window
				// (check_expired_applications()) and the admin dashboard's
				// "applied N days ago" should both count from when the
				// application actually opened for review, not from however
				// long the confirmation email sat unread.
				'applied_at'    => current_time( 'mysql' ),
			],
			[ 'id' => $application->id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);

		if ( $waitlisted ) {
			do_action( 'agnosis_artist_waitlisted', (int) $application->id, $application->email, $application->display_name );
		} else {
			do_action( 'agnosis_artist_applied', (int) $application->id, $application->email, $application->display_name );
		}

		return [
			'id'           => (int) $application->id,
			'status'       => $new_status,
			'display_name' => $application->display_name,
			'email'        => $application->email,
		];
	}

	/**
	 * Delete abandoned, never-confirmed 'unverified' rows older than
	 * UNVERIFIED_EXPIRY_DAYS (security audit §3a hardening note — mirrors
	 * Newsletter\Subscriber::expire_stale_pending()). Without this, a bot
	 * hammering the join form with a fresh address each time (still bounded
	 * by the existing 5/min/IP rate limit) accumulates rows forever — and
	 * every one of them triggered a confirmation email. Confirmed/pending/
	 * waitlisted/admitted/etc. rows are never touched.
	 *
	 * Piggybacks on the existing daily 'agnosis_check_admissions' cron
	 * (alongside check_expired_applications()) rather than registering a new
	 * scheduled event for what is a low-volume cleanup task.
	 */
	public function expire_stale_unverified(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$deleted = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}agnosis_applications
				 WHERE status = 'unverified' AND applied_at < ( NOW() - INTERVAL %d DAY )",
				self::UNVERIFIED_EXPIRY_DAYS
			)
		);

		if ( $deleted > 0 ) {
			Logger::info( sprintf( 'Admission: expired %d abandoned unverified application(s) older than %d days.', $deleted, self::UNVERIFIED_EXPIRY_DAYS ), 'admission' );
		}
	}

	/**
	 * Anonymize resolved applications older than
	 * `agnosis_application_retention_days` (default 180 — legal audit §4c).
	 * Before this, `rejected`/`withdrawn`/`left`/`banned` rows retained
	 * email, bio, statement, and portfolio URL forever — a rejected
	 * applicant who never became a member had their personal statement
	 * stored indefinitely with no automatic retention limit (only the
	 * on-demand DSAR eraser, `Core\Privacy::erase_application()`, could
	 * remove it, and only if the data subject knew to ask).
	 *
	 * Piggybacks on the existing daily 'agnosis_check_admissions' cron,
	 * alongside expire_stale_unverified(), rather than a new scheduled
	 * event. Shares its actual redaction with `Core\Privacy`'s own eraser
	 * via `Privacy::anonymize_application_row()` (the audit's own fix text:
	 * "tie into §4a eraser") so an application anonymized by this automatic
	 * sweep and one anonymized by a data subject's own erasure request end
	 * up in an identical state:
	 *
	 *  - `rejected`/`withdrawn`/`left`: fully anonymized, email included —
	 *    there's no ongoing reason to retain a resolved-and-gone
	 *    applicant's identity. The row itself (status, dates) is kept for
	 *    admission history and community-cap accounting.
	 *  - `banned`: bio/statement/portfolio/display_name anonymized, but the
	 *    email is deliberately left untouched — same reasoning as
	 *    `Privacy::erase_application()`'s own banned-row branch: it's the
	 *    only way this site can keep enforcing the ban.
	 *
	 * Gated on `resolved_at IS NOT NULL` (always set when a row leaves
	 * 'pending' — see Core\Activator's own schema comment) and on
	 * `display_name != Privacy::REDACTED_MARKER`, so an already-redacted
	 * row — from a prior sweep, or from a data subject's own request via
	 * `Core\Privacy` — is never reprocessed.
	 */
	public function anonymize_resolved_applications(): void {
		global $wpdb;

		$days = max( 1, (int) get_option( 'agnosis_application_retention_days', 180 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$resolved = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, status FROM {$wpdb->prefix}agnosis_applications
				 WHERE status IN ('rejected','withdrawn','left','banned')
				   AND resolved_at IS NOT NULL
				   AND resolved_at < ( NOW() - INTERVAL %d DAY )
				   AND display_name != %s",
				$days,
				Privacy::REDACTED_MARKER
			)
		);

		$anonymized = 0;
		foreach ( $resolved as $row ) {
			Privacy::anonymize_application_row( (int) $row->id, 'banned' !== $row->status );
			++$anonymized;
		}

		if ( $anonymized > 0 ) {
			Logger::info( sprintf( 'Admission: anonymized %d resolved application(s) older than %d days (legal audit §4c).', $anonymized, $days ), 'admission' );
		}
	}

	public function vouch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$voucher_id     = get_current_user_id();
		$application_id = (int) $request->get_param( 'id' );
		$vote           = (string) ( $request->get_param( 'vote' ) ?? 'yes' );
		$message        = (string) ( $request->get_param( 'message' ) ?? '' );

		return $this->record_vote( $voucher_id, $application_id, $vote, $message );
	}

	public function status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$application_id = (int) $request->get_param( 'id' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$application = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agnosis_applications WHERE id = %d",
				$application_id
			)
		);

		if ( ! $application ) {
			return new WP_Error(
				'agnosis_not_found',
				__( 'Application not found.', 'agnosis' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response( [
			'id'               => (int) $application->id,
			'email'            => $application->email,
			'display_name'     => $application->display_name,
			'status'           => $application->status,
			'wp_user_id'       => $application->wp_user_id ? (int) $application->wp_user_id : null,
			'vouches_received' => $this->count_positive_vouches( (int) $application->id ),
			'vouches_required' => $this->calculate_required(),
			'applied_at'       => $application->applied_at,
			'resolved_at'      => $application->resolved_at,
		] );
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public function rate_limit_apply(): bool|WP_Error {
		$rate = RateLimiter::check( 'admission_apply', 5, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}
		return true;
	}

	public function require_artist(): bool|WP_Error {
		$rate = RateLimiter::check( 'admission_vouch', 20, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		return $this->is_artist( get_current_user_id() )
			? true
			: new WP_Error(
				'agnosis_not_artist',
				__( 'Only admitted artists can vouch.', 'agnosis' ),
				[ 'status' => 403 ]
			);
	}

	public function require_admin(): bool|WP_Error {
		return current_user_can( 'manage_options' )
			? true
			: new WP_Error(
				'agnosis_forbidden',
				__( 'Admin access required.', 'agnosis' ),
				[ 'status' => 403 ]
			);
	}

	// -------------------------------------------------------------------------
	// Core voting logic — used by both the REST endpoint and VouchConfirm
	// -------------------------------------------------------------------------

	/**
	 * Record or update a vote for an application.
	 *
	 * Uses INSERT … ON DUPLICATE KEY UPDATE so artists can change their mind
	 * within the voting window. Triggers maybe_admit() after a 'yes' vote.
	 *
	 * @param int    $voucher_id     WP user ID of the voting artist.
	 * @param int    $application_id Row ID in agnosis_applications.
	 * @param string $vote           'yes' or 'no'.
	 * @param string $message        Optional personal note (stored for audit).
	 */
	public function record_vote(
		int $voucher_id,
		int $application_id,
		string $vote,
		string $message = ''
	): WP_REST_Response|WP_Error {
		global $wpdb;

		// agnosis_voting_disabled ("admin approval only"): checked here, the one
		// choke point shared by the authenticated REST vouch route (vouch()
		// above) and the unauthenticated email-link vote (VouchConfirm), so
		// neither path can cast a vote once a site has switched to admin-only
		// admission. Applications still reach 'pending' as before — they just
		// wait for Admission::admin_admit()/admin_reject() instead of a
		// community threshold (see check_expired_applications() below, which
		// stops auto-rejecting them for the same reason).
		if ( (bool) get_option( 'agnosis_voting_disabled', false ) ) {
			return new WP_Error(
				'agnosis_voting_disabled',
				__( 'Community voting is disabled on this site. Applications are reviewed directly by an admin.', 'agnosis' ),
				[ 'status' => 403 ]
			);
		}

		$vote = in_array( $vote, [ 'yes', 'no' ], true ) ? $vote : 'yes';

		// Load the application.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$application = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status, email FROM {$wpdb->prefix}agnosis_applications WHERE id = %d",
				$application_id
			)
		);

		if ( ! $application ) {
			return new WP_Error(
				'agnosis_not_found',
				__( 'Application not found.', 'agnosis' ),
				[ 'status' => 404 ]
			);
		}

		if ( 'pending' !== $application->status ) {
			return new WP_REST_Response( [ 'status' => $application->status ], 200 );
		}

		// Artists cannot vote on their own application — guard by email.
		$voucher = get_userdata( $voucher_id );
		if ( $voucher && $voucher->user_email === $application->email ) {
			return new WP_Error(
				'agnosis_self_vouch',
				__( 'You cannot vote on your own application.', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		// Upsert: allow vote change via ON DUPLICATE KEY UPDATE.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}agnosis_application_vouches
				 (application_id, voucher_id, vote, message)
				 VALUES (%d, %d, %s, %s)
				 ON DUPLICATE KEY UPDATE vote = VALUES(vote), message = VALUES(message)",
				$application_id,
				$voucher_id,
				$vote,
				$message
			)
		);

		if ( 'yes' === $vote ) {
			$this->maybe_admit( $application_id );
		}

		return new WP_REST_Response( [
			'status'           => 'recorded',
			'vote'             => $vote,
			'vouches_received' => $this->count_positive_vouches( $application_id ),
			'vouches_required' => $this->calculate_required(),
		], 201 );
	}

	// -------------------------------------------------------------------------
	// Cron callback — daily expiry check
	// -------------------------------------------------------------------------

	/**
	 * Check all pending applications that have exceeded the voting window.
	 *
	 * Called by the 'agnosis_check_admissions' daily cron event. For each
	 * expired pending application:
	 *   - If positive votes >= required → admit (handles race where threshold
	 *     was met just before the window closed but maybe_admit was not called).
	 *   - Otherwise → reject, fire 'agnosis_application_expired' action so
	 *     AdmissionNotification can send the notification emails.
	 *
	 * Skipped entirely while agnosis_voting_disabled ("admin approval only")
	 * is on: with no community vote possible, a pending application can never
	 * reach a threshold on its own, so applying the same window here would
	 * silently auto-reject every application an admin hadn't gotten to yet.
	 * Applications wait indefinitely for Admission::admin_admit()/
	 * admin_reject() instead — see that setting's own description.
	 */
	public function check_expired_applications(): void {
		if ( (bool) get_option( 'agnosis_voting_disabled', false ) ) {
			return;
		}

		global $wpdb;

		$window = max( 1, (int) get_option( 'agnosis_admission_window_days', 7 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$expired = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}agnosis_applications
				 WHERE status = 'pending'
				   AND applied_at <= DATE_SUB(%s, INTERVAL %d DAY)",
				current_time( 'mysql' ),
				$window
			)
		);

		foreach ( $expired as $row ) {
			$app_id = (int) $row->id;

			if ( $this->count_positive_vouches( $app_id ) >= $this->calculate_required() ) {
				// Threshold reached — admit now (edge case).
				$this->maybe_admit( $app_id );
				continue;
			}

			// Reject and notify.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'agnosis_applications',
				[
					'status'      => 'rejected',
					'resolved_at' => current_time( 'mysql' ),
				],
				[ 'id' => $app_id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);

			do_action( 'agnosis_application_expired', $app_id );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// Admin override actions (bypass vouch threshold)
	// -------------------------------------------------------------------------

	/**
	 * Admit an applicant directly, bypassing the vouch threshold.
	 *
	 * For use by admins via the Settings → Network admission dashboard.
	 * Fires `agnosis_artist_admitted` so the welcome email is sent.
	 *
	 * @param int $application_id  Row ID in agnosis_applications.
	 * @return bool  True on success, false when the row is not pending or user creation fails.
	 */
	public function admin_admit( int $application_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$application = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agnosis_applications WHERE id = %d AND status IN ('pending','waitlisted')",
				$application_id
			)
		);

		if ( ! $application ) {
			return false;
		}

		// The admin is the ultimate steward: admitting is allowed even when the
		// instance is at its community cap, but it is logged so an over-cap
		// admission is a deliberate, visible act rather than a silent one.
		$cap = new CommunityCap();
		if ( $cap->is_full() ) {
			Logger::warning(
				sprintf(
					'Admin admitted application #%d over the community cap of %d (deliberate override).',
					$application_id,
					$cap->cap()
				),
				'admission'
			);
		}

		// Temporarily make the positive vouch count appear to meet the threshold so
		// maybe_admit() proceeds.  We do this by calling it directly now that we've
		// verified the row — simpler than duplicating the admission logic.
		// Insert a synthetic vouch so count_positive_vouches() passes the guard.
		// Actually, just inline the admit sequence directly (DRY tradeoff accepted
		// because maybe_admit is private and duplicating is cleaner than exposing it).

		/** @var object{email: string, display_name: string, language: string|null} $application */
		$username = $this->unique_username( $application->display_name, $application->email );
		$user_id  = wp_create_user( $username, wp_generate_password(), $application->email );

		if ( is_wp_error( $user_id ) ) {
			return false;
		}

		$update_args = [
			'ID'           => $user_id,
			'display_name' => $application->display_name,
			'first_name'   => $application->display_name,
		];

		if ( ! empty( $application->language ) ) {
			$update_args['locale'] = self::iso_to_wp_locale( (string) $application->language );
		}

		wp_update_user( $update_args );

		$user = get_userdata( $user_id );
		if ( $user ) {
			$user->add_role( 'agnosis_artist' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'agnosis_applications',
			[
				'status'      => 'admitted',
				'wp_user_id'  => $user_id,
				'resolved_at' => current_time( 'mysql' ),
			],
			[ 'id' => $application_id ],
			[ '%s', '%d', '%s' ],
			[ '%d' ]
		);

		do_action( 'agnosis_artist_admitted', $user_id, $application_id );

		return true;
	}

	/**
	 * Reject an applicant directly, bypassing the vouch window.
	 *
	 * For use by admins via the Settings → Network admission dashboard.
	 * Fires `agnosis_artist_rejected` so the rejection email is sent.
	 *
	 * @param int $application_id  Row ID in agnosis_applications.
	 * @return bool  True on success, false when the row is not pending.
	 */
	public function admin_reject( int $application_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$wpdb->prefix . 'agnosis_applications',
			[
				'status'      => 'rejected',
				'resolved_at' => current_time( 'mysql' ),
			],
			[ 'id' => $application_id, 'status' => 'pending' ],
			[ '%s', '%s' ],
			[ '%d', '%s' ]
		);

		if ( ! $updated ) {
			return false;
		}

		do_action( 'agnosis_artist_rejected', $application_id );

		return true;
	}

	/**
	 * Calculate the required number of positive votes for admission.
	 *
	 * = max( ceil( active_artists × percent / 100 ), minimum )
	 * Default: 10 % of active artists, at least 3.
	 */
	public function calculate_required(): int {
		$percent = max( 0, (int) get_option( 'agnosis_admission_percent', 10 ) );
		$minimum = max( 1, (int) get_option( 'agnosis_admission_minimum', 3 ) );
		$active  = $this->count_active_artists();

		return (int) max( (int) ceil( $active * $percent / 100 ), $minimum );
	}

	private function count_active_artists(): int {
		$query = new \WP_User_Query( [
			'role'        => 'agnosis_artist',
			'count_total' => true,
			'number'      => 0,
		] );
		return (int) $query->get_total();
	}

	public function count_positive_vouches( int $application_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_application_vouches
				 WHERE application_id = %d AND vote = 'yes' AND revoked_at IS NULL",
				$application_id
			)
		);
	}

	private function maybe_admit( int $application_id ): void {
		global $wpdb;

		if ( $this->count_positive_vouches( $application_id ) < $this->calculate_required() ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$application = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agnosis_applications WHERE id = %d AND status = 'pending'",
				$application_id
			)
		);

		if ( ! $application ) {
			return; // Already admitted or not pending.
		}

		// Community size cap: re-check at admission time (the count can change
		// between application and threshold). If full, park on the waitlist instead
		// of admitting — it re-enters this flow when a slot opens (advance_waitlist).
		if ( ( new CommunityCap() )->is_full() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'agnosis_applications',
				[ 'status' => 'waitlisted' ],
				[ 'id' => $application_id, 'status' => 'pending' ],
				[ '%s' ],
				[ '%d', '%s' ]
			);
			do_action( 'agnosis_artist_waitlisted', $application_id, $application->email, $application->display_name );
			return;
		}

		/** @var object{email: string, display_name: string, language: string|null} $application */

		$username = $this->unique_username( $application->display_name, $application->email );
		$user_id  = wp_create_user( $username, wp_generate_password(), $application->email );

		if ( is_wp_error( $user_id ) ) {
			return;
		}

		$update_args = [
			'ID'           => $user_id,
			'display_name' => $application->display_name,
			'first_name'   => $application->display_name,
		];

		// Map the applicant's ISO 639-1 language code to a WP locale and persist it
		// so notification emails can be switched to the artist's language on send.
		if ( ! empty( $application->language ) ) {
			$update_args['locale'] = self::iso_to_wp_locale( $application->language );
		}

		wp_update_user( $update_args );

		$user = get_userdata( $user_id );
		if ( $user ) {
			$user->add_role( 'agnosis_artist' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'agnosis_applications',
			[
				'status'      => 'admitted',
				'wp_user_id'  => $user_id,
				'resolved_at' => current_time( 'mysql' ),
			],
			[ 'id' => $application_id ],
			[ '%s', '%d', '%s' ],
			[ '%d' ]
		);

		do_action( 'agnosis_artist_admitted', $user_id, $application_id );
	}

	/**
	 * Re-evaluate an application for admission after a waitlist slot opened.
	 *
	 * Hooked on `agnosis_waitlist_advanced` (fired by CommunityCap::advance_waitlist
	 * when a member leaves). The row was just moved waitlisted → pending; if it
	 * already meets the vouch threshold and there is now capacity it is admitted at
	 * once, otherwise it stays pending in the normal vouching window.
	 *
	 * @param int $application_id Row ID in agnosis_applications.
	 * @return void
	 */
	public function reconsider( int $application_id ): void {
		$this->maybe_admit( $application_id );
	}

	/**
	 * Revoke a vouch on a pending application.
	 *
	 * Sets revoked_at instead of deleting — the row is preserved for audit.
	 * Returns false when the vouch doesn't exist or was already revoked.
	 *
	 * @param int $voucher_id     WP user ID of the vouching artist.
	 * @param int $application_id Row ID in agnosis_applications.
	 */
	public function revoke_vouch( int $voucher_id, int $application_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}agnosis_application_vouches
				 SET revoked_at = %s
				 WHERE voucher_id = %d AND application_id = %d AND revoked_at IS NULL",
				current_time( 'mysql' ),
				$voucher_id,
				$application_id
			)
		);
		return (bool) $rows;
	}

	public function is_artist( int $user_id ): bool {
		return self::is_admitted_artist( $user_id );
	}

	/**
	 * Check whether a WP user is an admitted Agnosis artist (or an admin).
	 *
	 * Public static so it can be used as a shared choke point across the intake
	 * paths (Webhook, Inbox, PostCreator) without coupling those classes to
	 * the full Admission object.
	 *
	 * @param int|null $user_id WordPress user ID, or null for unauthenticated.
	 */
	public static function is_admitted_artist( int|null $user_id ): bool {
		if ( ! $user_id ) {
			return false;
		}
		// user_can() resolves the primitive capability from wp_capabilities meta
		// directly — it does not require the role to be present in the global
		// WP_Roles registry. This means the check works correctly in test
		// environments where register_activation_hook() hasn't run (and therefore
		// add_role('agnosis_artist') was never called globally), while remaining
		// identical in production where the role IS registered.
		return user_can( $user_id, 'agnosis_artist' )
			|| user_can( $user_id, 'manage_options' );
	}

	/**
	 * Map an ISO 639-1 language code to the closest WP locale string.
	 *
	 * WP locales use IETF BCP 47 style tags (e.g. 'es_ES', 'zh_CN').
	 * ISO codes submitted through the join form are converted here before being
	 * stored in user meta so WP can find matching translation files.
	 *
	 * Unmapped codes are returned as-is — WP will gracefully fall back to the
	 * site language when no translation files are found for that locale.
	 */
	public static function iso_to_wp_locale( string $iso ): string {
		/** @var array<string, string> */
		$map = [
			'en'    => 'en_US',
			'es'    => 'es_ES',
			'pt'    => 'pt_PT',
			'fr'    => 'fr_FR',
			'it'    => 'it_IT',
			'de'    => 'de_DE',
			'nl'    => 'nl_NL',
			'ca'    => 'ca',
			'sv'    => 'sv_SE',
			'da'    => 'da_DK',
			'nb'    => 'nb_NO',
			'fi'    => 'fi',
			'pl'    => 'pl_PL',
			'cs'    => 'cs_CZ',
			'hu'    => 'hu_HU',
			'ro'    => 'ro_RO',
			'el'    => 'el',
			'uk'    => 'uk',
			'ru'    => 'ru_RU',
			'ar'    => 'ar',
			'tr'    => 'tr_TR',
			'hi'    => 'hi_IN',
			'id'    => 'id_ID',
			'vi'    => 'vi',
			'th'    => 'th',
			'zh'    => 'zh_CN',
			'zh-tw' => 'zh_TW',
			'ja'    => 'ja',
			'ko'    => 'ko_KR',
		];

		return $map[ $iso ] ?? $iso;
	}

	/**
	 * Generate a unique WP username from the applicant's display name.
	 */
	private function unique_username( string $display_name, string $email ): string {
		$base = sanitize_user( str_replace( ' ', '', strtolower( $display_name ) ), true );
		if ( ! $base ) {
			$local = strstr( $email, '@', true );
			$base  = sanitize_user( false !== $local ? $local : $email, true );
		}
		if ( ! $base ) {
			$base = 'artist';
		}

		$username = $base;
		$i        = 1;
		while ( username_exists( $username ) ) {
			$username = $base . $i;
			++$i;
		}

		return $username;
	}
}
