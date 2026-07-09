<?php
/**
 * Unit tests for the Email Parser.
 *
 * @package Agnosis\Tests\Unit\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\Email;

use Agnosis\Email\Parser;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeImapAttachment.php';
require_once __DIR__ . '/FakeParserImapMessage.php';

class ParserTest extends TestCase {

	private Parser $parser;

	protected function setUp(): void {
		$this->parser = new Parser();
	}

	// -------------------------------------------------------------------------
	// parse_webhook_payload
	// -------------------------------------------------------------------------

	public function test_parse_webhook_returns_null_when_no_attachments(): void {
		$payload = [
			'sender'           => 'artist@example.com',
			'subject'          => 'My new painting',
			'stripped-text'    => 'Here is my artwork.',
			'attachment-count' => 0,
		];

		$result = $this->parser->parse_webhook_payload( $payload );

		$this->assertNull( $result );
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

	public function test_parse_webhook_collects_recipient_and_cc_together(): void {
		$tmp = $this->stage_webhook_attachment();

		$result = $this->parser->parse_webhook_payload( [
			'sender'           => 'artist@example.com',
			'recipient'        => 'friend@example.com',
			'cc'               => 'remove@example.com',
			'attachment-count' => 1,
		] );

		$this->clear_webhook_attachment( $tmp );

		$this->assertIsArray( $result );
		// to_address (primary routing signal) is still just 'recipient'.
		$this->assertSame( 'friend@example.com', $result['to_address'] );
		// to_addresses carries both — the CC'd management alias is not lost.
		$this->assertContains( 'remove@example.com', $result['to_addresses'] );
		$this->assertContains( 'friend@example.com', $result['to_addresses'] );
	}

	public function test_parse_webhook_extracts_addresses_from_a_multi_recipient_to_header(): void {
		$tmp = $this->stage_webhook_attachment();

		$result = $this->parser->parse_webhook_payload( [
			'sender'           => 'artist@example.com',
			'recipient'        => 'friend@example.com',
			'To'               => 'Friend Name <friend@example.com>, Gallery <remove@example.com>',
			'attachment-count' => 1,
		] );

		$this->clear_webhook_attachment( $tmp );

		$this->assertIsArray( $result );
		$this->assertContains( 'friend@example.com', $result['to_addresses'] );
		$this->assertContains( 'remove@example.com', $result['to_addresses'], 'Extract every bare address out of a "Name <addr>, Name2 <addr2>" To: header, not just the Mailgun "recipient" field.' );
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
	// 'to_addresses', mirroring the webhook path's extract_recipient_addresses().
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

	public function test_parse_imap_collects_to_and_cc_addresses_together(): void {
		$message = new FakeParserImapMessage(
			to_emails: [ 'friend@example.com' ],
			cc_emails: [ 'remove@example.com' ],
			fake_attachments: [ $this->make_attachment() ]
		);

		$result = $this->parser->parse_imap_message( $message );

		$this->assertIsArray( $result );
		// to_address (primary routing signal) is still just the first To:.
		$this->assertSame( 'friend@example.com', $result['to_address'] );
		// to_addresses carries BOTH — the CC'd management alias is not lost.
		$this->assertContains( 'remove@example.com', $result['to_addresses'] );
		$this->assertContains( 'friend@example.com', $result['to_addresses'] );
		$this->assertCount( 2, $result['to_addresses'] );
	}

	public function test_parse_imap_lowercases_and_dedupes_to_addresses(): void {
		$message = new FakeParserImapMessage(
			to_emails: [ 'Remove@Example.com' ],
			cc_emails: [ 'remove@example.com' ], // Same address, different case, also CC'd.
			fake_attachments: [ $this->make_attachment() ]
		);

		$result = $this->parser->parse_imap_message( $message );

		$this->assertSame( [ 'remove@example.com' ], $result['to_addresses'] );
	}

	public function test_parse_imap_to_addresses_survives_a_message_double_without_getCc(): void {
		// Some test fakes (and, per the source docblock, possibly some real
		// message states) don't support getCc() at all — the try/catch around
		// it must still leave the To: addresses collected, not blow up the
		// whole parse.
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

	public function test_parse_imap_returns_null_without_valid_attachments_regardless_of_recipients(): void {
		$message = new FakeParserImapMessage(
			to_emails: [ 'remove@example.com' ],
			fake_attachments: [] // No attachments at all.
		);

		$result = $this->parser->parse_imap_message( $message );

		$this->assertNull( $result );
	}
}
