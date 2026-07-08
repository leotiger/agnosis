<?php
/**
 * Integration tests — CommunityBroadcast.
 *
 * Tests cover:
 *
 *   broadcast():
 *     - Sends one email to every other admitted artist, excluding the sender.
 *     - Non-artists (no agnosis_artist role) never receive a copy.
 *     - Sender's display name and email are included in every recipient's body.
 *     - Reply-To points at the community alias (agnosis_email_community), NOT
 *       the sender's own address — a direct reply would bypass translation.
 *     - From header uses Core\CommunityMailer's configured sender identity.
 *     - No AI provider configured (test default) → original text sent unchanged.
 *     - Blank subject and body → nothing sent, returns 0.
 *     - Unknown sender ID → nothing sent, returns 0.
 *     - No other artists exist → nothing sent, returns 0.
 *     - Return value equals the number of recipients actually mailed.
 *
 *   max_length() / exceeds_max_length():
 *     - Defaults to 2000 characters when the option is unset.
 *     - Honours a configured, smaller or larger value.
 *     - Never exceeds the hard 20000-character ceiling regardless of the option.
 *     - Measures combined subject+body length in characters (mb_strlen), not words.
 *
 *   send_too_long_bounce():
 *     - Sends exactly one email, to the sender, not to any other artist.
 *     - Body names the actual length and the configured limit.
 *
 * @package Agnosis\Tests\Integration\Artist
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Artist;

use Agnosis\Artist\CommunityBroadcast;

class CommunityBroadcastTest extends \WP_UnitTestCase {

	private CommunityBroadcast $broadcast;

	/** All wp_mail() calls captured during a test (keys: to, subject, message, headers). */
	private array $sent_mails = [];

	/** The pre_wp_mail filter closure registered for the current test. */
	private ?\Closure $mail_filter = null;

	protected function setUp(): void {
		parent::setUp();
		$this->broadcast = new CommunityBroadcast();

		// No AI provider configured — from_settings() returns null, so every
		// test below exercises the deterministic "no translation" path unless
		// a test explicitly configures one.
		delete_option( 'agnosis_ai_provider' );
		delete_option( 'agnosis_openai_api_key' );
		delete_option( 'agnosis_anthropic_api_key' );

		// The Reply-To header is only added when the intake alias is
		// configured — see CommunityBroadcast::send_one(). Set for every test
		// so that path is exercised by default; test_reply_to_header_omitted_
		// when_alias_not_configured() below explicitly unsets it instead.
		update_option( 'agnosis_email_community', 'community@agnosis.test' );

		$this->start_mail_capture();
	}

	protected function tearDown(): void {
		$this->remove_mail_capture();
		delete_option( 'agnosis_community_from_name' );
		delete_option( 'agnosis_community_from_email' );
		delete_option( 'agnosis_email_community' );
		delete_option( 'agnosis_community_broadcast_max_chars' );
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

	/** @return array<array<string,mixed>> */
	private function mails_to( string $email ): array {
		return array_values(
			array_filter( $this->sent_mails, fn( array $m ) => $m['to'] === $email )
		);
	}

	private function create_artist( string $email, string $display_name = '', string $locale = '' ): int {
		$args = [ 'role' => 'subscriber', 'user_email' => $email ];
		if ( '' !== $display_name ) {
			$args['display_name'] = $display_name;
		}
		$id   = self::factory()->user->create( $args );
		$user = get_userdata( $id );
		$user->add_role( 'agnosis_artist' );
		if ( '' !== $locale ) {
			update_user_meta( $id, 'locale', $locale );
		}
		return $id;
	}

	// =========================================================================
	// Recipients
	// =========================================================================

	public function test_sends_to_every_other_admitted_artist(): void {
		$sender_id = $this->create_artist( 'sender@example.com', 'Sender Artist' );
		$this->create_artist( 'recipient1@example.com', 'Recipient One' );
		$this->create_artist( 'recipient2@example.com', 'Recipient Two' );

		$sent = $this->broadcast->broadcast( $sender_id, 'Hello', 'A message for everyone.' );

		$this->assertSame( 2, $sent );
		$this->assertCount( 1, $this->mails_to( 'recipient1@example.com' ) );
		$this->assertCount( 1, $this->mails_to( 'recipient2@example.com' ) );
	}

	public function test_sender_never_receives_their_own_broadcast(): void {
		$sender_id = $this->create_artist( 'sender2@example.com', 'Sender Two' );
		$this->create_artist( 'recipient3@example.com', 'Recipient Three' );

		$this->broadcast->broadcast( $sender_id, 'Hello', 'A message.' );

		$this->assertEmpty( $this->mails_to( 'sender2@example.com' ), 'Sender must not receive a copy of their own broadcast.' );
	}

	public function test_non_artist_users_never_receive_a_broadcast(): void {
		$sender_id = $this->create_artist( 'sender3@example.com', 'Sender Three' );
		self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => 'plain-subscriber@example.com' ] );

		$this->broadcast->broadcast( $sender_id, 'Hello', 'A message.' );

		$this->assertEmpty( $this->mails_to( 'plain-subscriber@example.com' ), 'A subscriber with no agnosis_artist role must not receive a broadcast.' );
	}

	public function test_returns_zero_when_no_other_artists_exist(): void {
		$sender_id = $this->create_artist( 'lonely@example.com', 'Lonely Artist' );

		$sent = $this->broadcast->broadcast( $sender_id, 'Hello', 'Anyone there?' );

		$this->assertSame( 0, $sent );
		$this->assertEmpty( $this->sent_mails );
	}

	public function test_unknown_sender_id_sends_nothing(): void {
		$this->create_artist( 'recipient4@example.com', 'Recipient Four' );

		$sent = $this->broadcast->broadcast( 999999, 'Hello', 'A message.' );

		$this->assertSame( 0, $sent );
		$this->assertEmpty( $this->sent_mails );
	}

	public function test_blank_subject_and_body_sends_nothing(): void {
		$sender_id = $this->create_artist( 'sender4@example.com', 'Sender Four' );
		$this->create_artist( 'recipient5@example.com', 'Recipient Five' );

		$sent = $this->broadcast->broadcast( $sender_id, '   ', '' );

		$this->assertSame( 0, $sent );
		$this->assertEmpty( $this->sent_mails );
	}

	// =========================================================================
	// Message content
	// =========================================================================

	public function test_body_includes_sender_display_name_and_email(): void {
		$sender_id = $this->create_artist( 'credit@example.com', 'Credited Sender' );
		$this->create_artist( 'recipient6@example.com', 'Recipient Six' );

		$this->broadcast->broadcast( $sender_id, 'Subject line', 'Body text.' );

		$mail = $this->mails_to( 'recipient6@example.com' )[0];
		$this->assertStringContainsString( 'Credited Sender', $mail['message'] );
		$this->assertStringContainsString( 'credit@example.com', $mail['message'] );
	}

	public function test_body_includes_original_subject_and_text_when_untranslated(): void {
		$sender_id = $this->create_artist( 'sender5@example.com', 'Sender Five' );
		$this->create_artist( 'recipient7@example.com', 'Recipient Seven' );

		$this->broadcast->broadcast( $sender_id, 'A specific subject', 'A specific body.' );

		$mail = $this->mails_to( 'recipient7@example.com' )[0];
		$this->assertStringContainsString( 'A specific subject', $mail['message'] );
		$this->assertStringContainsString( 'A specific body.', $mail['message'] );
	}

	public function test_reply_to_header_points_to_the_community_alias(): void {
		$sender_id = $this->create_artist( 'replyto@example.com', 'Reply To Me' );
		$this->create_artist( 'recipient8@example.com', 'Recipient Eight' );

		$this->broadcast->broadcast( $sender_id, 'Hello', 'A message.' );

		$mail    = $this->mails_to( 'recipient8@example.com' )[0];
		$headers = implode( "\n", (array) $mail['headers'] );
		$this->assertStringContainsString( 'Reply-To: Reply To Me via', $headers, 'Reply-To display name should credit the sender.' );
		$this->assertStringContainsString( '<community@agnosis.test>', $headers, 'Reply-To address must be the community alias, not the sender\'s own address.' );
		$this->assertStringNotContainsString( 'Reply-To: Reply To Me <replyto@example.com>', $headers, 'A reply must not go directly to the sender — it would bypass translation.' );
	}

	public function test_reply_to_header_omitted_when_alias_not_configured(): void {
		delete_option( 'agnosis_email_community' );

		$sender_id = $this->create_artist( 'noalias@example.com', 'No Alias Sender' );
		$this->create_artist( 'recipient8b@example.com', 'Recipient Eight B' );

		$this->broadcast->broadcast( $sender_id, 'Hello', 'A message.' );

		$mail    = $this->mails_to( 'recipient8b@example.com' )[0];
		$headers = implode( "\n", (array) $mail['headers'] );
		$this->assertStringNotContainsString( 'Reply-To:', $headers );
	}

	public function test_from_header_uses_community_sender_identity(): void {
		update_option( 'agnosis_community_from_name', 'Test Community' );
		update_option( 'agnosis_community_from_email', 'hello@agnosis.test' );

		$sender_id = $this->create_artist( 'sender6@example.com', 'Sender Six' );
		$this->create_artist( 'recipient9@example.com', 'Recipient Nine' );

		$this->broadcast->broadcast( $sender_id, 'Hello', 'A message.' );

		$mail    = $this->mails_to( 'recipient9@example.com' )[0];
		$headers = implode( "\n", (array) $mail['headers'] );
		$this->assertStringContainsString( 'From: Test Community <hello@agnosis.test>', $headers );
	}

	// =========================================================================
	// max_length() / exceeds_max_length()
	// =========================================================================

	public function test_max_length_defaults_to_2000(): void {
		$this->assertSame( 2000, $this->broadcast->max_length() );
	}

	public function test_max_length_honours_configured_option(): void {
		update_option( 'agnosis_community_broadcast_max_chars', 500 );
		$this->assertSame( 500, $this->broadcast->max_length() );
	}

	public function test_max_length_never_exceeds_hard_ceiling(): void {
		update_option( 'agnosis_community_broadcast_max_chars', 999999 );
		$this->assertSame( 20000, $this->broadcast->max_length() );
	}

	public function test_exceeds_max_length_is_false_at_the_limit(): void {
		update_option( 'agnosis_community_broadcast_max_chars', 10 );
		// Exactly 10 characters combined — at the limit, not over it.
		$this->assertFalse( $this->broadcast->exceeds_max_length( '12345', '67890' ) );
	}

	public function test_exceeds_max_length_is_true_just_over_the_limit(): void {
		update_option( 'agnosis_community_broadcast_max_chars', 10 );
		$this->assertTrue( $this->broadcast->exceeds_max_length( '12345', '678901' ) );
	}

	public function test_exceeds_max_length_counts_characters_not_words(): void {
		update_option( 'agnosis_community_broadcast_max_chars', 10 );
		// A single 11-character "word" (no spaces) still exceeds a 10-character
		// limit — this must not require whitespace to count length, since CJK
		// scripts don't separate words with spaces at all.
		$this->assertTrue( $this->broadcast->exceeds_max_length( '', 'abcdefghijk' ) );
	}

	public function test_exceeds_max_length_counts_multibyte_characters_correctly(): void {
		update_option( 'agnosis_community_broadcast_max_chars', 10 );
		// 5 Chinese characters — 15 bytes in UTF-8, but 5 characters. Must not
		// exceed a 10-character limit; a byte-counting bug (strlen() instead of
		// mb_strlen()) would wrongly report 15 and trigger a bounce here.
		$five_chars = '你好世界啊'; // 5 characters.
		$this->assertFalse( $this->broadcast->exceeds_max_length( '', $five_chars ) );
	}

	// =========================================================================
	// send_too_long_bounce()
	// =========================================================================

	public function test_bounce_sends_only_to_the_sender(): void {
		$sender_id = $this->create_artist( 'toolong@example.com', 'Too Long Sender' );
		$this->create_artist( 'recipient10@example.com', 'Recipient Ten' );

		$this->broadcast->send_too_long_bounce( $sender_id, 12345 );

		$this->assertCount( 1, $this->mails_to( 'toolong@example.com' ) );
		$this->assertEmpty( $this->mails_to( 'recipient10@example.com' ), 'A bounced message must never reach any other community member.' );
		$this->assertCount( 1, $this->sent_mails, 'Exactly one email (to the sender) must be sent, nothing else.' );
	}

	public function test_bounce_body_names_the_length_and_limit(): void {
		update_option( 'agnosis_community_broadcast_max_chars', 500 );
		$sender_id = $this->create_artist( 'toolong2@example.com', 'Too Long Sender Two' );

		$this->broadcast->send_too_long_bounce( $sender_id, 12345 );

		$mail = $this->mails_to( 'toolong2@example.com' )[0];
		$this->assertStringContainsString( '12345', $mail['message'] );
		$this->assertStringContainsString( '500', $mail['message'] );
	}

	public function test_bounce_does_nothing_for_unknown_sender(): void {
		$this->broadcast->send_too_long_bounce( 999999, 12345 );
		$this->assertEmpty( $this->sent_mails );
	}
}
