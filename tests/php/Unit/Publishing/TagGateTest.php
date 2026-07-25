<?php
/**
 * Unit tests for Publishing\TagGate — the gate table TAG-REDESIGN.md T2's
 * own acceptance criteria calls out by name ("Unit tests for the gate table
 * (junk/numeric/length/cap/normalization cases) and the TTL boundary").
 *
 * normalize_for_match()/is_junk() are pure functions (no WordPress calls),
 * so this lives under Unit/ and runs under plain PHPUnit — TagGate's own
 * third method, vocabulary_map(), calls get_terms()/taxonomy_exists() and is
 * covered separately under Integration/ instead (see TagGateVocabularyTest).
 *
 * @package Agnosis\Tests\Unit\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\Publishing;

use Agnosis\Publishing\TagGate;
use PHPUnit\Framework\TestCase;

class TagGateTest extends TestCase {

	// -------------------------------------------------------------------------
	// normalize_for_match()
	// -------------------------------------------------------------------------

	public function test_normalize_trims_outer_whitespace(): void {
		$this->assertSame( 'sunset', TagGate::normalize_for_match( '  sunset  ' ) );
	}

	public function test_normalize_collapses_internal_whitespace(): void {
		$this->assertSame( 'oil painting', TagGate::normalize_for_match( "Oil   \t Painting" ) );
	}

	public function test_normalize_strips_trailing_period_and_comma(): void {
		$this->assertSame( 'abstract', TagGate::normalize_for_match( 'Abstract.' ) );
		$this->assertSame( 'abstract', TagGate::normalize_for_match( 'Abstract,' ) );
		$this->assertSame( 'abstract', TagGate::normalize_for_match( 'Abstract..,,' ) );
	}

	public function test_normalize_lowercases(): void {
		$this->assertSame( 'photography', TagGate::normalize_for_match( 'Photography' ) );
		$this->assertSame( 'photography', TagGate::normalize_for_match( 'PHOTOGRAPHY' ) );
	}

	public function test_normalize_makes_case_variants_compare_equal(): void {
		$this->assertSame(
			TagGate::normalize_for_match( 'photography' ),
			TagGate::normalize_for_match( 'Photography' ),
			'TW-9: a byte-exact comparison missed "photography" vs "Photography" — this is the fix.'
		);
	}

	public function test_normalize_nfc_normalizes_when_intl_available(): void {
		if ( ! class_exists( '\Normalizer' ) ) {
			$this->markTestSkipped( 'intl extension (Normalizer) not loaded in this environment.' );
		}

		// NFD ("e" + combining acute accent, U+0065 U+0301) vs NFC (precomposed
		// "é", U+00E9) — visually identical, byte-different. Both must
		// normalize to the same comparison key.
		$nfd = "cafe\u{0301}";
		$nfc = "caf\u{00e9}";

		$this->assertSame( TagGate::normalize_for_match( $nfc ), TagGate::normalize_for_match( $nfd ) );
	}

	public function test_normalize_never_rewrites_display_casing_is_a_caller_concern(): void {
		// normalize_for_match() itself always lowercases — this test just
		// pins that the method is a pure comparison-key transform with no
		// side effect on any external state, i.e. calling it twice is
		// idempotent (a second normalization pass is a no-op).
		$once  = TagGate::normalize_for_match( '  Golden  Hour.  ' );
		$twice = TagGate::normalize_for_match( $once );
		$this->assertSame( $once, $twice );
	}

	// -------------------------------------------------------------------------
	// is_junk() — empty / "Array" / mangling signature
	// -------------------------------------------------------------------------

	public function test_is_junk_rejects_empty_string(): void {
		$this->assertTrue( TagGate::is_junk( '' ) );
	}

	public function test_is_junk_rejects_the_literal_array_artifact(): void {
		$this->assertTrue( TagGate::is_junk( TagGate::normalize_for_match( 'Array' ) ) );
	}

	public function test_is_junk_rejects_a_mangling_signature(): void {
		// TW-14/TW-15's exact live corruption shape: a \uXXXX escape with its
		// backslash stripped.
		$this->assertTrue( TagGate::is_junk( 'connexiu00f3' ) );
	}

	public function test_is_junk_accepts_a_word_that_happens_to_contain_u_followed_by_letters(): void {
		// Sanity check the mangling regex requires exactly 4 HEX digits after
		// "u", not any 4 characters — an ordinary word must survive.
		$this->assertFalse( TagGate::is_junk( 'unusual' ) );
	}

	// -------------------------------------------------------------------------
	// is_junk() — length
	// -------------------------------------------------------------------------

	public function test_is_junk_rejects_single_character(): void {
		$this->assertTrue( TagGate::is_junk( 'a' ) );
	}

	public function test_is_junk_accepts_two_characters(): void {
		$this->assertFalse( TagGate::is_junk( 'ai' ) );
	}

	// -------------------------------------------------------------------------
	// is_junk() — bare numbers vs. plausible 4-digit years
	// -------------------------------------------------------------------------

	public function test_is_junk_rejects_short_bare_numbers(): void {
		$this->assertTrue( TagGate::is_junk( '42' ) );
	}

	public function test_is_junk_rejects_a_4_digit_number_outside_the_plausible_year_range(): void {
		$this->assertTrue( TagGate::is_junk( '9999' ) );
		$this->assertTrue( TagGate::is_junk( '0000' ) );
	}

	public function test_is_junk_accepts_a_plausible_4_digit_year(): void {
		$this->assertFalse( TagGate::is_junk( '1987' ) );
		$this->assertFalse( TagGate::is_junk( '2026' ) );
	}

	public function test_is_junk_accepts_ordinary_words(): void {
		$this->assertFalse( TagGate::is_junk( 'sunset' ) );
		$this->assertFalse( TagGate::is_junk( 'oil painting' ) );
		$this->assertFalse( TagGate::is_junk( 'història' ) );
	}
}
