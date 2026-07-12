<?php
/**
 * Integration tests for Publishing\SocialLinks.
 *
 * render_icon_row() runs its procedurally-built wp:social-links/wp:social-link
 * markup through do_blocks(), which needs WordPress's real core block
 * registry (core/social-links, core/social-link) to actually render anything
 * — that registry only exists once full WordPress has bootstrapped, so this
 * is an Integration test (WP_UnitTestCase), not a Unit test, unlike most of
 * this codebase's other pure-logic helpers.
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\Publishing\SocialLinks;
use WP_UnitTestCase;

class SocialLinksTest extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// detect_service()
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider recognized_host_provider
	 */
	public function test_detect_service_recognizes_known_platforms( string $url, string $expected_service ): void {
		$this->assertSame( $expected_service, SocialLinks::detect_service( $url ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public function recognized_host_provider(): array {
		return [
			'facebook'   => [ 'https://facebook.com/artist', 'facebook' ],
			'instagram'  => [ 'https://instagram.com/artist', 'instagram' ],
			'x (twitter)' => [ 'https://twitter.com/artist', 'x' ],
			'x.com'      => [ 'https://x.com/artist', 'x' ],
			'bandcamp'   => [ 'https://artist.bandcamp.com', 'bandcamp' ],
			'youtube'    => [ 'https://youtube.com/@artist', 'youtube' ],
			'youtu.be'   => [ 'https://youtu.be/abc123', 'youtube' ],
			'whatsapp'   => [ 'https://wa.me/1234567890', 'whatsapp' ],
			'soundcloud' => [ 'https://soundcloud.com/artist', 'soundcloud' ],
		];
	}

	public function test_detect_service_matches_a_subdomain_of_a_known_host(): void {
		// Mirrors EmbedPolicy::is_trusted_host()'s own subdomain convention —
		// "myname.bandcamp.com" must match the "bandcamp.com" entry.
		$this->assertSame( 'bandcamp', SocialLinks::detect_service( 'https://myname.bandcamp.com' ) );
	}

	public function test_detect_service_strips_www_prefix(): void {
		$this->assertSame( 'facebook', SocialLinks::detect_service( 'https://www.facebook.com/artist' ) );
	}

	public function test_detect_service_falls_back_to_generic_for_unknown_host(): void {
		$this->assertSame( 'chain', SocialLinks::detect_service( 'https://my-personal-site.example' ) );
	}

	public function test_detect_service_falls_back_to_generic_for_an_unparseable_url(): void {
		$this->assertSame( 'chain', SocialLinks::detect_service( 'not a url at all' ) );
	}

	// -------------------------------------------------------------------------
	// render_icon_row()
	// -------------------------------------------------------------------------

	public function test_render_icon_row_returns_empty_string_for_no_urls(): void {
		$this->assertSame( '', SocialLinks::render_icon_row( [] ) );
	}

	public function test_render_icon_row_returns_empty_string_when_every_entry_is_blank(): void {
		$this->assertSame( '', SocialLinks::render_icon_row( [ '', '  ', '' ] ) );
	}

	public function test_render_icon_row_skips_blank_entries_but_renders_the_rest(): void {
		$html = SocialLinks::render_icon_row( [ '', 'https://instagram.com/artist', '' ] );

		$this->assertNotSame( '', $html );
		$this->assertStringContainsString( 'wp-block-social-links', $html );
		$this->assertStringContainsString( 'instagram.com/artist', $html );
	}

	public function test_render_icon_row_renders_every_non_blank_url_via_the_real_core_block(): void {
		$html = SocialLinks::render_icon_row( [
			'https://example.com/portfolio',
			'https://instagram.com/artist',
			'https://myname.bandcamp.com',
		] );

		$this->assertStringContainsString( 'wp-block-social-links', $html, 'Must render through the real core/social-links block, not custom markup.' );
		$this->assertStringContainsString( 'example.com/portfolio', $html );
		$this->assertStringContainsString( 'instagram.com/artist', $html );
		$this->assertStringContainsString( 'myname.bandcamp.com', $html );
	}
}
