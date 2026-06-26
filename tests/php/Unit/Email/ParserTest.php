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
	// decode_body (private — tested via reflection)
	// -------------------------------------------------------------------------

	private function decode_body( string $raw, int $encoding ): string {
		$ref = new \ReflectionMethod( Parser::class, 'decode_body' );
		$ref->setAccessible( true );
		return $ref->invoke( $this->parser, $raw, $encoding );
	}

	public function test_decode_body_base64_returns_decoded_string(): void {
		$original = 'Hello, artist!';
		$encoded  = base64_encode( $original );

		$this->assertSame( $original, $this->decode_body( $encoded, 3 ) );
	}

	public function test_decode_body_quoted_printable_returns_decoded_string(): void {
		$original = "Café au lait";
		$encoded  = quoted_printable_encode( $original );

		$this->assertSame( $original, $this->decode_body( $encoded, 4 ) );
	}

	public function test_decode_body_plain_returns_raw(): void {
		$raw = "Plain text, no encoding.";

		$this->assertSame( $raw, $this->decode_body( $raw, 0 ) );
		$this->assertSame( $raw, $this->decode_body( $raw, 7 ) ); // unknown encoding → raw
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
		$result = $this->clean_text( "   some text   " );

		$this->assertSame( 'some text', $result );
	}

	public function test_clean_text_handles_empty_string(): void {
		$this->assertSame( '', $this->clean_text( '' ) );
	}
}
