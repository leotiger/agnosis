<?php
/**
 * Unit tests for PostCreator::resolve_indicator().
 *
 * This method is the security-sensitive entry point for user-supplied input
 * (the email subject). Tests cover every branch: known indicators, unknown
 * indicators, case-insensitivity, surrounding whitespace, missing brackets,
 * and subjects that look like indicators but are not.
 *
 * @package Agnosis\Tests\Unit\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\Publishing;

use Agnosis\Publishing\PostCreator;
use PHPUnit\Framework\TestCase;

class IndicatorParsingTest extends TestCase {

	// -------------------------------------------------------------------------
	// Helper — call private resolve_indicator() via reflection
	// -------------------------------------------------------------------------

	/**
	 * Invoke PostCreator::resolve_indicator() without running the constructor.
	 *
	 * @return array{0: string, 1: bool, 2: string} [post_type, is_singleton, clean_subject]
	 */
	private function resolve( string $subject ): array {
		// newInstanceWithoutConstructor() avoids the Pipeline dependency.
		$instance = ( new \ReflectionClass( PostCreator::class ) )
			->newInstanceWithoutConstructor();

		$method = new \ReflectionMethod( PostCreator::class, 'resolve_indicator' );
		$method->setAccessible( true );

		/** @var array{0: string, 1: bool, 2: string} */
		return $method->invoke( $instance, $subject );
	}

	// -------------------------------------------------------------------------
	// No indicator — default artwork type
	// -------------------------------------------------------------------------

	public function test_no_indicator_returns_artwork_type(): void {
		[ $type, $singleton, $clean ] = $this->resolve( 'Sunset over the harbour' );

		$this->assertSame( 'agnosis_artwork', $type );
		$this->assertFalse( $singleton );
		$this->assertSame( 'Sunset over the harbour', $clean );
	}

	public function test_empty_subject_returns_artwork_type(): void {
		[ $type, $singleton, $clean ] = $this->resolve( '' );

		$this->assertSame( 'agnosis_artwork', $type );
		$this->assertFalse( $singleton );
		$this->assertSame( '', $clean );
	}

	// -------------------------------------------------------------------------
	// [Biography] indicator
	// -------------------------------------------------------------------------

	public function test_biography_indicator_routes_to_biography_cpt(): void {
		[ $type, $singleton, $clean ] = $this->resolve( '[Biography] Artist statement' );

		$this->assertSame( 'agnosis_biography', $type );
		$this->assertTrue( $singleton );
		$this->assertSame( 'Artist statement', $clean );
	}

	public function test_biography_indicator_is_case_insensitive(): void {
		foreach ( [ '[BIOGRAPHY]', '[biography]', '[Biography]', '[bIoGrApHy]' ] as $variant ) {
			[ $type ] = $this->resolve( $variant . ' Some text' );
			$this->assertSame(
				'agnosis_biography',
				$type,
				"Expected biography CPT for subject starting with '$variant'."
			);
		}
	}

	public function test_biography_indicator_strips_prefix_from_subject(): void {
		[ , , $clean ] = $this->resolve( '[Biography] My life in art' );

		$this->assertSame( 'My life in art', $clean );
	}

	public function test_biography_indicator_with_no_trailing_text(): void {
		[ $type, $singleton, $clean ] = $this->resolve( '[Biography]' );

		$this->assertSame( 'agnosis_biography', $type );
		$this->assertTrue( $singleton );
		$this->assertSame( '', $clean );
	}

	public function test_biography_indicator_with_extra_space_after_bracket(): void {
		[ , , $clean ] = $this->resolve( '[Biography]   Spaced title' );

		// The regex \s* after the bracket consumes all leading spaces.
		$this->assertSame( 'Spaced title', $clean );
	}

	// -------------------------------------------------------------------------
	// [Event] indicator
	// -------------------------------------------------------------------------

	public function test_event_indicator_routes_to_event_cpt(): void {
		[ $type, $singleton, $clean ] = $this->resolve( '[Event] Solo exhibition at Gallery X' );

		$this->assertSame( 'agnosis_event', $type );
		$this->assertTrue( $singleton );
		$this->assertSame( 'Solo exhibition at Gallery X', $clean );
	}

	public function test_event_indicator_is_case_insensitive(): void {
		foreach ( [ '[EVENT]', '[event]', '[Event]' ] as $variant ) {
			[ $type ] = $this->resolve( $variant . ' text' );
			$this->assertSame( 'agnosis_event', $type, "Failed for '$variant'." );
		}
	}

	// -------------------------------------------------------------------------
	// Unknown indicator — falls back to artwork
	// -------------------------------------------------------------------------

	public function test_unknown_indicator_falls_back_to_artwork(): void {
		[ $type, $singleton, $clean ] = $this->resolve( '[Exhibition] My show' );

		$this->assertSame( 'agnosis_artwork', $type );
		$this->assertFalse( $singleton );
		// Unknown indicator is NOT stripped — the full original subject is returned.
		$this->assertSame( '[Exhibition] My show', $clean );
	}

	public function test_unknown_indicator_preserves_full_subject(): void {
		[ , , $clean ] = $this->resolve( '[Press] An interview' );

		$this->assertSame( '[Press] An interview', $clean );
	}

	// -------------------------------------------------------------------------
	// Edge cases / potential injection
	// -------------------------------------------------------------------------

	public function test_indicator_with_html_content_is_treated_as_unknown(): void {
		[ $type, , $clean ] = $this->resolve( '[<script>alert(1)</script>] XSS attempt' );

		// The regex matches any [non-]+ group; the keyword won't match a known
		// indicator so it falls back to artwork with the full subject preserved.
		$this->assertSame( 'agnosis_artwork', $type );
		$this->assertSame( '[<script>alert(1)</script>] XSS attempt', $clean );
	}

	public function test_square_bracket_in_body_is_not_treated_as_indicator(): void {
		// Indicator must be at the very start of the subject (^).
		[ $type, , $clean ] = $this->resolve( 'Title [with brackets] inside' );

		$this->assertSame( 'agnosis_artwork', $type );
		$this->assertSame( 'Title [with brackets] inside', $clean );
	}

	public function test_empty_brackets_are_not_treated_as_indicator(): void {
		// [] matches the regex pattern but produces an empty keyword → unknown.
		[ $type ] = $this->resolve( '[] Something' );

		$this->assertSame( 'agnosis_artwork', $type );
	}

	public function test_whitespace_only_inside_brackets_is_unknown_indicator(): void {
		[ $type ] = $this->resolve( '[   ] Something' );

		$this->assertSame( 'agnosis_artwork', $type );
	}

	public function test_indicator_with_unicode_content_is_treated_as_unknown(): void {
		[ $type ] = $this->resolve( '[Exposición] Mi obra' );

		$this->assertSame( 'agnosis_artwork', $type );
	}

	public function test_multiline_subject_does_not_fool_indicator_parser(): void {
		// The indicator must start at position 0; a newline before it won't match.
		[ $type, , $clean ] = $this->resolve( "\n[Biography] sneaky" );

		$this->assertSame( 'agnosis_artwork', $type );
		$this->assertSame( "\n[Biography] sneaky", $clean );
	}

	// -------------------------------------------------------------------------
	// Localized indicators (fifth audit §2d) — resolve_indicator()'s bracket
	// keyword table gained ~50 non-English aliases across biography/event/
	// photo/pure. Previously an English-only keyword list silently turned a
	// non-English artist's "[Biografía]" into a plain artwork submission —
	// these were entirely untested before this file's own additions.
	// -------------------------------------------------------------------------

	/** @return array<string, array{0: string, 1: string}> [locale label => [keyword, expected post_type]] */
	public static function localized_biography_keywords(): array {
		return [
			'es accented'  => [ 'Biografía', 'agnosis_biography' ],
			'es/it/pt/ca'  => [ 'biografia', 'agnosis_biography' ],
			'fr'           => [ 'Biographie', 'agnosis_biography' ],
			'de/nl'        => [ 'Biografie', 'agnosis_biography' ],
			'ja'           => [ 'プロフィール', 'agnosis_biography' ],
			'ja alt'       => [ '経歴', 'agnosis_biography' ],
			'zh'           => [ '简介', 'agnosis_biography' ],
			'ar'           => [ 'سيرة', 'agnosis_biography' ],
			'ru'           => [ 'биография', 'agnosis_biography' ],
			'hi'           => [ 'जीवनी', 'agnosis_biography' ],
		];
	}

	/** @dataProvider localized_biography_keywords */
	public function test_localized_biography_indicators_route_to_biography_cpt( string $keyword, string $expected_type ): void {
		[ $type, $singleton, $clean ] = $this->resolve( "[{$keyword}] Mi declaración de artista" );

		$this->assertSame( $expected_type, $type, "Failed for keyword '{$keyword}'." );
		$this->assertTrue( $singleton );
		$this->assertSame( 'Mi declaración de artista', $clean );
	}

	/** @return array<string, array{0: string}> */
	public static function localized_event_keywords(): array {
		return [
			'es/it/pt' => [ 'Evento' ],
			'fr accented' => [ 'événement' ],
			'fr unaccented' => [ 'evenement' ],
			'de' => [ 'Veranstaltung' ],
			'ja' => [ 'イベント' ],
			'zh' => [ '活动' ],
			'ar' => [ 'حدث' ],
			'hi' => [ 'कार्यक्रम' ],
		];
	}

	/** @dataProvider localized_event_keywords */
	public function test_localized_event_indicators_route_to_event_cpt( string $keyword ): void {
		[ $type, $singleton ] = $this->resolve( "[{$keyword}] Solo show" );

		$this->assertSame( 'agnosis_event', $type, "Failed for keyword '{$keyword}'." );
		$this->assertTrue( $singleton );
	}

	public function test_localized_indicator_case_insensitivity(): void {
		// Bracket keywords are lowercased before lookup — an uppercased accented
		// variant must still match.
		[ $type ] = $this->resolve( '[BIOGRAFÍA] Texto' );

		$this->assertSame( 'agnosis_biography', $type );
	}

	// -------------------------------------------------------------------------
	// photo_only / pure flags (indices 3/4) — previously untested; every test
	// above only destructures the first three elements.
	// -------------------------------------------------------------------------

	public function test_photo_indicator_sets_photo_only_but_not_pure(): void {
		[ $type, $singleton, , $photo_only, $pure ] = $this->resolve( '[Photo] A quick snapshot' );

		$this->assertSame( 'agnosis_artwork', $type );
		$this->assertFalse( $singleton );
		$this->assertTrue( $photo_only );
		$this->assertFalse( $pure );
	}

	public function test_pure_indicator_sets_both_photo_only_and_pure(): void {
		[ $type, $singleton, , $photo_only, $pure ] = $this->resolve( '[Pure] Untouched original' );

		$this->assertSame( 'agnosis_artwork', $type );
		$this->assertFalse( $singleton );
		$this->assertTrue( $photo_only, 'pure@ implies photo_only as a subset.' );
		$this->assertTrue( $pure );
	}

	/** @return array<string, array{0: string}> */
	public static function localized_photo_keywords(): array {
		return [
			'es/it/pt/de/nl/id' => [ 'foto' ],
			'ja'                => [ '写真' ],
			'zh'                => [ '照片' ],
			'ru'                => [ 'фото' ],
		];
	}

	/** @dataProvider localized_photo_keywords */
	public function test_localized_photo_indicators_set_photo_only_flag( string $keyword ): void {
		[ $type, , , $photo_only, $pure ] = $this->resolve( "[{$keyword}] Snapshot" );

		$this->assertSame( 'agnosis_artwork', $type, "Failed for keyword '{$keyword}'." );
		$this->assertTrue( $photo_only );
		$this->assertFalse( $pure );
	}

	/** @return array<string, array{0: string}> */
	public static function localized_pure_keywords(): array {
		return [
			'es/it/pt' => [ 'Puro' ],
			'fr'       => [ 'Pur' ],
			'de'       => [ 'Rein' ],
			'zh'       => [ '纯' ],
			'ar'       => [ 'نقي' ],
		];
	}

	/** @dataProvider localized_pure_keywords */
	public function test_localized_pure_indicators_set_both_flags( string $keyword ): void {
		[ $type, , , $photo_only, $pure ] = $this->resolve( "[{$keyword}] Original file" );

		$this->assertSame( 'agnosis_artwork', $type, "Failed for keyword '{$keyword}'." );
		$this->assertTrue( $photo_only, "photo_only must be true for pure keyword '{$keyword}'." );
		$this->assertTrue( $pure, "pure must be true for keyword '{$keyword}'." );
	}

	public function test_unknown_indicator_has_no_photo_only_or_pure_flags(): void {
		[ , , , $photo_only, $pure ] = $this->resolve( '[Exhibition] My show' );

		$this->assertFalse( $photo_only );
		$this->assertFalse( $pure );
	}
}
