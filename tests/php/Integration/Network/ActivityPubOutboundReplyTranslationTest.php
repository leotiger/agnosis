<?php
/**
 * Integration tests — Network\ActivityPub, WP13 (interaction-surface
 * roadmap, §8, "closing the outbound-translation gap", 2026-07-28).
 *
 * A dedicated file (not more tests bolted onto ActivityPubTest.php /
 * ActivityPubFederateReplyTest.php) since WP13 is a genuinely separate
 * concern from either: it's about which LANGUAGE VERSIONS get written and
 * read, not the moderation gateway (WP7) or the federation delivery
 * mechanics (WP6) themselves — same "own file" convention those two files'
 * own docblocks already establish.
 *
 * Covers:
 *   - §13.1/§13.6b: drain_outbound_reply_translation() writes the OUTBOUND
 *     three-version model onto an artist's own reply — source (the
 *     artist's declared language, never detected), the site-primary
 *     translation, and the original commenter's own language — reusing the
 *     exact same three meta constants WP4 already writes for the INBOUND
 *     direction.
 *   - The "two of three coincide" skip efficiencies in both directions.
 *   - §13.2/§13.3: SubmissionTranslator::detect_language() only ever runs
 *     for a federated (remote) inbound reply whose own language isn't yet
 *     known, and only when `agnosis_federate_languages` is `all`.
 *   - §13.4: reply_to_note()'s contentMap now carries every distinct
 *     language version in play, not a single mistagged entry.
 *   - §13.5: an undetectable/unsupported federated reply gets
 *     notify_artist_of_unsupported_reply_language()'s informational-only
 *     email instead of the normal translate+notify flow — no gateway link,
 *     no expiry meta.
 *   - §13.6a's collision guard: notify_artist_of_reply() must never fire
 *     for an artist-authored (user_id > 0) comment during drain.
 *
 * @package Agnosis\Tests\Integration\Network
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Network;

use Agnosis\AI\SubmissionTranslator;
use Agnosis\Network\ActivityPub;
use Agnosis\Tests\Integration\AI\Stubs\WpAiClientTestRegistry;

require_once __DIR__ . '/../AI/Stubs/WpAiClientTestRegistry.php';
require_once __DIR__ . '/../AI/Stubs/wp_ai_provider_namespace_stubs.php';

class ActivityPubOutboundReplyTranslationTest extends \WP_UnitTestCase {

	/**
	 * All wp_mail() calls captured during a test.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $sent_mails = [];

	/** The pre_wp_mail filter closure registered for the current test. */
	private ?\Closure $mail_filter = null;

	protected function setUp(): void {
		parent::setUp();
		delete_option( 'agnosis_federate_languages' );
		delete_option( 'agnosis_ai_provider' );
		WpAiClientTestRegistry::reset();
		// Every scenario here needs 'ca'/'en'/'fr'/'de'/'es' resolvable —
		// same fix ActivityPubTest::test_drain_reply_translation_queue_
		// translates_pending_and_clears_flag's own docblock explains:
		// language_names() otherwise falls back to just the site's own
		// locale, and translate_fields() no-ops on an unresolvable target.
		add_filter( 'agnosis_translation_languages', static fn( array $langs ): array => array_replace(
			$langs,
			[ 'ca' => 'Catalan', 'en' => 'English', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish' ]
		) );
	}

	protected function tearDown(): void {
		$this->remove_mail_capture();
		delete_option( 'agnosis_federate_languages' );
		delete_option( 'agnosis_ai_provider' );
		WpAiClientTestRegistry::reset();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function start_mail_capture(): void {
		$this->sent_mails  = [];
		$this->mail_filter = function ( $pre, array $atts ): bool {
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

	/**
	 * Invoke a private/protected ActivityPub method by name.
	 *
	 * @param array<int, mixed> $args
	 */
	private function invoke( string $method, array $args = [] ): mixed {
		$ref = new \ReflectionMethod( ActivityPub::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( new ActivityPub(), $args );
	}

	/** Create a WP user with the agnosis_artist role and a declared locale, return their id. */
	private function create_artist( string $locale, string $email = 'artist@example.com' ): int {
		// @phpstan-ignore-next-line -- factory()->user->create() returns int|WP_Error; a bare artist fixture with a role/email/locale never fails in practice (see feedback_phpstan_baseline_test_gotchas Rule 4 / ActivityPubBoostTest's own create_artist() — passing 'role' and 'locale' directly to wp_insert_user() also sidesteps the add_role()-on-WP_User|false chain entirely, not just the return-type cast).
		return self::factory()->user->create( [ 'role' => 'agnosis_artist', 'user_email' => $email, 'locale' => $locale ] );
	}

	private function create_artwork( int $artist_id ): int {
		// @phpstan-ignore-next-line -- int|WP_Error union only exists because of wp_insert_post()'s own return type; fixed, valid fixture args never fail.
		return (int) self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $artist_id,
		] );
	}

	/** An approved LOCAL (site visitor) parent reply, optionally already tagged with its own known source language. */
	private function insert_local_visitor_parent( int $post_id, string $source_lang ): int {
		$comment_id = (int) wp_insert_comment( [
			'comment_post_ID'  => $post_id,
			'comment_content'  => 'A site visitor says hi.',
			'comment_author'   => 'Visitor',
			'comment_approved' => 1,
			'comment_type'     => ActivityPub::LOCAL_REPLY_COMMENT_TYPE,
		] );
		if ( '' !== $source_lang ) {
			update_comment_meta( $comment_id, '_agnosis_reply_source_lang', $source_lang );
		}
		return $comment_id;
	}

	/** An approved federated-INBOUND parent reply, optionally with its own language already detected/known. */
	private function insert_federated_parent( int $post_id, string $source_lang = '' ): int {
		$comment_id = (int) wp_insert_comment( [
			'comment_post_ID'  => $post_id,
			'comment_content'  => 'A remote fan says hello.',
			'comment_author'   => 'Remote Fan',
			'comment_approved' => 1,
			'comment_type'     => ActivityPub::REPLY_COMMENT_TYPE,
		] );
		update_comment_meta( $comment_id, '_agnosis_reply_activity_id', 'https://mastodon.example/statuses/12345' );
		update_comment_meta( $comment_id, '_agnosis_reply_actor', 'https://mastodon.example/users/remotefan' );
		if ( '' !== $source_lang ) {
			update_comment_meta( $comment_id, '_agnosis_reply_source_lang', $source_lang );
		}
		return $comment_id;
	}

	/** The most recently inserted child comment of $parent_comment_id. */
	private function latest_child_comment( int $post_id, int $parent_comment_id ): ?\WP_Comment {
		$comments = get_comments( [
			'post_id' => $post_id,
			'parent'  => $parent_comment_id,
			'status'  => 'any',
			'orderby' => 'comment_ID',
			'order'   => 'DESC',
			'number'  => 1,
		] );
		return ( is_array( $comments ) && ! empty( $comments ) && $comments[0] instanceof \WP_Comment ) ? $comments[0] : null;
	}

	// -------------------------------------------------------------------------
	// §13.1/§13.6b — the outbound three-version write
	// -------------------------------------------------------------------------

	public function test_outbound_reply_to_local_visitor_writes_three_version_translations(): void {
		$artist_id = $this->create_artist( 'ca_ES' ); // artist_lang => 'ca'.
		$post_id   = $this->create_artwork( $artist_id );
		$parent_id = $this->insert_local_visitor_parent( $post_id, 'fr' ); // distinct from both 'ca' and the site's primary ('en').

		update_option( 'agnosis_ai_provider', 'wp_ai' );
		WpAiClientTestRegistry::$response = (string) wp_json_encode( [ 'content' => 'Contingut traduït' ] );

		$this->invoke( 'store_artist_gateway_reply', [ $post_id, $parent_id, 'Text en català', false ] );
		$this->invoke( 'drain_reply_translation_queue' );

		$reply = $this->latest_child_comment( $post_id, $parent_id );
		$this->assertNotNull( $reply );
		$comment_id = (int) $reply->comment_ID;

		$this->assertSame( 'ca', get_comment_meta( $comment_id, '_agnosis_reply_source_lang', true ), 'Source must be the artist\'s own declared language, never detected.' );
		$this->assertSame( 'Contingut traduït', get_comment_meta( $comment_id, '_agnosis_reply_translated_primary', true ), 'Primary-language (en) translation must be written since it differs from the artist\'s own language.' );
		$this->assertSame( 'Contingut traduït', get_comment_meta( $comment_id, '_agnosis_reply_translated_content', true ), 'The parent commenter\'s own language (fr) differs from both artist and primary — must also be translated.' );
		$this->assertSame( 'Text en català', get_comment( $comment_id )->comment_content, 'The untouched original must survive in comment_content — never discard the source.' );
		$this->assertSame( '', get_comment_meta( $comment_id, '_agnosis_reply_pending_translation', true ) );
	}

	public function test_outbound_reply_skips_commenter_translation_when_it_equals_primary(): void {
		$primary_lang = SubmissionTranslator::resolve_target_language();
		$artist_id    = $this->create_artist( 'ca_ES' ); // 'ca' — distinct from primary.
		$post_id      = $this->create_artwork( $artist_id );
		// Parent's own language left unset -> resolve_original_commenter_lang() falls back to primary.
		$parent_id = $this->insert_local_visitor_parent( $post_id, '' );

		update_option( 'agnosis_ai_provider', 'wp_ai' );
		WpAiClientTestRegistry::$response = (string) wp_json_encode( [ 'content' => 'Contingut traduït' ] );

		$this->invoke( 'store_artist_gateway_reply', [ $post_id, $parent_id, 'Text en català', false ] );
		$this->invoke( 'drain_reply_translation_queue' );

		$reply      = $this->latest_child_comment( $post_id, $parent_id );
		$comment_id = (int) $reply->comment_ID;

		$this->assertSame( 'Contingut traduït', get_comment_meta( $comment_id, '_agnosis_reply_translated_primary', true ) );
		$this->assertSame(
			'',
			get_comment_meta( $comment_id, '_agnosis_reply_translated_content', true ),
			'Commenter language resolves to the same value as primary — must not be translated a second time.'
		);
		$this->assertCount( 1, WpAiClientTestRegistry::$prompts, 'Exactly one translation call should have been made — no wasted second call for the coinciding language.' );
		$this->assertSame( $primary_lang, SubmissionTranslator::resolve_target_language(), 'Sanity check: the option under test never changed the resolved primary language mid-test.' );
	}

	public function test_outbound_reply_skips_primary_translation_when_it_equals_artists_own_language(): void {
		$artist_id = $this->create_artist( 'en_US' ); // artist_lang => 'en', equal to the site's primary.
		$post_id   = $this->create_artwork( $artist_id );
		$parent_id = $this->insert_local_visitor_parent( $post_id, 'fr' ); // distinct -> one translation call expected.

		update_option( 'agnosis_ai_provider', 'wp_ai' );
		WpAiClientTestRegistry::$response = (string) wp_json_encode( [ 'content' => 'Contenu traduit' ] );

		$this->invoke( 'store_artist_gateway_reply', [ $post_id, $parent_id, 'Thanks!', false ] );
		$this->invoke( 'drain_reply_translation_queue' );

		$reply      = $this->latest_child_comment( $post_id, $parent_id );
		$comment_id = (int) $reply->comment_ID;

		$this->assertSame(
			'',
			get_comment_meta( $comment_id, '_agnosis_reply_translated_primary', true ),
			'Artist\'s own language equals the primary language — no primary translation call is needed.'
		);
		$this->assertSame( 'Contenu traduit', get_comment_meta( $comment_id, '_agnosis_reply_translated_content', true ) );
		$this->assertCount( 1, WpAiClientTestRegistry::$prompts );
	}

	public function test_outbound_reply_to_federated_parent_reuses_its_already_detected_source_lang(): void {
		$artist_id = $this->create_artist( 'ca_ES' ); // 'ca'.
		$post_id   = $this->create_artwork( $artist_id );
		// Simulates a federated parent whose own language was already
		// detected by an earlier drain pass — 'de', distinct from both
		// the artist's own language and the site's primary.
		$parent_id = $this->insert_federated_parent( $post_id, 'de' );

		update_option( 'agnosis_ai_provider', 'wp_ai' );
		WpAiClientTestRegistry::$response = (string) wp_json_encode( [ 'content' => 'Übersetzter Inhalt' ] );

		$this->invoke( 'store_artist_gateway_reply', [ $post_id, $parent_id, 'Text en català', false ] );
		$this->invoke( 'drain_reply_translation_queue' );

		$reply      = $this->latest_child_comment( $post_id, $parent_id );
		$comment_id = (int) $reply->comment_ID;

		$this->assertSame(
			'Übersetzter Inhalt',
			get_comment_meta( $comment_id, '_agnosis_reply_translated_content', true ),
			'The federated parent\'s own already-known language (de) must be reused as the commenter-language target, not re-detected.'
		);
		// The parent's own source lang must be left untouched by the outbound branch.
		$this->assertSame( 'de', get_comment_meta( $parent_id, '_agnosis_reply_source_lang', true ) );
	}

	// -------------------------------------------------------------------------
	// §13.4 — reply_to_note()'s contentMap
	// -------------------------------------------------------------------------

	public function test_reply_to_note_content_map_carries_source_primary_and_commenter_versions(): void {
		$artist_id = $this->create_artist( 'ca_ES' ); // 'ca'.
		$post_id   = $this->create_artwork( $artist_id );
		$parent_id = $this->insert_local_visitor_parent( $post_id, 'fr' );

		update_option( 'agnosis_ai_provider', 'wp_ai' );
		WpAiClientTestRegistry::$response = (string) wp_json_encode( [ 'content' => 'Contingut traduït' ] );

		$this->invoke( 'store_artist_gateway_reply', [ $post_id, $parent_id, 'Text en català', false ] );
		$this->invoke( 'drain_reply_translation_queue' );

		$reply = $this->latest_child_comment( $post_id, $parent_id );
		$note  = $this->invoke( 'reply_to_note', [ $reply ] );

		$this->assertArrayHasKey( 'ca', $note['contentMap'], 'Source (artist\'s own language) must always be present.' );
		$this->assertStringContainsString( 'Text en català', $note['contentMap']['ca'] );
		$this->assertArrayHasKey( 'en', $note['contentMap'], 'Primary-language translation must be present.' );
		$this->assertStringContainsString( 'Contingut traduït', $note['contentMap']['en'] );
		$this->assertArrayHasKey( 'fr', $note['contentMap'], 'Original commenter\'s own language translation must be present.' );
		$this->assertStringContainsString( 'Contingut traduït', $note['contentMap']['fr'] );
		$this->assertSame( $note['contentMap']['ca'], $note['content'], 'The flat content field must default to the untouched SOURCE version (a disclosed, documented WP13 §13.4 choice).' );
	}

	// -------------------------------------------------------------------------
	// §13.6a — the collision guard: never notify the artist about their own reply
	// -------------------------------------------------------------------------

	public function test_outbound_reply_drain_never_emails_the_artist_about_their_own_reply(): void {
		$artist_id = $this->create_artist( 'ca_ES' );
		$post_id   = $this->create_artwork( $artist_id );
		$parent_id = $this->insert_local_visitor_parent( $post_id, 'fr' );

		update_option( 'agnosis_ai_provider', 'wp_ai' );
		WpAiClientTestRegistry::$response = (string) wp_json_encode( [ 'content' => 'Contingut traduït' ] );

		$this->start_mail_capture();
		$this->invoke( 'store_artist_gateway_reply', [ $post_id, $parent_id, 'Text en català', false ] );
		$this->invoke( 'drain_reply_translation_queue' );

		$this->assertCount( 0, $this->sent_mails, 'An artist-authored reply must never trigger notify_artist_of_reply() — that would notify them about, and offer a gateway to approve/reject, their own already-approved words.' );
	}

	// -------------------------------------------------------------------------
	// §13.2/§13.3 — federated-inbound language detection, gated
	// -------------------------------------------------------------------------

	public function test_inbound_federated_reply_detects_source_language_when_federate_all_languages_and_stores_it(): void {
		update_option( 'agnosis_federate_languages', 'all' );
		$artist_id = $this->create_artist( 'es_ES' ); // 'es'.
		$post_id   = $this->create_artwork( $artist_id );
		$parent_id = $this->insert_federated_parent( $post_id ); // no source lang yet.

		update_option( 'agnosis_ai_provider', 'wp_ai' );
		WpAiClientTestRegistry::$detected_language = 'fr';
		WpAiClientTestRegistry::$response          = (string) wp_json_encode( [ 'content' => 'Contenido traducido' ] );

		update_comment_meta( $parent_id, '_agnosis_reply_pending_translation', '1' );

		$this->start_mail_capture();
		$this->invoke( 'drain_reply_translation_queue' );

		$this->assertSame( 'fr', get_comment_meta( $parent_id, '_agnosis_reply_source_lang', true ), 'A successful detection must be stored so it is never re-detected later.' );
		$this->assertSame( '', get_comment_meta( $parent_id, '_agnosis_reply_unsupported_lang', true ) );
		$this->assertSame( '', get_comment_meta( $parent_id, '_agnosis_reply_pending_translation', true ) );

		$this->assertCount( 1, $this->sent_mails, 'A detected, supported language must proceed to the normal notify_artist_of_reply() flow.' );
		$this->assertStringNotContainsString( 'could not identify', $this->sent_mails[0]['subject'] );
	}

	public function test_inbound_federated_reply_with_undetectable_language_sends_unsupported_notice_only(): void {
		update_option( 'agnosis_federate_languages', 'all' );
		$artist_id = $this->create_artist( 'es_ES', 'artist@example.com' );
		$post_id   = $this->create_artwork( $artist_id );
		$parent_id = $this->insert_federated_parent( $post_id );

		update_option( 'agnosis_ai_provider', 'wp_ai' );
		WpAiClientTestRegistry::$detected_language = ''; // Simulates "no match".

		update_comment_meta( $parent_id, '_agnosis_reply_pending_translation', '1' );

		$this->start_mail_capture();
		$this->invoke( 'drain_reply_translation_queue' );

		$this->assertSame( '1', get_comment_meta( $parent_id, '_agnosis_reply_unsupported_lang', true ), '§13.5: an undetectable/unsupported language must be flagged.' );
		$this->assertSame( '', get_comment_meta( $parent_id, '_agnosis_reply_pending_translation', true ) );
		$this->assertSame( '', get_comment_meta( $parent_id, '_agnosis_reply_source_lang', true ), 'No language could be resolved — must stay unset.' );
		$this->assertSame( '', get_comment_meta( $parent_id, '_agnosis_reply_translated_content', true ), 'No translation attempt must be made for an unsupported language.' );
		$this->assertSame( '', get_comment_meta( $parent_id, '_agnosis_reply_translated_primary', true ) );
		$this->assertSame( '', get_comment_meta( $parent_id, '_agnosis_reply_moderation_expiry', true ), 'No gateway/expiry must be set — there is nothing to approve or reject.' );

		$this->assertCount( 1, $this->sent_mails, 'Exactly one email — the informational-only notice — must be sent, never the normal gateway one as well.' );
		$mail = $this->sent_mails[0];
		$this->assertSame( 'artist@example.com', $mail['to'] );
		$this->assertStringContainsString( 'could not identify', $mail['subject'] );
		$this->assertStringContainsString( '<!DOCTYPE html>', $mail['message'], 'Must be sent through the branded EmailTemplate::render(), not a bare string.' );
		$this->assertStringNotContainsString( 'agnosis_reply=', $mail['message'], 'Must carry no gateway link — nothing to approve/reject.' );
		$this->assertStringContainsString( 'nothing to approve or reject', $mail['message'] );
	}

	public function test_inbound_federated_reply_skips_detection_when_federate_languages_is_primary_only(): void {
		// Default option value — 'primary-only' — left untouched.
		$artist_id = $this->create_artist( 'es_ES', 'artist@example.com' );
		$post_id   = $this->create_artwork( $artist_id );
		$parent_id = $this->insert_federated_parent( $post_id );

		update_option( 'agnosis_ai_provider', 'wp_ai' );
		// If detection were wrongly attempted, this would surface as the
		// detected language — leaving it null and instead pinning $response
		// proves no detect_language() call happened (its own prompt shape
		// would never reach $response, but a wrongly-triggered call
		// returning '' would still be indistinguishable from "gate off" by
		// meta state alone — the prompt-count assertion below is what
		// actually proves it).
		WpAiClientTestRegistry::$response = (string) wp_json_encode( [ 'content' => 'Contenido traducido' ] );

		update_comment_meta( $parent_id, '_agnosis_reply_pending_translation', '1' );

		$this->start_mail_capture();
		$this->invoke( 'drain_reply_translation_queue' );

		$this->assertSame( '', get_comment_meta( $parent_id, '_agnosis_reply_source_lang', true ), 'Detection must never run when federate_languages stays at its default (primary-only).' );
		$this->assertSame( '', get_comment_meta( $parent_id, '_agnosis_reply_unsupported_lang', true ) );
		$this->assertSame( '', get_comment_meta( $parent_id, '_agnosis_reply_pending_translation', true ) );
		$this->assertCount( 1, $this->sent_mails, 'The normal notify flow must still run.' );
		$this->assertStringNotContainsString( 'could not identify', $this->sent_mails[0]['subject'] );

		// Every prompt sent must be translate_fields()'s own shape, never detect_language()'s.
		foreach ( WpAiClientTestRegistry::$prompts as $prompt ) {
			$this->assertStringNotContainsString( 'Identify which ONE of these languages', $prompt, 'No detection call must ever be attempted when the gate is off.' );
		}
	}
}
