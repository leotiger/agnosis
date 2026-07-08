<?php
/**
 * Auto-creates a first agnosis_biography draft from the applicant's own
 * application data the moment they're admitted to the community.
 *
 * @package Agnosis\Artist
 */

declare(strict_types=1);

namespace Agnosis\Artist;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Agnosis\Core\Logger;
use Agnosis\Publishing\EmbedPolicy;

/**
 * An applicant already writes a short bio, an artist statement, and
 * (optionally) a portfolio URL on the application form — see
 * Admission::apply(). Today none of that ever becomes visible to a site
 * visitor unless the artist separately emails bio@ after being admitted;
 * it just sits in agnosis_applications. This class surfaces it: the moment
 * an application is admitted (either path — Admission::admin_admit() or the
 * community vouch-threshold path, Admission::maybe_admit() — both fire the
 * same 'agnosis_artist_admitted' action), it builds a first agnosis_biography
 * draft from that data, so a newly admitted artist starts with something
 * rather than a blank profile.
 *
 * The draft goes through the exact same review pipeline every other Agnosis
 * post uses: a '_agnosis_review_token'/'_agnosis_review_expiry' pair and the
 * 'agnosis_post_drafted' action, picked up by Publishing\Notification, which
 * emails the artist an Approve & Publish / Edit before publishing / Discard
 * link (Publishing\ReviewEndpoints). Nothing here bypasses that review step —
 * the artist always gets to see and approve (or edit, or discard) this
 * biography before it goes live, same as an AI-drafted artwork.
 *
 * The portfolio URL becomes a wp:embed block at the end of the post, gated by
 * the same Publishing\EmbedPolicy used for artist-submitted links in artwork/
 * biography/event emails: embedded immediately if the host is on the site's
 * trusted-platform list, otherwise only if the admin has enabled AI review
 * and the AI approves it against the site's configured disallowed
 * categories. A portfolio link is no more automatically trustworthy than any
 * other artist-submitted link — the same policy, the same settings, applies
 * uniformly.
 */
class ApplicationBiography {

	private EmbedPolicy $embed_policy;

	/**
	 * @param EmbedPolicy|null $embed_policy Injectable for tests; production
	 *                                       callers get a fully-configured one automatically.
	 */
	public function __construct( ?EmbedPolicy $embed_policy = null ) {
		$this->embed_policy = $embed_policy ?? new EmbedPolicy();
	}

	public function register_hooks(): void {
		add_action( 'agnosis_artist_admitted', [ $this, 'on_artist_admitted' ], 10, 2 );
	}

	/**
	 * @param int $user_id        WP user ID of the newly admitted artist.
	 * @param int $application_id Row ID in agnosis_applications.
	 */
	public function on_artist_admitted( int $user_id, int $application_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$application = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT bio, statement, portfolio_url, display_name FROM {$wpdb->prefix}agnosis_applications WHERE id = %d",
				$application_id
			)
		);

		if ( ! $application ) {
			return;
		}

		// Same guard AdmissionNotification::on_artist_admitted() applies before
		// sending the welcome email — without it, a stale or invalid $user_id
		// would still create a post, just orphaned under a post_author that maps
		// to no real account (wp_insert_post() does not validate this itself).
		if ( ! get_userdata( $user_id ) ) {
			return;
		}

		$bio       = trim( (string) $application->bio );
		$statement = trim( (string) $application->statement );
		$portfolio = trim( (string) $application->portfolio_url );

		if ( '' === $bio && '' === $statement && '' === $portfolio ) {
			return; // Nothing on the application to build a biography from.
		}

		// Defensive: don't create a second biography if one already exists for this
		// artist — e.g. a bio@ email arriving in the same window admission is being
		// processed, or this hook somehow firing twice. agnosis_biography is a
		// singleton-per-artist type everywhere else in the plugin; this stays
		// consistent with that rule rather than creating a duplicate.
		$existing = get_posts( [
			'post_type'      => 'agnosis_biography',
			'author'         => $user_id,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		if ( ! empty( $existing ) ) {
			Logger::info(
				sprintf( 'ApplicationBiography: artist #%d already has a biography post — skipping auto-creation.', $user_id ),
				'admission'
			);
			return;
		}

		// Only reached once we know a post might actually be created — the
		// portfolio link check may involve a network fetch and an AI call
		// (EmbedPolicy::is_allowed()), so it's deliberately not done any
		// earlier than this.
		$portfolio_approved = '' !== $portfolio && $this->embed_policy->is_allowed( $portfolio );

		if ( '' !== $portfolio && ! $portfolio_approved ) {
			Logger::info(
				sprintf( 'ApplicationBiography: portfolio URL for artist #%d was not approved for embedding — omitted.', $user_id ),
				'admission'
			);
		}

		if ( '' === $bio && '' === $statement && ! $portfolio_approved ) {
			return; // Nothing left to show once the portfolio link didn't clear policy either.
		}

		$post_content = $this->build_content( $bio, $statement, $portfolio_approved ? $portfolio : '' );

		// Raw, unmarked-up text — this is exactly what PostCreator's own biography
		// posts store in _agnosis_artist_prompt, and what its AI merge pass reads
		// back out to merge with a future bio@ update (PostCreator::handle()'s
		// biography-merge branch) — so a later email update merges with this
		// application-derived text instead of silently starting from nothing.
		$raw_prompt = implode( "\n\n", array_filter( [ $bio, $statement ] ) );

		$post_data = [
			'post_title'   => sprintf(
				/* translators: %s: artist display name */
				__( 'About %s', 'agnosis' ),
				(string) $application->display_name
			),
			'post_content' => $post_content,
			'post_status'  => 'draft',
			'post_type'    => 'agnosis_biography',
			'post_author'  => $user_id,
			'meta_input'   => [
				'_agnosis_from'          => '',
				'_agnosis_source'        => 'application',
				'_agnosis_artist_prompt' => $raw_prompt,
				'_agnosis_review_token'  => bin2hex( random_bytes( 32 ) ),
				'_agnosis_review_expiry' => time() + ( 7 * DAY_IN_SECONDS ),
				'_agnosis_queue_id'      => 0,
			],
		];

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			Logger::error(
				sprintf( 'ApplicationBiography: failed to create biography draft for artist #%d: %s', $user_id, $post_id->get_error_message() ),
				'admission'
			);
			return;
		}

		Logger::info(
			sprintf( 'ApplicationBiography: created biography draft #%d for newly admitted artist #%d from application data.', $post_id, $user_id ),
			'admission'
		);

		/**
		 * Notify review layer — same email/approve/discard pipeline every other
		 * Agnosis post goes through (Publishing\Notification::on_post_drafted()).
		 */
		do_action( 'agnosis_post_drafted', $post_id, $user_id );
	}

	/**
	 * Assemble the draft's content: the bio text, then the statement text (each
	 * as plain, unmarked-up text — mirroring exactly how PostCreator builds a
	 * biography post's content from an email submission, see
	 * PostCreator::build_post_content()), then the portfolio link as a wp:embed
	 * block at the very end.
	 *
	 * @param string $portfolio Already EmbedPolicy-approved, or '' — the caller
	 *                          (on_artist_admitted()) is responsible for that check.
	 */
	private function build_content( string $bio, string $statement, string $portfolio ): string {
		$body = '';

		if ( '' !== $bio ) {
			$body .= wp_kses_post( $bio ) . "\n\n";
		}
		if ( '' !== $statement ) {
			$body .= wp_kses_post( $statement ) . "\n\n";
		}

		if ( '' !== $portfolio ) {
			$body .= $this->build_embed_block( $portfolio ) . "\n\n";
		}

		return $body;
	}

	/**
	 * Build a minimal Gutenberg core/embed block for the (already
	 * EmbedPolicy-approved) portfolio URL. Only the URL itself needs to be
	 * correct — core/embed is a dynamic block; WordPress performs its own
	 * oEmbed lookup at render time regardless of what "type" this markup
	 * declares (mirrors PostCreator::build_embed_block()'s same comment).
	 */
	private function build_embed_block( string $url ): string {
		$attr    = wp_json_encode( [ 'url' => $url, 'type' => 'rich' ] ) ?: '{}';
		$esc_url = esc_url( $url );

		return '<!-- wp:embed ' . $attr . ' --><figure class="wp-block-embed is-type-rich"><div class="wp-block-embed__wrapper">' . "\n" . $esc_url . "\n" . '</div></figure><!-- /wp:embed -->';
	}
}
