<?php
/**
 * Core\Privacy — the GDPR DSAR exporters and erasers.
 *
 * Written for the sixteenth audit's G-1 and G-5 (2026-07-31); this class had
 * no test file at all before, which is part of how G-1 survived 0.9.60's
 * schema change unnoticed for a month.
 *
 * G-1 is the one that matters most here. 0.9.60 turned
 * `agnosis_contact_messages` into a TWO-PARTY thread table — an artist's reply
 * is stored with `sender = 'artist'` and, so the thread can be delivered and
 * continued, the SAME `visitor_email` as the message it answers. Privacy.php
 * kept keying on that column alone, so:
 *
 *   - a visitor's data EXPORT handed them the artist's private replies,
 *     rendered under "Your name"/"Message" as though the visitor wrote them
 *     (GDPR Art. 15(4): access must not adversely affect others' rights); and
 *   - a visitor's ERASURE request blanked the artist's own reply text, in the
 *     only place it is stored — irreversible destruction of a third party's
 *     content.
 *
 * The erasure half is the reason these tests exist rather than a manual check:
 * an export can be re-run, that could not be undone.
 *
 * G-5 covers the reply comment meta WordPress core's own comment
 * exporter/eraser cannot see (it exports no meta and erases none), i.e. the AI
 * translations Agnosis itself generated of a visitor's words.
 *
 * @package Agnosis
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Core;

use Agnosis\Core\Privacy;
use Agnosis\Network\ActivityPub;

class PrivacyTest extends \WP_UnitTestCase {

	private const VISITOR = 'visitor@example.com';
	private const OTHER   = 'someone-else@example.com';

	private Privacy $privacy;
	private int $artist_id;

	protected function setUp(): void {
		parent::setUp();
		$this->privacy   = new Privacy();
		$this->artist_id = self::id( self::factory()->user->create( [ 'role' => 'agnosis_artist', 'display_name' => 'Tessa' ] ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Narrow a `WP_UnitTest_Factory_For_Thing::create()` result to a plain int.
	 *
	 * Every factory `create()` is typed `int|WP_Error`, so assigning one
	 * straight into a typed property or passing it to a core function that
	 * wants an `int` is a static-analysis error. An `(int)` cast silences the
	 * call site but is itself a PHPStan finding ("Cannot cast int|WP_Error to
	 * int") and would turn a broken fixture into the id `0` — a confusing
	 * assertion failure somewhere further down instead of a clear one here.
	 *
	 * Throwing (rather than `$this->fail()`) is deliberate on both counts: it
	 * is what actually narrows the type for PHPStan, since a `fail()` call is
	 * not annotated `never` in PHPUnit 9.6 and execution would be assumed to
	 * continue past it — and a fixture that cannot be created is a test
	 * *error*, not an assertion failure, which is exactly how PHPUnit reports
	 * an uncaught exception.
	 *
	 * @param int|\WP_Error $created Raw factory result.
	 */
	private static function id( $created ): int {
		if ( $created instanceof \WP_Error ) {
			throw new \RuntimeException( 'Test fixture could not be created: ' . $created->get_error_message() );
		}

		return $created;
	}

	/** Insert one agnosis_contact_messages row and return its id. */
	private function seed_message( string $sender, string $message, string $visitor_email = self::VISITOR, ?int $thread_root = null ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test setup.
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_contact_messages',
			[
				'artist_id'              => $this->artist_id,
				'visitor_name'           => 'Visitor Name',
				'visitor_email'          => $visitor_email,
				'message'                => $message,
				'translated_message'     => 'translated: ' . $message,
				'status'                 => 'sent',
				'ip'                     => '203.0.113.9',
				'created_at'             => current_time( 'mysql' ),
				'thread_root_id'         => $thread_root,
				'parent_id'              => $thread_root,
				'sender'                 => $sender,
				'sender_lang'            => 'ca',
				'sender_lang_name'       => 'Catalan',
				'reply_token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * The `agnosis_contact_messages` row with this id, or a hard failure.
	 *
	 * Returns non-nullable for the same reason id() throws: every caller here
	 * has just created the row it is asking for, so a null is a broken fixture,
	 * not a case to assert around — and returning `?array` would force a
	 * null-check at each of a dozen call sites purely to satisfy the analyser.
	 *
	 * @return array<string, mixed>
	 */
	private function row( int $id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}agnosis_contact_messages WHERE id = %d", $id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			throw new \RuntimeException( "Expected agnosis_contact_messages row #{$id} to exist." );
		}

		return $row;
	}

	/** The WP_Comment with this id, or a hard failure — same reasoning as row(). */
	private function comment( int $id ): \WP_Comment {
		$comment = get_comment( $id );

		if ( ! $comment instanceof \WP_Comment ) {
			throw new \RuntimeException( "Expected comment #{$id} to exist." );
		}

		return $comment;
	}

	/**
	 * Flatten one exporter result into a list of "name|value" strings.
	 *
	 * @param array{data: array<int, array{group_id: string, group_label: string, item_id: string, data: array<int, array{name: string, value: string}>}>, done: bool} $result
	 * @return array<int, string>
	 */
	private function flatten( array $result ): array {
		$out = [];
		foreach ( $result['data'] as $item ) {
			foreach ( $item['data'] as $field ) {
				$out[] = $field['name'] . '|' . $field['value'];
			}
		}
		return $out;
	}

	private function seed_reply_comment( string $author_email, string $content ): int {
		$post_id = self::id( self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_title' => 'Endemoniat' ] ) );

		$comment_id = self::id( self::factory()->comment->create( [
			'comment_post_ID'      => $post_id,
			'comment_author'       => 'Visitor Name',
			'comment_author_email' => $author_email,
			'comment_content'      => $content,
			'comment_type'         => ActivityPub::LOCAL_REPLY_COMMENT_TYPE,
			'comment_approved'     => 1,
		] ) );

		update_comment_meta( $comment_id, ActivityPub::REPLY_SOURCE_LANG_META, 'ca' );
		update_comment_meta( $comment_id, ActivityPub::REPLY_TRANSLATED_CONTENT_META, 'artist-language version of: ' . $content );
		update_comment_meta( $comment_id, ActivityPub::REPLY_TRANSLATED_PRIMARY_META, 'site-language version of: ' . $content );

		return $comment_id;
	}

	// -------------------------------------------------------------------------
	// G-1 — export must not disclose the artist's replies as the visitor's own
	// -------------------------------------------------------------------------

	public function test_export_contact_messages_returns_only_the_visitors_own_messages(): void {
		$root = $this->seed_message( 'visitor', 'Hello, I love your work.' );
		$this->seed_message( 'artist', 'ARTIST PRIVATE REPLY', self::VISITOR, $root );
		$this->seed_message( 'visitor', 'Thanks for answering!', self::VISITOR, $root );

		$fields = $this->flatten( $this->privacy->export_contact_messages( self::VISITOR ) );
		$joined = implode( "\n", $fields );

		$this->assertStringContainsString( 'Hello, I love your work.', $joined );
		$this->assertStringContainsString( 'Thanks for answering!', $joined );
		$this->assertStringNotContainsString(
			'ARTIST PRIVATE REPLY',
			$joined,
			'An artist\'s own reply must never appear in the visitor\'s export (GDPR Art. 15(4)).'
		);
	}

	public function test_export_contact_messages_ignores_another_visitors_thread(): void {
		$this->seed_message( 'visitor', 'Mine.', self::VISITOR );
		$this->seed_message( 'visitor', 'SOMEONE ELSES MESSAGE', self::OTHER );

		$joined = implode( "\n", $this->flatten( $this->privacy->export_contact_messages( self::VISITOR ) ) );

		$this->assertStringContainsString( 'Mine.', $joined );
		$this->assertStringNotContainsString( 'SOMEONE ELSES MESSAGE', $joined );
	}

	public function test_export_contact_messages_surfaces_the_thread_columns(): void {
		$root = $this->seed_message( 'visitor', 'First one.' );
		$this->seed_message( 'visitor', 'Follow-up.', self::VISITOR, $root );

		$joined = implode( "\n", $this->flatten( $this->privacy->export_contact_messages( self::VISITOR ) ) );

		$this->assertStringContainsString( 'Conversation|#' . $root, $joined );
		$this->assertStringContainsString( 'First message', $joined );
		$this->assertStringContainsString( 'Follow-up in an existing conversation', $joined );
		$this->assertStringContainsString( 'Detected language|Catalan', $joined );
	}

	// -------------------------------------------------------------------------
	// G-1 — erasure must not destroy the artist's replies
	// -------------------------------------------------------------------------

	public function test_erase_contact_messages_redacts_the_visitors_own_messages(): void {
		$id = $this->seed_message( 'visitor', 'Please forget this.' );

		$this->privacy->erase_contact_messages( self::VISITOR );

		$row = $this->row( $id );
		$this->assertStringNotContainsString( 'Please forget this.', (string) $row['message'] );
		$this->assertSame( 'erased@erased.invalid', $row['visitor_email'] );
		$this->assertSame( '', (string) $row['visitor_name'] );
		$this->assertSame( '', (string) $row['ip'] );
		$this->assertSame( '', (string) $row['translated_message'] );
		$this->assertNull( $row['sender_lang'] );
		$this->assertNull( $row['sender_lang_name'] );
	}

	public function test_erase_contact_messages_keeps_the_artists_reply_text_but_detaches_the_visitor(): void {
		$root     = $this->seed_message( 'visitor', 'A question.' );
		$reply_id = $this->seed_message( 'artist', 'ARTIST PRIVATE REPLY', self::VISITOR, $root );

		$this->privacy->erase_contact_messages( self::VISITOR );

		$row = $this->row( $reply_id );
		$this->assertSame(
			'ARTIST PRIVATE REPLY',
			(string) $row['message'],
			'The artist\'s own words are the artist\'s data and must survive a visitor\'s erasure request.'
		);
		// ...but nothing identifying the erased visitor may remain on it, and
		// the token that would let anyone continue the thread must be dead.
		$this->assertSame( 'erased@erased.invalid', $row['visitor_email'] );
		$this->assertSame( '', (string) $row['visitor_name'] );
		$this->assertSame( '', (string) $row['ip'] );
		$this->assertNull( $row['reply_token_expires_at'] );
	}

	public function test_erase_contact_messages_reports_partial_erasure_when_an_artist_reply_was_kept(): void {
		$root = $this->seed_message( 'visitor', 'A question.' );
		$this->seed_message( 'artist', 'Kept.', self::VISITOR, $root );

		$result = $this->privacy->erase_contact_messages( self::VISITOR );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'], 'Keeping the artist\'s reply is a partial erasure and must be reported as one.' );
		$this->assertNotEmpty( $result['messages'] );
	}

	public function test_erase_contact_messages_reports_a_clean_erasure_when_no_artist_replied(): void {
		$this->seed_message( 'visitor', 'A question nobody answered.' );

		$result = $this->privacy->erase_contact_messages( self::VISITOR );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertSame( [], $result['messages'] );
	}

	public function test_erase_contact_messages_leaves_another_visitors_row_untouched(): void {
		$mine   = $this->seed_message( 'visitor', 'Mine.', self::VISITOR );
		$theirs = $this->seed_message( 'visitor', 'THEIRS', self::OTHER );

		$this->privacy->erase_contact_messages( self::VISITOR );

		$this->assertStringNotContainsString( 'Mine.', (string) $this->row( $mine )['message'] );
		$this->assertSame( 'THEIRS', (string) $this->row( $theirs )['message'] );
		$this->assertSame( self::OTHER, $this->row( $theirs )['visitor_email'] );
	}

	// -------------------------------------------------------------------------
	// G-5 — reply comment meta, which core's own comment tools cannot see
	// -------------------------------------------------------------------------

	public function test_export_replies_includes_the_translations_core_does_not_export(): void {
		$this->seed_reply_comment( self::VISITOR, 'Quina peça tan bonica.' );

		$joined = implode( "\n", $this->flatten( $this->privacy->export_replies( self::VISITOR ) ) );

		$this->assertStringContainsString( 'Quina peça tan bonica.', $joined );
		$this->assertStringContainsString( 'artist-language version of: Quina peça tan bonica.', $joined );
		$this->assertStringContainsString( 'site-language version of: Quina peça tan bonica.', $joined );
		$this->assertStringContainsString( 'Detected language|ca', $joined );
		$this->assertStringContainsString( 'On artwork|Endemoniat', $joined );
	}

	public function test_export_replies_ignores_another_persons_reply(): void {
		$this->seed_reply_comment( self::OTHER, 'NOT MINE' );

		$result = $this->privacy->export_replies( self::VISITOR );

		$this->assertSame( [], $result['data'] );
	}

	public function test_erase_replies_deletes_every_derived_translation(): void {
		$comment_id = $this->seed_reply_comment( self::VISITOR, 'Original words.' );

		$result = $this->privacy->erase_replies( self::VISITOR );

		$this->assertTrue( $result['items_removed'] );
		$this->assertSame( '', (string) get_comment_meta( $comment_id, ActivityPub::REPLY_TRANSLATED_CONTENT_META, true ) );
		$this->assertSame( '', (string) get_comment_meta( $comment_id, ActivityPub::REPLY_TRANSLATED_PRIMARY_META, true ) );
		$this->assertSame( '', (string) get_comment_meta( $comment_id, ActivityPub::REPLY_SOURCE_LANG_META, true ) );
	}

	/**
	 * The comment row itself is core's to anonymise, not ours. Overriding that
	 * for one comment type would make Agnosis replies behave differently from
	 * every other comment on the site — see erase_replies()'s own docblock.
	 */
	public function test_erase_replies_leaves_the_comment_row_to_core(): void {
		$comment_id = $this->seed_reply_comment( self::VISITOR, 'Original words.' );

		$this->privacy->erase_replies( self::VISITOR );

		$this->assertSame( 'Original words.', $this->comment( $comment_id )->comment_content );
	}

	public function test_erase_replies_reports_nothing_removed_when_there_is_nothing_to_remove(): void {
		$result = $this->privacy->erase_replies( 'nobody@example.com' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}

	// -------------------------------------------------------------------------
	// Registration — both new groups must actually be wired into core's tools
	// -------------------------------------------------------------------------

	public function test_replies_group_is_registered_with_both_core_tools(): void {
		$this->assertArrayHasKey( 'agnosis-replies', $this->privacy->register_exporters( [] ) );
		$this->assertArrayHasKey( 'agnosis-replies', $this->privacy->register_erasers( [] ) );
	}
}
