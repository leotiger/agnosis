<?php
/**
 * Network\ActivityPub::handle_for() — the Fediverse handle builder added in
 * 0.9.63 for the site-copyright and follow-overlay blocks.
 *
 * Written for the sixteenth audit's Q-1 (2026-07-31); 0.9.63 shipped with no
 * test coverage at all.
 *
 * The invariant worth pinning is the one the method was deliberately written
 * to hold, and the one that breaks silently: **a handle must resolve the
 * base/apex domain, never the current request's possibly-rewritten host.**
 * Agnosis serves each artist a subdomain by filtering `option_home`
 * (`SubdomainRouter::rewrite_home()`), so on `artistx.agnosis.art` a naive
 * `home_url()` read would render `@artistx@artistx.agnosis.art` — a handle
 * that looks plausible, is copyable, and resolves to nothing, because
 * WebFinger answers on the apex. Nothing would error; the Follow button would
 * simply hand every visitor a dead address.
 *
 * @package Agnosis
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Network;

use Agnosis\Network\ActivityPub;

class ActivityPubHandleTest extends \WP_UnitTestCase {

	private ActivityPub $ap;

	protected function setUp(): void {
		parent::setUp();
		$this->ap = new ActivityPub();
	}

	protected function tearDown(): void {
		delete_option( 'agnosis_base_domain' );
		remove_all_filters( 'option_home' );
		parent::tearDown();
	}

	/**
	 * @param int|\WP_Error $created
	 */
	private static function id( $created ): int {
		if ( $created instanceof \WP_Error ) {
			throw new \RuntimeException( 'Test fixture could not be created: ' . $created->get_error_message() );
		}
		return $created;
	}

	private function create_artist( string $nicename = 'tessa' ): int {
		return self::id( self::factory()->user->create( [
			'role'          => 'agnosis_artist',
			'user_login'    => $nicename,
			'user_nicename' => $nicename,
			'user_email'    => $nicename . '@example.com',
		] ) );
	}

	/** Stand in for SubdomainRouter::rewrite_home() being active on this request. */
	private function pretend_request_is_on_artist_subdomain( string $host ): void {
		add_filter( 'option_home', static fn (): string => 'https://' . $host );
	}

	// -------------------------------------------------------------------------
	// Node handle
	// -------------------------------------------------------------------------

	public function test_node_handle_uses_the_configured_base_domain(): void {
		update_option( 'agnosis_base_domain', 'agnosis.art' );

		$this->assertSame( 'agnosis@agnosis.art', $this->ap->handle_for( 'node' ) );
	}

	public function test_node_handle_falls_back_to_the_site_host_when_no_base_domain_is_set(): void {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$this->assertSame( 'agnosis@' . $host, $this->ap->handle_for( 'node' ) );
	}

	// -------------------------------------------------------------------------
	// Artist handle
	// -------------------------------------------------------------------------

	public function test_artist_handle_uses_the_nicename_and_the_base_domain(): void {
		update_option( 'agnosis_base_domain', 'agnosis.art' );
		$artist = $this->create_artist( 'tessa' );

		$this->assertSame( 'tessa@agnosis.art', $this->ap->handle_for( 'artist', $artist ) );
	}

	public function test_artist_handle_is_empty_for_a_user_that_does_not_exist(): void {
		update_option( 'agnosis_base_domain', 'agnosis.art' );

		$this->assertSame( '', $this->ap->handle_for( 'artist', 999999 ) );
	}

	// -------------------------------------------------------------------------
	// The actual point: apex-anchored, not request-anchored
	// -------------------------------------------------------------------------

	public function test_artist_handle_stays_on_the_apex_while_serving_that_artists_own_subdomain(): void {
		update_option( 'agnosis_base_domain', 'agnosis.art' );
		$artist = $this->create_artist( 'tessa' );

		$this->pretend_request_is_on_artist_subdomain( 'tessa.agnosis.art' );

		$this->assertSame(
			'tessa@agnosis.art',
			$this->ap->handle_for( 'artist', $artist ),
			'A handle rendered on an artist subdomain must still match what WebFinger resolves on the apex.'
		);
	}

	public function test_node_handle_stays_on_the_apex_while_serving_an_artist_subdomain(): void {
		update_option( 'agnosis_base_domain', 'agnosis.art' );

		$this->pretend_request_is_on_artist_subdomain( 'tessa.agnosis.art' );

		$this->assertSame( 'agnosis@agnosis.art', $this->ap->handle_for( 'node' ) );
	}

	/**
	 * Same handle from anywhere. This is what makes the copyable handle in the
	 * footer and the follow-overlay safe to paste into a Fediverse client
	 * regardless of which page the visitor happened to copy it from.
	 */
	public function test_handle_is_identical_on_the_apex_and_on_a_subdomain(): void {
		update_option( 'agnosis_base_domain', 'agnosis.art' );
		$artist = $this->create_artist( 'tessa' );

		$on_apex = $this->ap->handle_for( 'artist', $artist );

		$this->pretend_request_is_on_artist_subdomain( 'tessa.agnosis.art' );
		$on_subdomain = $this->ap->handle_for( 'artist', $artist );

		$this->assertSame( $on_apex, $on_subdomain );
	}

	/**
	 * Without a base domain configured there is no apex to anchor to, so the
	 * handle necessarily follows the request host. Asserted so the limitation
	 * is recorded rather than discovered: artist subdomains are not usable in
	 * that configuration anyway (`SubdomainRouter::boot()` requires the option).
	 */
	public function test_without_a_base_domain_the_handle_follows_the_request_host(): void {
		$artist = $this->create_artist( 'tessa' );
		$this->pretend_request_is_on_artist_subdomain( 'tessa.example.org' );

		$this->assertSame( 'tessa@tessa.example.org', $this->ap->handle_for( 'artist', $artist ) );
	}
}
