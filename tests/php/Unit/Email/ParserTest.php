<?php
/**
 * Unit tests for the Email Parser.
 *
 * @package Agnosis\Tests\Unit\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\Email;

use Agnosis\Email\Parser;
use Agnosis\Tests\Unit\Email\WebhookSignatureTest;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeImapAttachment.php';
require_once __DIR__ . '/FakeParserImapMessage.php';

class ParserTest extends TestCase {

	private Parser $parser;

	protected function setUp(): void {
		$this->parser = new Parser();
	}

	/**
	 * Resets the shared fake options store (Agnosis\Email's namespace-scoped
	 * get_option() stub reads WebhookSignatureTest::$options — see that
	 * class's own docblock) so a test below that configures
	 * agnosis_email_remove/agnosis_email_promote never leaks into another
	 * test file's assertions.
	 */
	protected function tearDown(): void {
		WebhookSignatureTest::$options = [];
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// parse_webhook_payload
	// -------------------------------------------------------------------------

	/**
	 * Poetry is art too, and a biography can be text-only — a submission with
	 * no attachment is no longer automatically rejected as long as it carries
	 * real text (see parse_webhook_payload()'s relaxed gate). This test used
	 * to assert the opposite (null); the payload here is exactly the shape a
	 * text-only bio@/pure@ email arrives as.
	 */
	public function test_parse_webhook_accepts_a_text_only_submission_with_no_attachments(): void {
		$payload = [
			'sender'           => 'artist@example.com',
			'subject'          => 'My new painting',
			'stripped-text'    => 'Here is my artwork.',
			'attachment-count' => 0,
		];

		$result = $this->parser->parse_webhook_payload( $payload );

		$this->assertIsArray( $result );
		$this->assertSame( 'Here is my artwork.', $result['description'] );
		$this->assertSame( [], $result['attachments'] );
	}

	public function test_parse_webhook_returns_null_when_no_attachments_and_no_text(): void {
		$payload = [
			'sender'           => 'artist@example.com',
			'subject'          => 'Empty',
			'stripped-text'    => '',
			'attachment-count' => 0,
		];

		$result = $this->parser->parse_webhook_payload( $payload );

		$this->assertNull( $result, 'Nothing usable — no attachment and no real text — must still be rejected.' );
	}

	public function test_parse_webhook_returns_null_when_attachment_count_missing(): void {
		$result = $this->parser->parse_webhook_payload( [
			'sender'  => 'a@b.com',
			'subject' => 'Art',
		] );

		$this->assertNull( $result );
	}

	public function test_parse_webhook_extracts_sender_and_description(): void {
		// Create a real temporary image file so the parser can read it.
		$tmp  = tempnam( sys_get_temp_dir(), 'agnosis_test_' );
		file_put_contents( $tmp, str_repeat( 'x', 100 ) ); // tiny fake image

		$_FILES['attachment-1'] = [
			'name'     => 'artwork.jpg',
			'type'     => 'image/jpeg',
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => 100,
		];

		$payload = [
			'sender'           => 'artist@example.com',
			'subject'          => 'Seascape at dawn',
			'stripped-text'    => 'I painted this at sunrise.',
			'attachment-count' => 1,
		];

		$result = $this->parser->parse_webhook_payload( $payload );

		unlink( $tmp );
		unset( $_FILES['attachment-1'] );

		$this->assertIsArray( $result );
		$this->assertSame( 'artist@example.com', $result['from'] );
		$this->assertSame( 'Seascape at dawn', $result['subject'] );
		$this->assertSame( 'I painted this at sunrise.', $result['description'] );
		$this->assertSame( 'webhook', $result['source'] );
		$this->assertCount( 1, $result['attachments'] );
	}

	public function test_parse_webhook_skips_disallowed_mime_types(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'agnosis_test_' );
		file_put_contents( $tmp, 'fake pdf content' );

		$_FILES['attachment-1'] = [
			'name'     => 'document.pdf',
			'type'     => 'application/pdf', // not allowed
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => 16,
		];

		$result = $this->parser->parse_webhook_payload( [
			'sender'           => 'a@b.com',
			'subject'          => 'Doc',
			'attachment-count' => 1,
		] );

		unlink( $tmp );
		unset( $_FILES['attachment-1'] );

		$this->assertNull( $result ); // No valid image → null
	}

	/**
	 * HEIC/HEIF (the default iPhone photo format) must be accepted at intake
	 * rather than silently dropped — MediaAdapter::adapt_heic() converts it to
	 * JPEG later, before it reaches the AI vision call or gets published, but
	 * only if it survives this gate in the first place.
	 */
	public function test_parse_webhook_accepts_heic_attachments(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'agnosis_test_' );
		file_put_contents( $tmp, 'fake heic content' );

		$_FILES['attachment-1'] = [
			'name'     => 'photo.heic',
			'type'     => 'image/heic',
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => 18,
		];

		$result = $this->parser->parse_webhook_payload( [
			'sender'           => 'artist@example.com',
			'subject'          => 'iPhone photo',
			'attachment-count' => 1,
		] );

		unlink( $tmp );
		unset( $_FILES['attachment-1'] );

		$this->assertIsArray( $result, 'A HEIC attachment must no longer be silently dropped at intake.' );
		$this->assertCount( 1, $result['attachments'] );
		$this->assertSame( 'image/heic', $result['attachments'][0]['mime'] );
	}

	public function test_parse_webhook_accepts_heif_attachments(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'agnosis_test_' );
		file_put_contents( $tmp, 'fake heif content' );

		$_FILES['attachment-1'] = [
			'name'     => 'photo.heif',
			'type'     => 'image/heif',
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => 18,
		];

		$result = $this->parser->parse_webhook_payload( [
			'sender'           => 'artist@example.com',
			'subject'          => 'iPhone photo',
			'attachment-count' => 1,
		] );

		unlink( $tmp );
		unset( $_FILES['attachment-1'] );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['attachments'] );
	}

	public function test_parse_webhook_skips_oversized_attachments(): void {
		$tmp  = tempnam( sys_get_temp_dir(), 'agnosis_test_' );
		$data = str_repeat( 'x', 100 ); // tiny file on disk, but we lie about size
		file_put_contents( $tmp, $data );

		$twenty_one_mb = 21 * 1024 * 1024;

		$_FILES['attachment-1'] = [
			'name'     => 'huge.jpg',
			'type'     => 'image/jpeg',
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => $twenty_one_mb, // over the 20 MB limit
		];

		$result = $this->parser->parse_webhook_payload( [
			'sender'           => 'a@b.com',
			'subject'          => 'Big file',
			'attachment-count' => 1,
		] );

		unlink( $tmp );
		unset( $_FILES['attachment-1'] );

		$this->assertNull( $result );
	}

	public function test_parse_webhook_falls_back_to_from_field(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'agnosis_test_' );
		file_put_contents( $tmp, 'img' );

		$_FILES['attachment-1'] = [
			'name'     => 'art.png',
			'type'     => 'image/png',
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => 3,
		];

		$result = $this->parser->parse_webhook_payload( [
			// 'sender' absent — should fall back to 'from'
			'from'             => 'fallback@example.com',
			'subject'          => 'Test',
			'text'             => 'Body text.',
			'attachment-count' => 1,
		] );

		unlink( $tmp );
		unset( $_FILES['attachment-1'] );

		$this->assertIsArray( $result );
		$this->assertSame( 'fallback@example.com', $result['from'] );
		$this->assertSame( 'Body text.', $result['description'] );
	}

	// -------------------------------------------------------------------------
	// parse_webhook_payload() — attachment MIME sniffing (sixth audit §3e)
	//
	// Previously the webhook path trusted $_FILES[...]['type'] outright — a
	// value that's entirely sender-declared, with no byte-level check at
	// all — while the IMAP path (parse_imap_message(), above) always
	// fileinfo-sniffs the real bytes first, falling back to the declared
	// Content-Type only when the sniff itself doesn't recognize anything on
	// the allow-list. These tests exercise that same sniff-then-declared-
	// fallback order on the webhook path, now that both transports share it.
	// -------------------------------------------------------------------------

	/** A minimal (67-byte) but genuinely valid 1x1 PNG — real bytes finfo reliably sniffs as image/png. */
	private const REAL_PNG_BYTES = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\xff\xff?\x00\x05\xfe\x02\xfe\xdc\xccY\xe7\x00\x00\x00\x00IEND\xaeB`\x82";

	public function test_webhook_attachment_is_accepted_via_sniff_when_declared_type_is_not_allowed(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'agnosis_test_' );
		file_put_contents( $tmp, self::REAL_PNG_BYTES );

		$_FILES['attachment-1'] = [
			'name'     => 'photo',
			'type'     => 'application/octet-stream', // not on the allow-list at all
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( self::REAL_PNG_BYTES ),
		];

		$result = $this->parser->parse_webhook_payload( [
			'sender'           => 'artist@example.com',
			'subject'          => 'Real PNG, generic declared type',
			'attachment-count' => 1,
		] );

		unlink( $tmp );
		unset( $_FILES['attachment-1'] );

		$this->assertIsArray( $result, 'Genuinely valid image bytes must be accepted via sniffing even when the declared type is not on the allow-list at all — the old code would have rejected this outright.' );
		$this->assertSame( 'image/png', $result['attachments'][0]['mime'], 'The sniffed type, not the useless declared type, must be recorded.' );
	}

	public function test_webhook_attachment_falls_back_to_declared_type_when_sniff_is_not_allowed(): void {
		// Mirrors parse_imap_message()'s own fallback: real bytes that don't
		// sniff as anything on the allow-list (plain text here) still get
		// accepted if the declared Content-Type is itself an allowed image
		// type — documents the same back-compat this method has always had
		// for a sender whose upload doesn't sniff cleanly.
		$tmp = tempnam( sys_get_temp_dir(), 'agnosis_test_' );
		file_put_contents( $tmp, 'not actually image bytes at all' );

		$_FILES['attachment-1'] = [
			'name'     => 'artwork.jpg',
			'type'     => 'image/jpeg',
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => 32,
		];

		$result = $this->parser->parse_webhook_payload( [
			'sender'           => 'artist@example.com',
			'subject'          => 'Unsniffable body, allowed declared type',
			'attachment-count' => 1,
		] );

		unlink( $tmp );
		unset( $_FILES['attachment-1'] );

		$this->assertIsArray( $result, 'An allowed declared type must still be trusted as a fallback when the actual bytes fail to sniff as anything recognized — matching the IMAP path\'s own convention.' );
		$this->assertSame( 'image/jpeg', $result['attachments'][0]['mime'] );
	}

	public function test_webhook_attachment_is_rejected_when_neither_sniff_nor_declared_type_is_allowed(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'agnosis_test_' );
		file_put_contents( $tmp, '%PDF-1.4 fake pdf bytes' );

		$_FILES['attachment-1'] = [
			'name'     => 'document.pdf',
			'type'     => 'application/pdf',
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => 23,
		];

		$result = $this->parser->parse_webhook_payload( [
			'sender'           => 'a@b.com',
			'subject'          => 'Doc',
			'attachment-count' => 1,
		] );

		unlink( $tmp );
		unset( $_FILES['attachment-1'] );

		$this->assertNull( $result );
	}

	// -------------------------------------------------------------------------
	// parse_webhook_payload() — to_addresses collection (fifth audit §5a)
	//
	// Previously untested at all: no test in this file ever asserted on the
	// 'to_addresses' key, only 'from'/'subject'/'description'/'attachments'.
	// -------------------------------------------------------------------------

	private function stage_webhook_attachment(): string {
		$tmp = tempnam( sys_get_temp_dir(), 'agnosis_test_' );
		file_put_contents( $tmp, 'img' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$_FILES['attachment-1'] = [
			'name'     => 'art.png',
			'type'     => 'image/png',
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => 3,
		];
		return $tmp;
	}

	private function clear_webhook_attachment( string $tmp ): void {
		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
		unset( $_FILES['attachment-1'] );
	}

	public function test_parse_webhook_collects_recipient_field_into_to_addresses(): void {
		$tmp = $this->stage_webhook_attachment();

		$result = $this->parser->parse_webhook_payload( [
			'sender'           => 'artist@example.com',
			'recipient'        => 'remove@example.com',
			'attachment-count' => 1,
		] );

		$this->clear_webhook_attachment( $tmp );

		$this->assertIsArray( $result );
		$this->assertSame( 'remove@example.com', $result['to_address'] );
		$this->assertSame( [ 'remove@example.com' ], $result['to_addresses'] );
	}

	/**
	 * 2026-07-15: reverses the "fifth audit §5a" broadening this test used to
	 * cover — Cc: is no longer read at all (no CC accepted as a routing
	 * signal, full stop), so a management alias reachable only via Cc: is now
	 * correctly absent from to_addresses, not "not lost."
	 */
	public function test_parse_webhook_ignores_cc_entirely(): void {
		$tmp = $this->stage_webhook_attachment();

		$result = $this->parser->parse_webhook_payload( [
			'sender'           => 'artist@example.com',
			'recipient'        => 'friend@example.com',
			'cc'               => 'remove@example.com',
			'attachment-count' => 1,
		] );

		$this->clear_webhook_attachment( $tmp );

		$this->assertIsArray( $result );
		$this->assertSame( 'friend@example.com', $result['to_address'] );
		$this->assertSame( [ 'friend@example.com' ], $result['to_addresses'] );
		$this->assertNotContains( 'remove@example.com', $result['to_addresses'] );
	}

	/**
	 * 2026-07-15: only the FIRST address in a multi-recipient 'To' header
	 * counts now — a secondary recipient is no longer collected, even without
	 * a Mailgun 'recipient' field to defer to.
	 */
	public function test_parse_webhook_to_header_takes_only_the_first_address(): void {
		$tmp = $this->stage_webhook_attachment();

		$result = $this->parser->parse_webhook_payload( [
			'sender'           => 'artist@example.com',
			'To'               => 'Friend Name <friend@example.com>, Gallery <remove@example.com>',
			'attachment-count' => 1,
		] );

		$this->clear_webhook_attachment( $tmp );

		$this->assertIsArray( $result );
		$this->assertSame( [ 'friend@example.com' ], $result['to_addresses'] );
	}

	public function test_parse_webhook_to_addresses_is_empty_when_no_recipient_fields_present(): void {
		$tmp = $this->stage_webhook_attachment();

		$result = $this->parser->parse_webhook_payload( [
			'sender'           => 'artist@example.com',
			'attachment-count' => 1,
		] );

		$this->clear_webhook_attachment( $tmp );

		$this->assertIsArray( $result );
		$this->assertSame( [], $result['to_addresses'] );
	}

	// -------------------------------------------------------------------------
	// clean_text (private — tested via reflection)
	// -------------------------------------------------------------------------

	private function clean_text( string $text ): string {
		$ref = new \ReflectionMethod( Parser::class, 'clean_text' );
		$ref->setAccessible( true );
		return $ref->invoke( $this->parser, $text );
	}

	public function test_clean_text_strips_email_signature(): void {
		$text = "Here is my artwork.\n--\nSent from my phone.";

		$result = $this->clean_text( $text );

		$this->assertStringNotContainsString( 'Sent from my phone', $result );
		$this->assertStringContainsString( 'Here is my artwork', $result );
	}

	public function test_clean_text_trims_whitespace(): void {
		$result = $this->clean_text( '   some text   ' );

		$this->assertSame( 'some text', $result );
	}

	public function test_clean_text_handles_empty_string(): void {
		$this->assertSame( '', $this->clean_text( '' ) );
	}

	// -------------------------------------------------------------------------
	// parse_imap_message() — to_addresses collection (fifth audit §5a)
	//
	// Previously PostCreator::resolve_post_type() only ever saw a single
	// 'to_address' from the IMAP path too — this exercises
	// parse_imap_message()'s own collection of every To:/Cc: recipient into
	// 'to_addresses', mirroring the webhook path's IntakeGates::recipient_addresses()
	// (sixth audit §6 — this parser moved there from a private method on this class).
	// No test anywhere previously called parse_imap_message() at all.
	// -------------------------------------------------------------------------

	private function make_attachment(): FakeImapAttachment {
		return new FakeImapAttachment( 'image/jpeg', 'artwork.jpg', str_repeat( 'x', 50 ) );
	}

	public function test_parse_imap_collects_a_single_to_address(): void {
		$message = new FakeParserImapMessage(
			from_email: 'artist@example.com',
			to_emails: [ 'remove@example.com' ],
			fake_attachments: [ $this->make_attachment() ]
		);

		$result = $this->parser->parse_imap_message( $message );

		$this->assertIsArray( $result );
		$this->assertSame( 'remove@example.com', $result['to_address'] );
		$this->assertSame( [ 'remove@example.com' ], $result['to_addresses'] );
	}

	/**
	 * 2026-07-15: reverses the "fifth audit §5a" broadening this test used to
	 * cover — Cc: is no longer read at all (no CC accepted as a routing
	 * signal, full stop), so a management alias reachable only via Cc: is now
	 * correctly absent from to_addresses, not "not lost."
	 */
	public function test_parse_imap_ignores_cc_entirely(): void {
		$message = new FakeParserImapMessage(
			to_emails: [ 'friend@example.com' ],
			cc_emails: [ 'remove@example.com' ],
			fake_attachments: [ $this->make_attachment() ]
		);

		$result = $this->parser->parse_imap_message( $message );

		$this->assertIsArray( $result );
		$this->assertSame( 'friend@example.com', $result['to_address'] );
		$this->assertSame( [ 'friend@example.com' ], $result['to_addresses'] );
		$this->assertNotContains( 'remove@example.com', $result['to_addresses'] );
	}

	public function test_parse_imap_lowercases_to_address(): void {
		$message = new FakeParserImapMessage(
			to_emails: [ 'Remove@Example.com' ],
			fake_attachments: [ $this->make_attachment() ]
		);

		$result = $this->parser->parse_imap_message( $message );

		$this->assertSame( [ 'remove@example.com' ], $result['to_addresses'] );
	}

	/**
	 * getCc() is no longer called at all by parse_imap_message() (2026-07-15
	 * — Cc: is never read), so a message double that doesn't support it must
	 * not affect the parse in any way; this is now really just confirming
	 * to_addresses is unaffected by Cc: support one way or the other.
	 */
	public function test_parse_imap_unaffected_by_a_message_double_without_getCc(): void {
		$message = new FakeParserImapMessage(
			to_emails: [ 'remove@example.com' ],
			fake_attachments: [ $this->make_attachment() ],
			cc_unsupported: true
		);

		$result = $this->parser->parse_imap_message( $message );

		$this->assertIsArray( $result );
		$this->assertSame( [ 'remove@example.com' ], $result['to_addresses'] );
	}

	public function test_parse_imap_to_addresses_is_empty_when_no_recipients_at_all(): void {
		$message = new FakeParserImapMessage(
			to_emails: [],
			cc_emails: [],
			fake_attachments: [ $this->make_attachment() ]
		);

		$result = $this->parser->parse_imap_message( $message );

		$this->assertIsArray( $result );
		$this->assertSame( '', $result['to_address'] );
		$this->assertSame( [], $result['to_addresses'] );
	}

	/**
	 * Poetry is art too, and a biography can be text-only — a message with no
	 * attachment is no longer automatically rejected as long as it carries
	 * real text. FakeParserImapMessage's default text_body ('Test body.') is
	 * non-empty, so this is exactly that shape.
	 */
	public function test_parse_imap_accepts_a_text_only_message_with_no_attachments(): void {
		$message = new FakeParserImapMessage(
			to_emails: [ 'remove@example.com' ],
			fake_attachments: [] // No attachments at all.
		);

		$result = $this->parser->parse_imap_message( $message );

		$this->assertIsArray( $result );
		$this->assertSame( 'Test body.', $result['description'] );
		$this->assertSame( [], $result['attachments'] );
	}

	public function test_parse_imap_returns_null_without_attachments_or_text_when_recipient_is_not_a_configured_management_address(): void {
		// 2026-07-14: recipient DOES matter now (see the exemption tests
		// below) — but only once agnosis_email_remove/agnosis_email_promote
		// is actually configured to that address. An unconfigured site (the
		// default — WebhookSignatureTest::$options is empty here, so
		// get_option('agnosis_email_remove', '') resolves to '') has no
		// management address for 'remove@example.com' to match, so an
		// attachment-and-text-less message to it is still nothing usable.
		$message = new FakeParserImapMessage(
			to_emails: [ 'remove@example.com' ],
			text_body: '',
			fake_attachments: [] // No attachments and no text — nothing usable at all.
		);

		$result = $this->parser->parse_imap_message( $message );

		$this->assertNull( $result );
	}

	/**
	 * 2026-07-14 fix: a remove@/promote@ request identifies its target purely
	 * by subject line — no attachment, and often no body text either (a
	 * quick "delete this" email is commonly sent with an empty body). Before
	 * this fix, such a message was rejected right here as "nothing usable",
	 * before PostCreator ever got a chance to route it to
	 * handle_removal_request() — surfacing as a false "no valid image,
	 * audio, or video attachment" skip in the Inbox admin table for a
	 * request that was never supposed to need one.
	 */
	public function test_parse_imap_accepts_an_empty_body_message_to_a_configured_remove_address(): void {
		WebhookSignatureTest::$options['agnosis_email_remove'] = 'remove@example.com';

		$message = new FakeParserImapMessage(
			to_emails: [ 'remove@example.com' ],
			subject_text: 'UID 51',
			text_body: '',
			fake_attachments: []
		);

		$result = $this->parser->parse_imap_message( $message );

		$this->assertIsArray( $result );
		$this->assertSame( 'UID 51', $result['subject'] );
		$this->assertSame( '', $result['description'] );
		$this->assertSame( [], $result['attachments'] );
	}

	/** Same exemption, for promote@ — mirrors the remove@ test above exactly. */
	public function test_parse_imap_accepts_an_empty_body_message_to_a_configured_promote_address(): void {
		WebhookSignatureTest::$options['agnosis_email_promote'] = 'promote@example.com';

		$message = new FakeParserImapMessage(
			to_emails: [ 'promote@example.com' ],
			subject_text: 'Golden Hour',
			text_body: '',
			fake_attachments: []
		);

		$result = $this->parser->parse_imap_message( $message );

		$this->assertIsArray( $result );
		$this->assertSame( 'Golden Hour', $result['subject'] );
	}

	/**
	 * The exemption is subject-gated too — an empty subject gives
	 * PostCreator::handle_removal_request() nothing to identify a post by
	 * regardless, so this must still return null rather than enqueue a
	 * request nobody could ever act on.
	 */
	public function test_parse_imap_still_rejects_empty_subject_even_to_a_configured_remove_address(): void {
		WebhookSignatureTest::$options['agnosis_email_remove'] = 'remove@example.com';

		$message = new FakeParserImapMessage(
			to_emails: [ 'remove@example.com' ],
			subject_text: '',
			text_body: '',
			fake_attachments: []
		);

		$result = $this->parser->parse_imap_message( $message );

		$this->assertNull( $result );
	}

	// -------------------------------------------------------------------------
	// parse_imap_message() — HTML-only fallback (audit §6a, AUDIT-1.0.0.md)
	//
	// Real senders (webmail "rich text" modes, several mobile mail clients,
	// most marketing/newsletter composers an artist might paste from) send
	// HTML-only messages with no text/plain alternative at all. Before this
	// fix, getTextBody() returning '' meant the artist's own description
	// text silently vanished (a photo attachment still published, but a
	// text-only HTML message — a poem, a biography — dead-ended at the
	// "nothing usable" gate as if genuinely empty).
	// -------------------------------------------------------------------------

	/** The audit's own explicitly requested test: an HTML-only fixture must yield the same submission a plain-text twin does. */
	public function test_parse_imap_html_only_message_matches_a_plain_text_twin(): void {
		$plain_message = new FakeParserImapMessage(
			to_emails: [ 'submit@example.com' ],
			text_body: "Autumn Light\n\nA study of falling leaves, painted last week.",
			fake_attachments: [ $this->make_attachment() ]
		);
		$html_message = new FakeParserImapMessage(
			to_emails: [ 'submit@example.com' ],
			text_body: '',
			html_body: '<p>Autumn Light</p><p>A study of falling leaves, painted last week.</p>',
			fake_attachments: [ $this->make_attachment() ]
		);

		$plain_result = $this->parser->parse_imap_message( $plain_message );
		$html_result  = $this->parser->parse_imap_message( $html_message );

		$this->assertIsArray( $plain_result );
		$this->assertIsArray( $html_result );
		$this->assertSame( $plain_result['description'], $html_result['description'], 'An HTML-only message must derive the same description as its plain-text twin.' );
	}

	public function test_parse_imap_derives_text_from_html_when_text_body_is_empty(): void {
		$message = new FakeParserImapMessage(
			to_emails: [ 'remove@example.com' ], // Attachment-optional recipient — see the text-only test above for why.
			text_body: '',
			html_body: '<p>Hello there, this is my new painting.</p>',
			fake_attachments: []
		);

		$result = $this->parser->parse_imap_message( $message );

		$this->assertIsArray( $result );
		$this->assertSame( 'Hello there, this is my new painting.', $result['description'] );
	}

	/**
	 * <script>/<style> tags DO arrive in real HTML mail (Postie's own 1.4.10
	 * changelog entry is the origin of this exact lesson) — a naive
	 * "just strip tags" pass alone would leave raw JS/CSS source sitting in
	 * the published description as if it were the artist's own words.
	 */
	public function test_parse_imap_html_fallback_strips_script_and_style_content(): void {
		$message = new FakeParserImapMessage(
			to_emails: [ 'remove@example.com' ],
			text_body: '',
			html_body: '<style>body{color:red}</style><script>alert(1)</script><p>Real content here.</p>',
			fake_attachments: []
		);

		$result = $this->parser->parse_imap_message( $message );

		$this->assertIsArray( $result );
		$this->assertSame( 'Real content here.', $result['description'] );
		$this->assertStringNotContainsString( 'alert(1)', $result['description'] );
		$this->assertStringNotContainsString( 'color:red', $result['description'] );
	}

	/** HTML entities must decode to their real characters, not survive as literal entity text. */
	public function test_parse_imap_html_fallback_decodes_entities(): void {
		$message = new FakeParserImapMessage(
			to_emails: [ 'remove@example.com' ],
			text_body: '',
			html_body: '<p>It&#8217;s called &#8220;Autumn Light&#8221;.</p>',
			fake_attachments: []
		);

		$result = $this->parser->parse_imap_message( $message );

		$this->assertIsArray( $result );
		$this->assertSame( 'It’s called “Autumn Light”.', $result['description'] );
	}

	/** A genuine text/plain body always wins — the HTML fallback only ever engages when getTextBody() is empty. */
	public function test_parse_imap_ignores_html_body_when_text_body_is_present(): void {
		$message = new FakeParserImapMessage(
			to_emails: [ 'remove@example.com' ],
			text_body: 'The real plain-text description.',
			html_body: '<p>A completely different HTML description that must be ignored.</p>',
			fake_attachments: []
		);

		$result = $this->parser->parse_imap_message( $message );

		$this->assertIsArray( $result );
		$this->assertSame( 'The real plain-text description.', $result['description'] );
	}

	/** Both bodies empty must still hit the ordinary "nothing usable" gate — the fallback is not itself a new bypass. */
	public function test_parse_imap_returns_null_when_both_text_and_html_bodies_are_empty(): void {
		$message = new FakeParserImapMessage(
			to_emails: [ 'remove@example.com' ], // Unconfigured — see the equivalent plain-text test above.
			text_body: '',
			html_body: '',
			fake_attachments: []
		);

		$result = $this->parser->parse_imap_message( $message );

		$this->assertNull( $result );
	}

	// -------------------------------------------------------------------------
	// parse_imap_message() / parse_webhook_payload() — reply/forward
	// extraction (2026-07-15; extraction widening 2026-07-14 — eighth audit
	// §3c) and last_rejection_reason()
	//
	// A "Re:"/"Fwd:" subject or a quoted body no longer rejects on its own —
	// IntakeGates::extract_original_content() is tried first, and only a
	// message that extracts to NOTHING (no original text, no attachment)
	// still ends up rejected as 'looks_like_reply'. See
	// IntakeGates::extract_original_content()'s own docblock and
	// IntakeGatesTest for the extraction algorithm's own unit coverage —
	// these tests cover Parser's end-to-end wiring of it.
	// -------------------------------------------------------------------------

	public function test_parse_imap_accepts_a_re_prefixed_subject_with_a_genuine_attachment(): void {
		// The attachment alone is "something to process" — extraction
		// doesn't even need real body text here, matching the audit's own
		// framing ("original text ABOVE the attribution line, OR a genuine
		// attachment").
		$message = new FakeParserImapMessage(
			to_emails: [ 'remove@example.com' ],
			subject_text: 'Re: My new painting',
			fake_attachments: [ $this->make_attachment() ]
		);

		$result = $this->parser->parse_imap_message( $message );

		$this->assertIsArray( $result );
		$this->assertSame( 'My new painting', $result['subject'], 'The "Re:" prefix must be stripped from the subject.' );
		$this->assertTrue( $result['extracted_from_reply'] );
		$this->assertSame( '', $this->parser->last_rejection_reason() );
	}

	public function test_parse_imap_extracts_the_senders_own_comment_above_a_quoted_reply(): void {
		$message = new FakeParserImapMessage(
			to_emails: [ 'remove@example.com' ],
			subject_text: 'My new painting',
			text_body: "Here you go again.\n\nOn 13 Jul 2026, at 18:57, Agnosis <submit@agnosis.art> wrote:\n\n> Previous content.",
			fake_attachments: [ $this->make_attachment() ]
		);

		$result = $this->parser->parse_imap_message( $message );

		$this->assertIsArray( $result );
		$this->assertSame( 'Here you go again.', $result['description'] );
		$this->assertTrue( $result['extracted_from_reply'] );
		$this->assertSame( '', $this->parser->last_rejection_reason() );
	}

	public function test_parse_imap_rejects_a_reply_with_nothing_above_the_quote_and_no_attachment(): void {
		// The one case that still rejects: extraction genuinely finds
		// nothing — no comment above the quote, no attachment either.
		$message = new FakeParserImapMessage(
			to_emails: [ 'remove@example.com' ],
			subject_text: 'Re: My new painting',
			text_body: "On 13 Jul 2026, at 18:57, Agnosis <submit@agnosis.art> wrote:\n\n> Previous content.",
			fake_attachments: []
		);

		$result = $this->parser->parse_imap_message( $message );

		$this->assertNull( $result );
		$this->assertSame( 'looks_like_reply', $this->parser->last_rejection_reason() );
	}

	public function test_parse_imap_does_not_flag_extracted_from_reply_for_a_genuine_submission(): void {
		$message = new FakeParserImapMessage(
			to_emails: [ 'remove@example.com' ],
			subject_text: 'My new painting',
			fake_attachments: [ $this->make_attachment() ]
		);

		$result = $this->parser->parse_imap_message( $message );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['extracted_from_reply'] );
	}

	public function test_parse_imap_last_rejection_reason_is_empty_for_a_genuine_accepted_submission(): void {
		$message = new FakeParserImapMessage(
			to_emails: [ 'remove@example.com' ],
			subject_text: 'My new painting',
			fake_attachments: [ $this->make_attachment() ]
		);

		$result = $this->parser->parse_imap_message( $message );

		$this->assertIsArray( $result );
		$this->assertSame( '', $this->parser->last_rejection_reason() );
	}

	public function test_parse_imap_last_rejection_reason_resets_between_calls(): void {
		$reply_message = new FakeParserImapMessage(
			to_emails: [ 'remove@example.com' ],
			subject_text: 'Re: My new painting',
			text_body: "On 13 Jul 2026, at 18:57, Agnosis <submit@agnosis.art> wrote:\n\n> Previous content.",
			fake_attachments: []
		);
		$this->parser->parse_imap_message( $reply_message );
		$this->assertSame( 'looks_like_reply', $this->parser->last_rejection_reason() );

		$genuine_message = new FakeParserImapMessage(
			to_emails: [ 'remove@example.com' ],
			subject_text: 'My new painting',
			fake_attachments: [ $this->make_attachment() ]
		);
		$this->parser->parse_imap_message( $genuine_message );
		$this->assertSame( '', $this->parser->last_rejection_reason(), 'Stale reason from a previous call must not leak into a later, genuine submission.' );
	}

	public function test_parse_webhook_accepts_a_re_prefixed_subject_with_real_body_text(): void {
		$payload = [
			'sender'        => 'artist@example.com',
			'subject'       => 'Re: My new painting',
			'stripped-text' => 'Here is my artwork.',
		];

		$result = $this->parser->parse_webhook_payload( $payload );

		$this->assertIsArray( $result );
		$this->assertSame( 'My new painting', $result['subject'] );
		$this->assertSame( 'Here is my artwork.', $result['description'] );
		$this->assertTrue( $result['extracted_from_reply'] );
		$this->assertSame( '', $this->parser->last_rejection_reason() );
	}

	public function test_parse_webhook_extracts_an_agnosis_bracketed_subject_with_real_body_text(): void {
		$payload = [
			'sender'        => 'artist@example.com',
			'subject'       => 'Fwd: [Agnosis] Your submission was received',
			'stripped-text' => 'Here is my artwork.',
		];

		$result = $this->parser->parse_webhook_payload( $payload );

		$this->assertIsArray( $result );
		$this->assertSame( 'Your submission was received', $result['subject'], '"Fwd:" and "[Agnosis]" must both be stripped, leaving just the remaining text.' );
		$this->assertTrue( $result['extracted_from_reply'] );
	}

	public function test_parse_webhook_extracts_the_senders_own_comment_above_an_outlook_original_message_body(): void {
		$payload = [
			'sender'        => 'artist@example.com',
			'subject'       => 'My new painting',
			'stripped-text' => "See attached.\n\n-----Original Message-----\nFrom: someone@example.com",
		];

		$result = $this->parser->parse_webhook_payload( $payload );

		$this->assertIsArray( $result );
		$this->assertSame( 'See attached.', $result['description'] );
		$this->assertTrue( $result['extracted_from_reply'] );
		$this->assertSame( '', $this->parser->last_rejection_reason() );
	}

	public function test_parse_webhook_rejects_a_reply_with_nothing_above_the_quote_and_no_attachment(): void {
		$payload = [
			'sender'        => 'artist@example.com',
			'subject'       => 'Re: My new painting',
			'stripped-text' => "-----Original Message-----\nFrom: someone@example.com",
		];

		$result = $this->parser->parse_webhook_payload( $payload );

		$this->assertNull( $result );
		$this->assertSame( 'looks_like_reply', $this->parser->last_rejection_reason() );
	}

	public function test_parse_webhook_last_rejection_reason_is_empty_for_a_genuine_accepted_submission(): void {
		$payload = [
			'sender'        => 'artist@example.com',
			'subject'       => 'My new painting',
			'stripped-text' => 'Here is my artwork.',
		];

		$result = $this->parser->parse_webhook_payload( $payload );

		$this->assertIsArray( $result );
		$this->assertSame( '', $this->parser->last_rejection_reason() );
		$this->assertFalse( $result['extracted_from_reply'] );
	}
}
