<?php
/**
 * Integration tests — ApplicationBiography (auto-created first biography draft).
 *
 * Covers on_artist_admitted():
 *   - Creates a agnosis_biography draft from bio + portfolio_url
 *   - The application's "why do you want to join?" statement is NEVER included
 *     in the biography content or the _agnosis_artist_prompt merge-context meta
 *     (2026-07-08 correction) — it's addressed to the community/admin
 *     reviewing admission, not biographical/artistic information about the
 *     artist, and doesn't belong on a public profile by nature
 *   - Trusted-platform portfolio URL becomes a wp:embed block
 *   - Non-trusted portfolio URL is rejected by default (AI review is off)
 *   - Non-trusted portfolio URL embeds/rejects per the AI's verdict once AI review is enabled
 *   - Works with only a portfolio_url and no bio text
 *   - Skips entirely when bio and portfolio_url are both empty (or portfolio is
 *     rejected) — even if a statement alone was provided, since it never
 *     contributes content
 *   - Skips when the application row does not exist
 *   - Skips when $user_id does not resolve to a real WP user
 *   - Skips (no duplicate) when a biography post already exists for the artist
 *   - _agnosis_artist_prompt is set to the raw bio text only (biography-merge compat)
 *   - _agnosis_review_token / _agnosis_review_expiry are set (review pipeline compat)
 *   - Fires 'agnosis_post_drafted', triggering the real review email
 *
 * Portfolio URL trust/AI-review mechanics themselves (host matching, fetch
 * failure, inconclusive AI response, category configuration) are covered in
 * depth by Publishing\EmbedPolicyTest — this file only needs to confirm
 * ApplicationBiography actually calls through to that policy correctly.
 *
 * Plus one end-to-end test exercising the real agnosis_artist_admitted wiring
 * via Admission::apply()/vouch() through the REST API (mirrors
 * AdmissionIntegrationTest), to confirm Plugin.php actually registers this
 * listener — not just that the class works correctly in isolation.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\AI\Pipeline;
use Agnosis\Artist\ApplicationBiography;
use Agnosis\Publishing\EmbedPolicy;

class ApplicationBiographyTest extends \WP_UnitTestCase {

	private ApplicationBiography $listener;

	/** All wp_mail() calls captured during a test (keys: to, subject, message, headers). */
	private array $sent_mails = [];

	/** The pre_wp_mail filter closure registered for the current test. */
	private ?\Closure $mail_filter = null;

	protected function setUp(): void {
		parent::setUp();
		$this->listener = new ApplicationBiography();

		// Same guard AdmissionIntegrationTest uses: pins a known, stable set of
		// "active" languages so the one end-to-end test below (which submits a
		// real application through Admission::apply()) doesn't depend on
		// whatever Lingua Forge test state another test in the same process
		// left behind. Reverted automatically by WP_UnitTestCase's hook-snapshot
		// teardown, same as everywhere else.
		add_filter( 'agnosis_translation_languages', [ $this, 'filter_test_language_names' ] );
	}

	protected function tearDown(): void {
		$this->remove_mail_capture();
		$this->remove_http_mock();
		parent::tearDown();
	}

	/** The pre_http_request filter closure registered for the current test, if any. */
	private ?\Closure $http_filter = null;

	/** Short-circuit wp_safe_remote_get() with a canned successful HTML response — no real network access. */
	private function mock_http_success( string $html = '<html><head><title>Some Page</title></head><body>Body text.</body></html>' ): void {
		$this->http_filter = function () use ( $html ) {
			return [
				'headers'  => [],
				'body'     => $html,
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'cookies'  => [],
				'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	private function remove_http_mock(): void {
		if ( $this->http_filter ) {
			remove_filter( 'pre_http_request', $this->http_filter, 10 );
			$this->http_filter = null;
		}
	}

	/** Build a listener whose portfolio-URL AI review always returns a fixed verdict, with the HTTP fetch mocked out. */
	private function listener_with_ai_verdict( ?bool $verdict ): ApplicationBiography {
		$this->mock_http_success();

		$pipeline = new class( $verdict ) extends Pipeline {
			private ?bool $fixed_verdict;
			public function __construct( ?bool $verdict ) {
				$this->fixed_verdict = $verdict;
			}
			public function classify_link( string $title, string $description, string $snippet, array $disallowed_categories ): ?bool {
				return $this->fixed_verdict;
			}
		};

		return new ApplicationBiography( new EmbedPolicy( $pipeline ) );
	}

	/**
	 * @param array<string, string> $languages
	 * @return array<string, string>
	 */
	public function filter_test_language_names( array $languages ): array {
		return array_replace( $languages, [ 'en' => 'English' ] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function start_mail_capture(): void {
		$this->sent_mails   = [];
		$this->mail_filter  = function ( $pre, array $atts ): bool {
			$this->sent_mails[] = $atts;
			return true; // Short-circuit — do not actually send.
		};
		add_filter( 'pre_wp_mail', $this->mail_filter, 10, 2 );
	}

	private function remove_mail_capture(): void {
		if ( $this->mail_filter ) {
			remove_filter( 'pre_wp_mail', $this->mail_filter, 10 );
			$this->mail_filter = null;
		}
	}

	/** @return array<array<string,mixed>> */
	private function mails_to( string $email ): array {
		return array_values( array_filter(
			$this->sent_mails,
			static fn( array $m ): bool => ( $m['to'] ?? '' ) === $email
		) );
	}

	/** Insert a row into agnosis_applications and return the application ID. */
	private function insert_application(
		string $email = 'app@example.com',
		string $display_name = 'Test Applicant',
		string $bio = 'I paint seascapes.',
		string $portfolio = 'https://myportfolio.example/artist',
		string $statement = 'I want to share my work with the world.'
	): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_applications',
			[
				'email'         => $email,
				'display_name'  => $display_name,
				'bio'           => $bio,
				'portfolio_url' => $portfolio,
				'statement'     => $statement,
				'status'        => 'admitted',
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	private function create_user( string $email ): int {
		return self::factory()->user->create( [ 'user_email' => $email ] );
	}

	/** Fetch the first agnosis_biography post authored by $user_id, or null. */
	private function get_biography_post( int $user_id ): ?\WP_Post {
		$posts = get_posts( [
			'post_type'      => 'agnosis_biography',
			'author'         => $user_id,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		] );
		return $posts[0] ?? null;
	}

	// -------------------------------------------------------------------------
	// Happy path — bio + portfolio_url
	// -------------------------------------------------------------------------

	public function test_creates_biography_draft_from_application_data(): void {
		$user_id        = $this->create_user( 'happy@example.com' );
		$application_id = $this->insert_application( 'happy@example.com', 'Happy Artist' );

		$this->listener->on_artist_admitted( $user_id, $application_id );

		$post = $this->get_biography_post( $user_id );
		$this->assertNotNull( $post, 'A biography post must be created.' );
		$this->assertSame( 'agnosis_biography', $post->post_type );
		$this->assertSame(
			'draft',
			$post->post_status,
			'The auto-created biography must land as a draft pending review, same as every other Agnosis post.'
		);
	}

	public function test_biography_content_includes_bio_but_not_statement(): void {
		$user_id        = $this->create_user( 'content@example.com' );
		$application_id = $this->insert_application(
			'content@example.com',
			'Content Artist',
			'My bio text here.',
			'https://myportfolio.example/content',
			'My statement text here.'
		);

		$this->listener->on_artist_admitted( $user_id, $application_id );

		$post = $this->get_biography_post( $user_id );
		$this->assertNotNull( $post );
		$this->assertStringContainsString( 'My bio text here.', $post->post_content );
		$this->assertStringNotContainsString(
			'My statement text here.',
			$post->post_content,
			'The "why do you want to join?" statement is not biographical content and must never appear in the auto-created biography.'
		);
	}

	public function test_biography_title_defaults_to_about_display_name(): void {
		$user_id        = $this->create_user( 'title@example.com' );
		$application_id = $this->insert_application( 'title@example.com', 'Title Artist' );

		$this->listener->on_artist_admitted( $user_id, $application_id );

		$post = $this->get_biography_post( $user_id );
		$this->assertNotNull( $post );
		$this->assertSame( 'About Title Artist', $post->post_title );
	}

	public function test_biography_post_author_is_the_admitted_artist(): void {
		$user_id        = $this->create_user( 'author@example.com' );
		$application_id = $this->insert_application( 'author@example.com', 'Author Artist' );

		$this->listener->on_artist_admitted( $user_id, $application_id );

		$post = $this->get_biography_post( $user_id );
		$this->assertNotNull( $post );
		$this->assertSame( $user_id, (int) $post->post_author );
	}

	// -------------------------------------------------------------------------
	// Portfolio URL — gated by Publishing\EmbedPolicy, same as any other link
	// -------------------------------------------------------------------------

	public function test_trusted_platform_portfolio_url_becomes_wp_embed_block(): void {
		$user_id        = $this->create_user( 'embed@example.com' );
		$application_id = $this->insert_application(
			'embed@example.com',
			'Embed Artist',
			'Bio text.',
			'https://vimeo.com/123456789',
			'Statement text.'
		);

		$this->listener->on_artist_admitted( $user_id, $application_id );

		$post = $this->get_biography_post( $user_id );
		$this->assertNotNull( $post );
		$this->assertStringContainsString( '<!-- wp:embed', $post->post_content );
		$this->assertStringContainsString( 'https://vimeo.com/123456789', $post->post_content );
	}

	public function test_non_trusted_portfolio_url_is_not_embedded_by_default(): void {
		// AI review is off by default (agnosis_embed_ai_vetting_enabled) — a
		// portfolio link to a host that isn't a recognised platform is simply
		// not embedded, same as PostCreator's email-submission path.
		$user_id        = $this->create_user( 'notrust@example.com' );
		$application_id = $this->insert_application(
			'notrust@example.com',
			'No Trust Artist',
			'Bio text.',
			'https://a-completely-random-personal-site.example/gallery',
			'Statement text.'
		);

		$this->listener->on_artist_admitted( $user_id, $application_id );

		$post = $this->get_biography_post( $user_id );
		$this->assertNotNull( $post, 'The biography is still created from bio alone even when the portfolio link is rejected.' );
		$this->assertStringNotContainsString( 'a-completely-random-personal-site.example', $post->post_content );
		$this->assertStringNotContainsString( '<!-- wp:embed', $post->post_content );
	}

	public function test_non_trusted_portfolio_url_is_embedded_when_ai_approves(): void {
		update_option( 'agnosis_embed_ai_vetting_enabled', 1 );
		update_option( 'agnosis_embed_block_adult', 1 );

		$listener       = $this->listener_with_ai_verdict( true );
		$user_id        = $this->create_user( 'aiallow@example.com' );
		$application_id = $this->insert_application(
			'aiallow@example.com',
			'AI Allow Artist',
			'Bio text.',
			'https://a-completely-random-personal-site.example/gallery',
			'Statement text.'
		);

		$listener->on_artist_admitted( $user_id, $application_id );

		$post = $this->get_biography_post( $user_id );
		$this->assertNotNull( $post );
		$this->assertStringContainsString( '<!-- wp:embed', $post->post_content );
		$this->assertStringContainsString( 'a-completely-random-personal-site.example', $post->post_content );
	}

	public function test_non_trusted_portfolio_url_is_rejected_when_ai_blocks(): void {
		update_option( 'agnosis_embed_ai_vetting_enabled', 1 );
		update_option( 'agnosis_embed_block_adult', 1 );

		$listener       = $this->listener_with_ai_verdict( false );
		$user_id        = $this->create_user( 'aiblock@example.com' );
		$application_id = $this->insert_application(
			'aiblock@example.com',
			'AI Block Artist',
			'Bio text.',
			'https://a-completely-random-personal-site.example/gallery',
			'Statement text.'
		);

		$listener->on_artist_admitted( $user_id, $application_id );

		$post = $this->get_biography_post( $user_id );
		$this->assertNotNull( $post, 'The biography is still created from bio alone even when the AI rejects the portfolio link.' );
		$this->assertStringNotContainsString( 'a-completely-random-personal-site.example', $post->post_content );
	}

	public function test_biography_created_with_only_portfolio_url_and_no_text(): void {
		$user_id        = $this->create_user( 'onlyurl@example.com' );
		$application_id = $this->insert_application(
			'onlyurl@example.com',
			'Only URL Artist',
			'',
			'https://youtu.be/onlyurl123',
			''
		);

		$this->listener->on_artist_admitted( $user_id, $application_id );

		$post = $this->get_biography_post( $user_id );
		$this->assertNotNull( $post, 'A biography must still be created from a portfolio URL alone.' );
		$this->assertStringContainsString( 'https://youtu.be/onlyurl123', $post->post_content );
	}

	public function test_no_biography_created_from_only_a_rejected_portfolio_url(): void {
		// No bio, no statement, and the one portfolio URL isn't a trusted host —
		// AI review is off by default, so there is nothing left to show at all.
		$user_id        = $this->create_user( 'nothingleft@example.com' );
		$application_id = $this->insert_application(
			'nothingleft@example.com',
			'Nothing Left Artist',
			'',
			'https://a-completely-random-personal-site.example/gallery',
			''
		);

		$this->listener->on_artist_admitted( $user_id, $application_id );

		$this->assertNull( $this->get_biography_post( $user_id ) );
	}

	// -------------------------------------------------------------------------
	// Skip conditions
	// -------------------------------------------------------------------------

	public function test_skips_when_application_has_no_bio_statement_or_portfolio(): void {
		$user_id        = $this->create_user( 'empty@example.com' );
		$application_id = $this->insert_application( 'empty@example.com', 'Empty Artist', '', '', '' );

		$this->listener->on_artist_admitted( $user_id, $application_id );

		$this->assertNull(
			$this->get_biography_post( $user_id ),
			'No biography should be created when the application has nothing to show.'
		);
	}

	/**
	 * Regression coverage for the 2026-07-08 correction: before it, a
	 * statement-only application (no bio, no portfolio) still produced a
	 * biography draft — its entire content was the "why do you want to join?"
	 * text. Since that text no longer contributes to the biography at all,
	 * this must now skip exactly like an entirely-empty application.
	 */
	public function test_skips_when_only_statement_is_provided(): void {
		$user_id        = $this->create_user( 'statementonly@example.com' );
		$application_id = $this->insert_application(
			'statementonly@example.com',
			'Statement Only Artist',
			'', // no bio
			'', // no portfolio
			'I really want to join because I love this community.'
		);

		$this->listener->on_artist_admitted( $user_id, $application_id );

		$this->assertNull(
			$this->get_biography_post( $user_id ),
			'A statement alone must not produce a biography draft — it has no biographical content to show.'
		);
	}

	public function test_skips_when_application_row_does_not_exist(): void {
		$user_id = $this->create_user( 'noapp@example.com' );

		$this->listener->on_artist_admitted( $user_id, 999999 ); // No such application row.

		$this->assertNull( $this->get_biography_post( $user_id ) );
	}

	public function test_skips_when_user_id_does_not_resolve_to_a_real_user(): void {
		$application_id = $this->insert_application( 'ghost@example.com', 'Ghost Artist' );

		$this->listener->on_artist_admitted( 999999, $application_id ); // No such WP user.

		$posts = get_posts( [
			'post_type'      => 'agnosis_biography',
			'author'         => 999999,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );
		$this->assertEmpty( $posts, 'No orphaned biography post should be created for a non-existent user ID.' );
	}

	public function test_does_not_create_duplicate_when_biography_already_exists(): void {
		$user_id = $this->create_user( 'dup@example.com' );

		$existing_id = wp_insert_post( [
			'post_type'    => 'agnosis_biography',
			'post_status'  => 'publish',
			'post_title'   => 'Already here',
			'post_content' => 'Pre-existing content.',
			'post_author'  => $user_id,
		] );

		$application_id = $this->insert_application( 'dup@example.com', 'Dup Artist' );

		$this->listener->on_artist_admitted( $user_id, $application_id );

		$posts = get_posts( [
			'post_type'      => 'agnosis_biography',
			'author'         => $user_id,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );

		$this->assertCount( 1, $posts, 'A second biography post must not be created when one already exists.' );
		$this->assertSame( $existing_id, (int) $posts[0] );

		$unchanged = get_post( $existing_id );
		$this->assertSame( 'Pre-existing content.', $unchanged->post_content, 'The existing biography must be left untouched.' );
	}

	// -------------------------------------------------------------------------
	// Review-pipeline compatibility (_agnosis_review_token, _agnosis_artist_prompt)
	// -------------------------------------------------------------------------

	public function test_sets_review_token_and_expiry_meta(): void {
		$user_id        = $this->create_user( 'token@example.com' );
		$application_id = $this->insert_application( 'token@example.com', 'Token Artist' );

		$this->listener->on_artist_admitted( $user_id, $application_id );

		$post = $this->get_biography_post( $user_id );
		$this->assertNotNull( $post );

		$token  = get_post_meta( $post->ID, '_agnosis_review_token', true );
		$expiry = (int) get_post_meta( $post->ID, '_agnosis_review_expiry', true );

		$this->assertNotEmpty(
			$token,
			'A review token must be set so the artist can approve/discard via the standard review email.'
		);
		$this->assertSame(
			64,
			strlen( $token ),
			'Review token must be a 64-character hex string (32 random bytes), matching PostCreator::generate_token().'
		);
		$this->assertGreaterThan( time(), $expiry, 'Review expiry must be in the future.' );
		$this->assertLessThanOrEqual(
			time() + ( 7 * DAY_IN_SECONDS ) + 5,
			$expiry,
			'Review expiry must be about 7 days out, matching every other Agnosis post.'
		);
	}

	/**
	 * Settings → Behaviour → "Review link expiry (days)"
	 * (agnosis_review_token_expiry_days) must actually change how long a
	 * biography's review link lasts, not just artwork/event — this listener
	 * writes its own _agnosis_review_expiry directly rather than going through
	 * PostCreator::create_post(), so it needs its own coverage of the setting.
	 */
	public function test_review_expiry_honours_configured_days_option(): void {
		update_option( 'agnosis_review_token_expiry_days', 3 );

		$user_id        = $this->create_user( 'shortexpiry@example.com' );
		$application_id = $this->insert_application( 'shortexpiry@example.com', 'Short Expiry Artist' );

		$this->listener->on_artist_admitted( $user_id, $application_id );

		$post   = $this->get_biography_post( $user_id );
		$this->assertNotNull( $post );
		$expiry = (int) get_post_meta( $post->ID, '_agnosis_review_expiry', true );

		$this->assertGreaterThan( time() + ( 2 * DAY_IN_SECONDS ), $expiry, 'Expiry must reflect the configured 3 days, not the default 7.' );
		$this->assertLessThanOrEqual( time() + ( 3 * DAY_IN_SECONDS ) + 5, $expiry );
	}

	public function test_sets_artist_prompt_meta_for_future_bio_merge(): void {
		$user_id        = $this->create_user( 'prompt@example.com' );
		$application_id = $this->insert_application(
			'prompt@example.com',
			'Prompt Artist',
			'Bio prompt text.',
			'https://myportfolio.example/prompt',
			'Statement prompt text.'
		);

		$this->listener->on_artist_admitted( $user_id, $application_id );

		$post = $this->get_biography_post( $user_id );
		$this->assertNotNull( $post );

		$prompt = get_post_meta( $post->ID, '_agnosis_artist_prompt', true );
		$this->assertSame(
			'Bio prompt text.',
			$prompt,
			'_agnosis_artist_prompt must hold the raw bio text only so a later bio@ update merges correctly (PostCreator::handle()).'
		);
	}

	/**
	 * Regression coverage for the 2026-07-08 correction: the "why do you want
	 * to join?" statement must never leak into _agnosis_artist_prompt either,
	 * even when it's present on the application — this meta seeds future AI
	 * merges of the artist's biography, and admission motivation isn't
	 * biographical material any more than it belongs in the post content.
	 */
	public function test_artist_prompt_meta_never_includes_statement(): void {
		$user_id        = $this->create_user( 'promptbio@example.com' );
		$application_id = $this->insert_application(
			'promptbio@example.com',
			'Prompt Bio Artist',
			'Only bio here.',
			'https://myportfolio.example/promptbio',
			'This statement must not leak into the prompt.'
		);

		$this->listener->on_artist_admitted( $user_id, $application_id );

		$post   = $this->get_biography_post( $user_id );
		$prompt = get_post_meta( $post->ID, '_agnosis_artist_prompt', true );

		$this->assertSame( 'Only bio here.', $prompt );
	}

	// -------------------------------------------------------------------------
	// Review email — real 'agnosis_post_drafted' wiring
	// -------------------------------------------------------------------------

	public function test_fires_post_drafted_and_sends_review_email(): void {
		$user_id        = $this->create_user( 'review@example.com' );
		$application_id = $this->insert_application( 'review@example.com', 'Review Artist' );

		$this->start_mail_capture();
		$this->listener->on_artist_admitted( $user_id, $application_id );
		$this->remove_mail_capture();

		$this->assertNotEmpty(
			$this->mails_to( 'review@example.com' ),
			'agnosis_post_drafted must fire and trigger the standard review email via Publishing\\Notification.'
		);
	}

	public function test_no_review_email_sent_when_nothing_to_show(): void {
		$user_id        = $this->create_user( 'noreview@example.com' );
		$application_id = $this->insert_application( 'noreview@example.com', 'No Review Artist', '', '', '' );

		$this->start_mail_capture();
		$this->listener->on_artist_admitted( $user_id, $application_id );
		$this->remove_mail_capture();

		$this->assertEmpty(
			$this->mails_to( 'noreview@example.com' ),
			'No review email should be sent when no biography draft was created.'
		);
	}

	// -------------------------------------------------------------------------
	// End-to-end — real agnosis_artist_admitted wiring via the admission flow
	// -------------------------------------------------------------------------

	public function test_end_to_end_admission_creates_biography_draft(): void {
		update_option( 'agnosis_admission_percent', 0 );
		update_option( 'agnosis_admission_minimum', 1 );

		$voter = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		get_userdata( $voter )->add_role( 'agnosis_artist' );

		wp_set_current_user( 0 );
		$apply_request = new \WP_REST_Request( 'POST', '/agnosis/v1/admission/apply' );
		$apply_request->set_param( 'email', 'e2e@example.com' );
		$apply_request->set_param( 'display_name', 'End To End Artist' );
		$apply_request->set_param( 'bio', 'End to end bio.' );
		$apply_request->set_param( 'statement', 'End to end statement.' );
		// A trusted-platform URL — deliberately not testing AI-review mechanics
		// here (see EmbedPolicyTest for that); this test's job is only to prove
		// Plugin.php really wires ApplicationBiography up end-to-end.
		$apply_request->set_param( 'portfolio_url', 'https://vimeo.com/e2e123' );
		$apply_request->set_param( 'language', 'en' );
		$apply_response = rest_do_request( $apply_request );
		$application_id = (int) ( $apply_response->get_data()['application_id'] ?? 0 );
		$this->assertGreaterThan( 0, $application_id, 'Application must be created before it can be vouched.' );

		// Double opt-in (security audit §3a/§4a): apply() alone only parks the
		// row as 'unverified' now — confirm it via the real
		// Admission::confirm_application() before it can be vouched, mirroring
		// the applicant clicking the link in the confirmation email.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$confirm_token = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT confirm_token FROM {$wpdb->prefix}agnosis_applications WHERE id = %d",
				$application_id
			)
		);
		( new \Agnosis\Artist\Admission() )->confirm_application( (string) $confirm_token );

		wp_set_current_user( $voter );
		rest_do_request( new \WP_REST_Request( 'POST', "/agnosis/v1/admission/vouch/{$application_id}" ) );

		$new_user = get_user_by( 'email', 'e2e@example.com' );
		$this->assertNotFalse( $new_user, 'Artist must be admitted after reaching the vouch threshold.' );

		$posts = get_posts( [
			'post_type'      => 'agnosis_biography',
			'author'         => $new_user->ID,
			'post_status'    => 'any',
			'posts_per_page' => -1,
		] );

		$this->assertCount(
			1,
			$posts,
			'Plugin.php must wire ApplicationBiography to the real agnosis_artist_admitted action.'
		);
		$this->assertStringContainsString( 'End to end bio.', $posts[0]->post_content );
		$this->assertStringContainsString( 'https://vimeo.com/e2e123', $posts[0]->post_content );
	}
}
