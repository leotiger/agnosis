<?php
/**
 * Unit tests for PostCreator::build_post_content().
 *
 * Verifies that biography and event posts use the artist's own written text
 * as the body (not the AI image description), and that artwork posts continue
 * to use the AI description body.
 *
 * build_post_content() is private and is exercised via ReflectionMethod.
 * A minimal Pipeline stub is injected so no options or AI calls are needed.
 *
 * @package Agnosis\Tests\Unit\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\Publishing;

use Agnosis\AI\Pipeline;
use Agnosis\Publishing\PostCreator;
use PHPUnit\Framework\TestCase;

class PostContentBuildTest extends TestCase {

	private PostCreator $creator;
	private \ReflectionMethod $method;

	protected function setUp(): void {
		$pipeline = new class() extends Pipeline {
			public function __construct() {}
			/** @param array<string, mixed> $s */
			public function process( array $s, bool $skip_enhancement = false ): array {
				return []; }
		};

		$this->creator = new PostCreator( $pipeline );

		$this->method = new \ReflectionMethod( PostCreator::class, 'build_post_content' );
		$this->method->setAccessible( true );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $primary
	 * @param int[]                $gallery
	 */
	private function build( array $primary, array $gallery, string $post_type = 'agnosis_artwork', string $artist_text = '' ): string {
		return $this->method->invoke( $this->creator, $primary, $gallery, $post_type, $artist_text );
	}

	// -------------------------------------------------------------------------
	// Artwork — AI description body used
	// -------------------------------------------------------------------------

	public function test_artwork_uses_ai_description_body(): void {
		$primary = [ 'body' => '<p>AI wrote this.</p>' ];
		$result  = $this->build( $primary, [], 'agnosis_artwork', 'Artist wrote this.' );

		$this->assertStringContainsString( 'AI wrote this.', $result );
		$this->assertStringNotContainsString( 'Artist wrote this.', $result );
	}

	public function test_artwork_with_no_ai_body_falls_back_to_artist_text(): void {
		// 2026-07-24 fix: artwork used to return '' outright whenever the AI
		// description failed/came back empty — a real submission then shipped
		// with a blank "Full text" on the review page, even though the artist's
		// own words were right there in the email. Biography/event already
		// fell back to the artist's own text in this situation; artwork alone
		// didn't. Now it does too, same wpautop()+wp_kses_post() treatment.
		$result = $this->build( [], [], 'agnosis_artwork', 'Artist note.' );

		$this->assertStringContainsString( 'Artist note.', $result );
	}

	public function test_artwork_with_no_ai_body_and_no_artist_text_returns_empty(): void {
		// Nothing to fall back to either way — genuinely nothing was submitted
		// for this attachment/description, so an empty body is still correct.
		$result = $this->build( [], [], 'agnosis_artwork', '' );

		$this->assertSame( '', $result );
	}

	// -------------------------------------------------------------------------
	// Biography — artist statement used, AI body ignored
	// -------------------------------------------------------------------------

	public function test_biography_uses_artist_text_not_ai_body(): void {
		$primary = [ 'body' => '<p>AI photo description.</p>' ];
		$result  = $this->build( $primary, [], 'agnosis_biography', 'I am a sculptor based in Madrid.' );

		$this->assertStringContainsString( 'I am a sculptor based in Madrid.', $result );
		$this->assertStringNotContainsString( 'AI photo description.', $result );
	}

	public function test_biography_text_only_no_attachment_renders_artist_text(): void {
		// Simulates the exact failure case from the audit: text-only bio email,
		// no AI results, so primary is empty. Before the fix this produced ''.
		$result = $this->build( [], [], 'agnosis_biography', 'My name is Ana and I paint seascapes.' );

		$this->assertStringContainsString( 'My name is Ana', $result );
	}

	public function test_biography_with_no_text_returns_empty(): void {
		$primary = [ 'body' => '<p>AI description.</p>' ];
		$result  = $this->build( $primary, [], 'agnosis_biography', '' );

		$this->assertSame( '', $result );
	}

	// -------------------------------------------------------------------------
	// Event — same rules as biography
	// -------------------------------------------------------------------------

	public function test_event_uses_artist_text_not_ai_body(): void {
		$primary = [ 'body' => '<p>AI venue description.</p>' ];
		$result  = $this->build( $primary, [], 'agnosis_event', 'Join me at Gallery X, 15 July 2026, 7 pm.' );

		$this->assertStringContainsString( 'Gallery X', $result );
		$this->assertStringNotContainsString( 'AI venue description.', $result );
	}

	public function test_event_text_only_renders_artist_text(): void {
		$result = $this->build( [], [], 'agnosis_event', 'Opening night is Friday.' );

		$this->assertStringContainsString( 'Opening night is Friday.', $result );
	}

	// -------------------------------------------------------------------------
	// XSS — artist text is sanitised through wp_kses_post
	// -------------------------------------------------------------------------

	public function test_biography_artist_text_is_kses_sanitised(): void {
		$dirty  = '<p>Hello</p><script>alert(1)</script>';
		$result = $this->build( [], [], 'agnosis_biography', $dirty );

		$this->assertStringNotContainsString( '<script>', $result );
		$this->assertStringContainsString( '<p>Hello</p>', $result );
	}
}
