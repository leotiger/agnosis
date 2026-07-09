<?php
/**
 * Integration tests — CommunityBroadcast::broadcast()'s per-language-group
 * translation (fifth audit §4a), the one piece of the class the existing
 * CommunityBroadcastTest.php deliberately never exercises — that file
 * disables the AI provider entirely so every test there hits the
 * deterministic "no translation" path.
 *
 * Before §4a, broadcast() called translate_text() once PER RECIPIENT — twenty
 * Spanish-speaking members meant twenty separate, byte-identical Spanish
 * translation calls. The fix buckets recipients by target language first and
 * translates each distinct language exactly once (N recipients x 2 calls ->
 * L languages x 2 calls). These tests configure a real, non-null
 * SubmissionTranslator (Providers\WordPressAI, which needs no API key) backed
 * by a fake `wp_ai_client_prompt()` NAMESPACE-SCOPED stub (see
 * Stubs/wp_ai_global_stubs.php, which forwards to the real, shared stub in
 * Integration/AI/Stubs/) so the actual grouping logic runs against something
 * other than a null translator, and asserts on the number of AI calls made
 * plus the translated content each recipient actually receives.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\CommunityBroadcast;
use Agnosis\Tests\Integration\AI\Stubs\WpAiClientTestRegistry;

require_once __DIR__ . '/Stubs/wp_ai_global_stubs.php';

class CommunityBroadcastLanguageGroupingTest extends \WP_UnitTestCase {

	private CommunityBroadcast $broadcast;

	/** All wp_mail() calls captured during a test (keys: to, subject, message, headers). */
	private array $sent_mails = [];

	/** The pre_wp_mail filter closure registered for the current test. */
	private ?\Closure $mail_filter = null;

	protected function setUp(): void {
		parent::setUp();
		$this->broadcast = new CommunityBroadcast();
		WpAiClientTestRegistry::reset();

		// Providers\WordPressAI needs no API key — only agnosis_ai_provider set
		// to 'wp_ai' and the fake wp_ai_client_prompt() namespace-scoped stub
		// (loaded above) for SubmissionTranslator::from_settings() to return a
		// real, non-null translator.
		update_option( 'agnosis_ai_provider', 'wp_ai' );

		// Pin a known, stable language map — same convention
		// ApplicationBiographyTest/AdmissionIntegrationTest use — so this test
		// doesn't depend on Lingua Forge being active or on state another test
		// in the same process left behind.
		add_filter( 'agnosis_translation_languages', [ $this, 'filter_test_language_names' ] );

		$this->start_mail_capture();
	}

	protected function tearDown(): void {
		$this->remove_mail_capture();
		delete_option( 'agnosis_ai_provider' );
		delete_option( 'agnosis_email_community' );
		WpAiClientTestRegistry::reset();
		parent::tearDown();
	}

	public function filter_test_language_names( array $languages ): array {
		return array_replace( $languages, [ 'en' => 'English', 'es' => 'Spanish', 'fr' => 'French' ] );
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

	/** @return array<array<string,mixed>> */
	private function mails_to( string $email ): array {
		return array_values(
			array_filter( $this->sent_mails, fn( array $m ) => $m['to'] === $email )
		);
	}

	private function create_artist( string $email, string $locale = '' ): int {
		$id   = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => $email ] );
		$user = get_userdata( $id );
		$user->add_role( 'agnosis_artist' );
		if ( '' !== $locale ) {
			update_user_meta( $id, 'locale', $locale );
		}
		return $id;
	}

	/** Count of recorded prompts whose "Translate ... to X." target matches $language_name. */
	private function prompt_count_for_language( string $language_name ): int {
		return count( array_filter(
			WpAiClientTestRegistry::$prompts,
			static fn( string $p ) => str_contains( $p, "Translate the sections below to {$language_name}." )
		) );
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	public function test_translation_is_called_once_per_language_not_once_per_recipient(): void {
		$sender_id = $this->create_artist( 'grouping-sender@example.com', 'en_US' );
		// Three Spanish recipients and two French recipients — five recipients,
		// only two distinct target languages.
		$this->create_artist( 'es-1@example.com', 'es_ES' );
		$this->create_artist( 'es-2@example.com', 'es_ES' );
		$this->create_artist( 'es-3@example.com', 'es_ES' );
		$this->create_artist( 'fr-1@example.com', 'fr_FR' );
		$this->create_artist( 'fr-2@example.com', 'fr_FR' );

		$sent = $this->broadcast->broadcast( $sender_id, 'Group show update', 'The opening moved to Friday.' );

		$this->assertSame( 5, $sent );

		// Two fields (subject + body) translated once per distinct language —
		// 2 languages x 2 fields = 4 calls total, never 5 recipients x 2 = 10.
		$this->assertCount( 4, WpAiClientTestRegistry::$prompts, 'translate_text() must be called once per language group per field, not once per recipient.' );
		$this->assertSame( 2, $this->prompt_count_for_language( 'Spanish' ), 'Exactly one subject + one body call for the Spanish group, regardless of how many Spanish recipients there are.' );
		$this->assertSame( 2, $this->prompt_count_for_language( 'French' ), 'Exactly one subject + one body call for the French group, regardless of how many French recipients there are.' );
	}

	public function test_recipients_in_the_same_language_group_receive_identical_translated_text(): void {
		$sender_id = $this->create_artist( 'grouping-sender2@example.com', 'en_US' );
		$this->create_artist( 'es-a@example.com', 'es_ES' );
		$this->create_artist( 'es-b@example.com', 'es_ES' );

		$this->broadcast->broadcast( $sender_id, 'Same subject', 'Same body text.' );

		$mail_a = $this->mails_to( 'es-a@example.com' )[0];
		$mail_b = $this->mails_to( 'es-b@example.com' )[0];

		$this->assertStringContainsString( '[Spanish] Same body text.', $mail_a['message'] );
		$this->assertStringContainsString( '[Spanish] Same body text.', $mail_b['message'], 'Both Spanish recipients must receive the exact same translated copy — proof the translation was computed once for the group, then reused, not recomputed per recipient.' );
	}

	public function test_recipient_sharing_the_senders_language_gets_the_original_text_untranslated(): void {
		$sender_id = $this->create_artist( 'grouping-sender3@example.com', 'en_US' );
		$this->create_artist( 'en-recipient@example.com', 'en_US' );

		$this->broadcast->broadcast( $sender_id, 'No translation needed', 'Same language as the sender.' );

		$this->assertCount( 0, WpAiClientTestRegistry::$prompts, 'A recipient sharing the sender\'s own language needs no AI call at all.' );

		$mail = $this->mails_to( 'en-recipient@example.com' )[0];
		$this->assertStringContainsString( 'Same language as the sender.', $mail['message'] );
		$this->assertStringNotContainsString( '[English]', $mail['message'] );
	}

	public function test_mixed_broadcast_only_translates_the_groups_that_actually_need_it(): void {
		$sender_id = $this->create_artist( 'grouping-sender4@example.com', 'en_US' );
		$this->create_artist( 'en-same@example.com', 'en_US' );   // No translation needed.
		$this->create_artist( 'es-only@example.com', 'es_ES' );   // Needs Spanish.

		$sent = $this->broadcast->broadcast( $sender_id, 'Mixed group', 'One language differs.' );

		$this->assertSame( 2, $sent );
		$this->assertCount( 2, WpAiClientTestRegistry::$prompts, 'Only the Spanish group requires translation (subject + body) — the English-locale recipient must not trigger any AI call.' );
		$this->assertSame( 2, $this->prompt_count_for_language( 'Spanish' ) );

		$en_mail = $this->mails_to( 'en-same@example.com' )[0];
		$es_mail = $this->mails_to( 'es-only@example.com' )[0];
		$this->assertStringContainsString( 'One language differs.', $en_mail['message'] );
		$this->assertStringContainsString( '[Spanish] One language differs.', $es_mail['message'] );
	}

	public function test_recipient_with_no_locale_set_falls_back_to_original_text(): void {
		$sender_id = $this->create_artist( 'grouping-sender5@example.com', 'en_US' );
		// No locale set at all — target_lang resolves to '', so needs_translation
		// is false regardless of the sender's language.
		$this->create_artist( 'no-locale@example.com' );

		$this->broadcast->broadcast( $sender_id, 'Subject', 'Body text.' );

		$this->assertCount( 0, WpAiClientTestRegistry::$prompts );
		$mail = $this->mails_to( 'no-locale@example.com' )[0];
		$this->assertStringContainsString( 'Body text.', $mail['message'] );
	}
}
