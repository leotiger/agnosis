<?php
/**
 * Integration tests for PostCreator's external-link-to-wp:embed feature.
 *
 * When an artist's file is too large to email (typically a video), the guide
 * tells them to include a link to their preferred platform instead. This
 * covers build_external_link_embeds() / is_allowed_embed_host() /
 * build_embed_block() — the private methods that scan the artist's raw
 * submitted text for links and turn each allowlisted one into a wp:embed
 * block appended at the bottom of the post.
 *
 * The allowlist is a hard safety boundary (see ALLOWED_EMBED_HOSTS's
 * docblock in PostCreator.php): this pipeline never fetches or inspects what
 * a linked page actually contains, so "reject commercial/adult sites" is
 * achieved structurally, by only ever trusting a short list of known
 * video/audio platforms — not by trying to detect what to exclude. These
 * tests exist mainly to prove that boundary actually holds.
 *
 * Methods are private and exercised via ReflectionMethod, same pattern as
 * PostCreatorGalleryAudioSkipTest. No AI calls are made — a minimal Pipeline
 * stub is injected.
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\AI\Pipeline;
use Agnosis\Publishing\PostCreator;

class PostCreatorExternalLinkEmbedTest extends \WP_UnitTestCase {

	private PostCreator $creator;

	protected function setUp(): void {
		parent::setUp();

		// Minimal Pipeline stub — no AI calls, no WP option resolution.
		$pipeline = new class() extends Pipeline {
			public function __construct() {}
			/** @param array<string, mixed> $submission */
			public function process( array $submission, bool $skip_enhancement = false ): array {
				return [];
			}
		};

		$this->creator = new PostCreator( $pipeline );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function call_build_embeds( string $artist_text ): string {
		$ref = new \ReflectionMethod( PostCreator::class, 'build_external_link_embeds' );
		$ref->setAccessible( true );
		return $ref->invoke( $this->creator, $artist_text );
	}

	private function call_is_allowed_host( string $host ): bool {
		$ref = new \ReflectionMethod( PostCreator::class, 'is_allowed_embed_host' );
		$ref->setAccessible( true );
		return $ref->invoke( $this->creator, $host );
	}

	private function call_build_post_content( array $primary, array $gallery, string $post_type, string $artist_text ): string {
		$ref = new \ReflectionMethod( PostCreator::class, 'build_post_content' );
		$ref->setAccessible( true );
		return $ref->invoke( $this->creator, $primary, $gallery, $post_type, $artist_text );
	}

	// -------------------------------------------------------------------------
	// Allowlist — the actual safety boundary
	// -------------------------------------------------------------------------

	public function test_youtube_host_is_allowed(): void {
		$this->assertTrue( $this->call_is_allowed_host( 'www.youtube.com' ) );
		$this->assertTrue( $this->call_is_allowed_host( 'youtu.be' ) );
	}

	public function test_bandcamp_subdomain_is_allowed(): void {
		// Bandcamp artists get their own subdomain (name.bandcamp.com) — the
		// allowlist must match on the base domain, not require an exact host match.
		$this->assertTrue( $this->call_is_allowed_host( 'aphextwin.bandcamp.com' ) );
	}

	public function test_unrelated_commercial_host_is_not_allowed(): void {
		$this->assertFalse( $this->call_is_allowed_host( 'www.amazon.com' ) );
		$this->assertFalse( $this->call_is_allowed_host( 'shop.example.com' ) );
	}

	public function test_lookalike_host_is_not_allowed_by_substring(): void {
		// "notyoutube.com" must not match "youtube.com" via a loose substring
		// check — only an exact host or a genuine subdomain should ever pass.
		$this->assertFalse( $this->call_is_allowed_host( 'notyoutube.com' ) );
		$this->assertFalse( $this->call_is_allowed_host( 'youtube.com.evil.example' ) );
	}

	public function test_allowlist_is_extensible_via_filter(): void {
		$filter = fn( array $hosts ) => array_merge( $hosts, [ 'peertube.example.org' ] );
		add_filter( 'agnosis_embed_host_allowlist', $filter );

		$this->assertTrue( $this->call_is_allowed_host( 'peertube.example.org' ) );

		remove_filter( 'agnosis_embed_host_allowlist', $filter );

		// And the filter's effect doesn't leak once removed.
		$this->assertFalse( $this->call_is_allowed_host( 'peertube.example.org' ) );
	}

	// -------------------------------------------------------------------------
	// build_external_link_embeds() — extraction + block generation
	// -------------------------------------------------------------------------

	public function test_no_url_in_text_returns_empty_string(): void {
		$this->assertSame( '', $this->call_build_embeds( 'Just a regular message with no links at all.' ) );
	}

	public function test_empty_text_returns_empty_string(): void {
		$this->assertSame( '', $this->call_build_embeds( '' ) );
	}

	public function test_allowed_link_produces_a_wp_embed_block(): void {
		$text   = 'The file was too large to attach, so here it is: https://www.youtube.com/watch?v=dQw4w9WgXcQ';
		$result = $this->call_build_embeds( $text );

		$this->assertStringContainsString( '<!-- wp:embed', $result );
		$this->assertStringContainsString( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', $result );
		$this->assertStringContainsString( 'wp-block-embed__wrapper', $result );
	}

	public function test_disallowed_link_produces_no_embed_and_no_raw_link(): void {
		$text   = 'Buy prints of my other work here: https://www.amazon.com/dp/B000123456';
		$result = $this->call_build_embeds( $text );

		$this->assertSame( '', $result, 'A link to a non-allowlisted host must not appear in the output at all — neither as an embed nor as raw text.' );
	}

	public function test_trailing_sentence_punctuation_is_stripped_from_url(): void {
		$text   = 'Full video here: https://vimeo.com/123456789.';
		$result = $this->call_build_embeds( $text );

		$this->assertStringContainsString( 'https://vimeo.com/123456789', $result );
		$this->assertStringNotContainsString( '123456789.', $result );
	}

	public function test_mixed_allowed_and_disallowed_links_only_embeds_the_allowed_one(): void {
		$text   = "See it on my shop: https://www.etsy.com/listing/123\nOr watch it: https://soundcloud.com/artist/track";
		$result = $this->call_build_embeds( $text );

		$this->assertStringContainsString( 'soundcloud.com', $result );
		$this->assertStringNotContainsString( 'etsy.com', $result );
	}

	public function test_duplicate_links_are_only_embedded_once(): void {
		$text   = 'https://youtu.be/abc123 and again https://youtu.be/abc123';
		$result = $this->call_build_embeds( $text );

		$this->assertSame( 1, substr_count( $result, '<!-- wp:embed' ) );
	}

	public function test_links_are_capped_at_max_embedded_links(): void {
		$text = 'https://youtu.be/a https://youtu.be/b https://youtu.be/c https://youtu.be/d https://youtu.be/e';
		$result = $this->call_build_embeds( $text );

		// MAX_EMBEDDED_LINKS = 3 — a submission with five allowed links must
		// not turn into a wall of embeds.
		$this->assertSame( 3, substr_count( $result, '<!-- wp:embed' ) );
	}

	// -------------------------------------------------------------------------
	// build_post_content() — embeds land at the bottom, after body text
	// -------------------------------------------------------------------------

	public function test_embed_is_appended_after_body_text_with_no_gallery(): void {
		$primary = [ 'body' => '<p>AI-written description.</p>' ];
		$content = $this->call_build_post_content(
			$primary,
			[], // no gallery — this used to short-circuit before any embed logic ran
			'agnosis_artwork',
			'Video was too big to email: https://www.youtube.com/watch?v=xyz'
		);

		$this->assertStringContainsString( '<p>AI-written description.</p>', $content );
		$this->assertStringContainsString( '<!-- wp:embed', $content );
		$this->assertGreaterThan(
			strpos( $content, 'AI-written description' ),
			strpos( $content, '<!-- wp:embed' ),
			'The embed block must come after the body text, not before it.'
		);
	}

	public function test_no_links_leaves_content_unchanged(): void {
		$primary = [ 'body' => '<p>AI-written description.</p>' ];
		$content = $this->call_build_post_content( $primary, [], 'agnosis_artwork', 'No links in this message.' );

		$this->assertSame( '<p>AI-written description.</p>', $content );
	}
}
