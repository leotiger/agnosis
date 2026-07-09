<?php
/**
 * Unit tests for PostCreator::resolve_post_type() — fifth audit §5a.
 *
 * Previously this matched only against a single 'to_address' value. Real
 * mail can carry several relevant recipients (a To: list, or a Cc:) and the
 * artist's intent shouldn't depend on which single address Parser happened
 * to capture first. resolve_post_type() now matches against EVERY address in
 * 'to_addresses' (falling back to the singular 'to_address' when that key is
 * absent, for older queued rows and test doubles that only set the legacy
 * key), and this file — unlike any existing test — actually exercises that.
 *
 * Address-based routing takes priority over the subject-line indicator
 * fallback; within address-based routing, the order tested here matches the
 * order resolve_post_type() itself checks in (bio, event, pure, photo,
 * replace, remove, promote).
 *
 * Uses a namespace-scoped get_option() override (Stubs/publishing_namespace_stubs.php)
 * so each test can configure exactly which agnosis_email_* aliases are set,
 * independent of dev/bootstrap.php's global get_option() stub (which always
 * returns the default and isn't per-test controllable).
 *
 * @package Agnosis\Tests\Unit\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\Publishing;

use Agnosis\Publishing\PostCreator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/publishing_namespace_stubs.php';

class ResolvePostTypeTest extends TestCase {

	/** @var array<string, mixed> Option key => value, read by the namespace-scoped get_option() stub. */
	public static array $options = [];

	protected function setUp(): void {
		parent::setUp();
		self::$options = [];
	}

	protected function tearDown(): void {
		self::$options = [];
	}

	// -------------------------------------------------------------------------
	// Helper — call private resolve_post_type() via reflection
	// -------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $submission
	 * @return array{0: string, 1: bool, 2: string, 3: bool, 4: bool}
	 */
	private function resolve( array $submission ): array {
		$instance = ( new \ReflectionClass( PostCreator::class ) )->newInstanceWithoutConstructor();
		$method   = new \ReflectionMethod( PostCreator::class, 'resolve_post_type' );
		$method->setAccessible( true );
		/** @var array{0: string, 1: bool, 2: string, 3: bool, 4: bool} */
		return $method->invoke( $instance, $submission );
	}

	// -------------------------------------------------------------------------
	// to_addresses — matching ANY recipient in the list, not just the first
	// -------------------------------------------------------------------------

	public function test_matches_remove_address_when_it_is_the_only_to_address(): void {
		self::$options['agnosis_email_remove'] = 'remove@example.com';

		[ $type ] = $this->resolve( [
			'to_addresses' => [ 'remove@example.com' ],
			'subject'      => 'Take this down',
		] );

		$this->assertSame( 'agnosis_remove', $type );
	}

	public function test_matches_remove_address_when_it_is_second_in_a_to_and_cc_list(): void {
		// The artist's mail client put a friend's CC'd address first — the
		// management alias is still in the list, just not first.
		self::$options['agnosis_email_remove'] = 'remove@example.com';

		[ $type ] = $this->resolve( [
			'to_addresses' => [ 'friend@example.com', 'remove@example.com' ],
			'subject'      => 'Take this down',
		] );

		$this->assertSame( 'agnosis_remove', $type, 'A CC-only match must still route to agnosis_remove — previously only the first To: address was ever checked.' );
	}

	public function test_matches_promote_address_as_the_third_of_three_recipients(): void {
		self::$options['agnosis_email_promote'] = 'promote@example.com';

		[ $type ] = $this->resolve( [
			'to_addresses' => [ 'other@example.com', 'friend@example.com', 'promote@example.com' ],
			'subject'      => 'Feature this',
		] );

		$this->assertSame( 'agnosis_promote', $type );
	}

	public function test_address_matching_is_case_insensitive_and_trims_whitespace(): void {
		self::$options['agnosis_email_bio'] = 'bio@example.com';

		[ $type ] = $this->resolve( [
			'to_addresses' => [ '  BIO@EXAMPLE.COM  ' ],
			'subject'      => 'My statement',
		] );

		$this->assertSame( 'agnosis_biography', $type );
	}

	// -------------------------------------------------------------------------
	// Legacy fallback — 'to_address' (singular) used when 'to_addresses' absent
	// -------------------------------------------------------------------------

	public function test_falls_back_to_singular_to_address_when_to_addresses_key_is_absent(): void {
		self::$options['agnosis_email_event'] = 'event@example.com';

		[ $type ] = $this->resolve( [
			'to_address' => 'event@example.com',
			'subject'    => 'Opening night',
		] );

		$this->assertSame( 'agnosis_event', $type, 'Older queued rows and test doubles only ever set the singular to_address key — this must still work.' );
	}

	public function test_to_addresses_key_takes_priority_over_to_address_when_both_present(): void {
		self::$options['agnosis_email_bio']   = 'bio@example.com';
		self::$options['agnosis_email_event'] = 'event@example.com';

		[ $type ] = $this->resolve( [
			'to_address'   => 'bio@example.com',   // Would match biography if used alone.
			'to_addresses' => [ 'event@example.com' ], // Actual full recipient list — no bio@ present.
			'subject'      => 'Opening night',
		] );

		$this->assertSame( 'agnosis_event', $type );
	}

	public function test_neither_to_address_nor_to_addresses_falls_back_to_indicator(): void {
		self::$options['agnosis_email_bio'] = 'bio@example.com';

		[ $type, $singleton, $clean ] = $this->resolve( [ 'subject' => '[Biography] My statement' ] );

		$this->assertSame( 'agnosis_biography', $type );
		$this->assertTrue( $singleton );
		$this->assertSame( 'My statement', $clean );
	}

	// -------------------------------------------------------------------------
	// Precedence among address types
	// -------------------------------------------------------------------------

	public function test_pure_takes_priority_over_photo_when_both_addresses_are_present(): void {
		// Both configured to the SAME alias (a plausible admin misconfiguration,
		// or an artist CCing both) — pure@ is checked first since it is the
		// stronger of the two lanes.
		self::$options['agnosis_email_pure']  = 'shared@example.com';
		self::$options['agnosis_email_photo'] = 'shared@example.com';

		[ $type, $singleton, , $photo_only, $pure ] = $this->resolve( [
			'to_addresses' => [ 'shared@example.com' ],
			'subject'      => 'Untouched original',
		] );

		$this->assertSame( 'agnosis_artwork', $type );
		$this->assertFalse( $singleton );
		$this->assertTrue( $photo_only );
		$this->assertTrue( $pure );
	}

	public function test_bio_takes_priority_over_event_when_both_addresses_are_in_the_recipient_list(): void {
		self::$options['agnosis_email_bio']   = 'bio@example.com';
		self::$options['agnosis_email_event'] = 'event@example.com';

		[ $type ] = $this->resolve( [
			'to_addresses' => [ 'event@example.com', 'bio@example.com' ],
			'subject'      => 'Ambiguous message',
		] );

		$this->assertSame( 'agnosis_biography', $type, 'bio is checked before event in resolve_post_type()\'s own address-check order.' );
	}

	// -------------------------------------------------------------------------
	// No configured aliases at all — falls back to indicator/default artwork
	// -------------------------------------------------------------------------

	public function test_empty_to_addresses_list_falls_back_to_indicator(): void {
		[ $type ] = $this->resolve( [
			'to_addresses' => [],
			'subject'      => 'Just a plain artwork submission',
		] );

		$this->assertSame( 'agnosis_artwork', $type );
	}

	public function test_no_configured_aliases_falls_back_to_default_artwork_type(): void {
		// No agnosis_email_* options set at all.
		[ $type, $singleton, $clean, $photo_only, $pure ] = $this->resolve( [
			'to_addresses' => [ 'someone@example.com' ],
			'subject'      => 'A regular submission',
		] );

		$this->assertSame( 'agnosis_artwork', $type );
		$this->assertFalse( $singleton );
		$this->assertSame( 'A regular submission', $clean );
		$this->assertFalse( $photo_only );
		$this->assertFalse( $pure );
	}
}
