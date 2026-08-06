<?php
/**
 * Integration tests — the agnosis/follow-overlay block.
 *
 * `Network\Federation\Follows` came out of the Q-2 split at **45.7%**, far below
 * every other unit from that exercise (Delivery 95, Replies 94, Interactions 92,
 * Serialization 90, Rhizome 88, Identity 82, Language 100). Almost all of the
 * shortfall was `render_follow_overlay()`.
 *
 * **It was never covered before the split either.** Those 80-odd lines sat inside
 * a 6,337-line `ActivityPub.php` where a single file-level percentage hid them
 * completely. Giving the follower domain its own file didn't create the gap — it
 * made an existing one legible, which is the clearest argument the decomposition
 * produced for itself.
 *
 * What matters here is what the block *refuses* to render, and what it puts in
 * front of a visitor who has no Agnosis account and never will:
 *
 *   Guards:
 *     - Renders nothing on a non-artwork post, and nothing at all for a post
 *       whose author has no resolvable handle
 *
 *   The handle (0.9.63's whole point):
 *     - Shows the artist's `@handle@host` on the APEX domain, even while the
 *       request is being served from an artist subdomain — a request-anchored
 *       handle would read `@artist@artist.agnosis.art`, which looks right, copies
 *       cleanly, and resolves to nothing, because WebFinger answers on the apex
 *     - The copy button carries the same value the code element displays
 *
 *   Accessibility (sixteenth audit A-3, fixed in 0.9.66):
 *     - The form status paragraph is a live region. frontend.js writes the
 *       "enter your instance domain" validation error into it, so without
 *       aria-live a screen-reader user gets no feedback at all on a failed
 *       submit. That fix had no test; it does now.
 *     - The close button carries an accessible name rather than a bare icon
 *
 *   Localized data:
 *     - actorUrl is the artist's actor, not the node's — following the wrong
 *       actor would silently subscribe someone to the whole node firehose
 *
 * @package Agnosis\Tests\Integration\Network
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Network;

use Agnosis\Network\ActivityPub;

class FollowOverlayTest extends \WP_UnitTestCase {

	private int $artist_id;
	private int $post_id;

	protected function setUp(): void {
		parent::setUp();

		$this->artist_id = self::id( self::factory()->user->create( [
			'role'          => 'agnosis_artist',
			'user_nicename' => 'rosa',
			'display_name'  => 'Rosa Bonheur',
		] ) );

		$this->post_id = self::id( self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
		] ) );
	}

	/**
	 * Narrow a factory return from int|WP_Error to int by throwing on failure.
	 *
	 * Same helper ActivityPubHandleTest, DepartureBanContentTest and PrivacyTest
	 * already carry. A (int) cast would satisfy PHPStan while turning a failed
	 * fixture into the silent id 0 — which passes some assertions and makes the
	 * rest fail somewhere unrelated.
	 *
	 * @param int|\WP_Error $created
	 */
	private static function id( $created ): int {
		if ( $created instanceof \WP_Error ) {
			throw new \RuntimeException( 'Test fixture could not be created: ' . $created->get_error_message() );
		}
		return $created;
	}

	protected function tearDown(): void {
		wp_dequeue_script( 'agnosis-follow-overlay' );
		wp_deregister_script( 'agnosis-follow-overlay' );
		wp_dequeue_style( 'agnosis-follow-overlay' );
		wp_deregister_style( 'agnosis-follow-overlay' );

		parent::tearDown();
	}

	/**
	 * Dispatch agnosis/follow-overlay through a real WP_Block::render().
	 *
	 * Same reason ActivityPubTest's own block helpers give: the callback calls
	 * get_block_wrapper_attributes(), which reads WP_Block_Supports::$block_to_render
	 * and fatals on null if the callback is invoked directly. The block also
	 * declares usesContext: ["postId"], supplied through WP_Block's constructor —
	 * render_block() gives no way to pass it.
	 */
	private function render_via_block( int $post_id ): string {
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'agnosis/follow-overlay' ) ) {
			( new ActivityPub() )->register_follow_overlay_block();
		}

		$block = new \WP_Block(
			[
				'blockName'    => 'agnosis/follow-overlay',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			],
			[ 'postId' => $post_id ]
		);

		return $block->render();
	}

	// -------------------------------------------------------------------------
	// Guards
	// -------------------------------------------------------------------------

	public function test_renders_nothing_on_a_non_artwork_post(): void {
		$page = self::id( self::factory()->post->create( [ 'post_type' => 'page', 'post_author' => $this->artist_id ] ) );

		$this->assertSame( '', $this->render_via_block( $page ) );
	}

	public function test_renders_nothing_for_a_post_that_does_not_exist(): void {
		$this->assertSame( '', $this->render_via_block( 99999999 ) );
	}

	/**
	 * A post authored by someone who is not an admitted artist has no actor and
	 * therefore no handle. Rendering a Follow button pointing at nothing would be
	 * worse than rendering none: it looks live, copies cleanly, and fails only
	 * once a visitor pastes it into their own app.
	 */
	public function test_renders_nothing_when_the_author_has_no_resolvable_handle(): void {
		$stranger = self::id( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$post     = self::id( self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $stranger,
		] ) );

		$this->assertSame( '', $this->render_via_block( $post ) );
	}

	// -------------------------------------------------------------------------
	// The handle
	// -------------------------------------------------------------------------

	public function test_renders_the_artists_handle_and_a_matching_copy_button(): void {
		$html = $this->render_via_block( $this->post_id );

		$this->assertNotSame( '', $html );

		$handle = ( new ActivityPub() )->handle_for( 'artist', $this->artist_id );
		$this->assertNotSame( '', $handle, 'Fixture precondition: the artist must have a resolvable handle.' );

		$this->assertStringContainsString( '@' . $handle, $html );
		$this->assertStringContainsString( 'data-agnosis-copy-handle="@' . $handle . '"', $html, 'The copy button must carry exactly what is displayed.' );
	}

	/**
	 * 0.9.63's invariant, and the reason `handle_for()` exists at all: the handle
	 * must name the apex domain even when the page is served from an artist
	 * subdomain. WebFinger answers on the apex, so a request-anchored handle would
	 * produce `@rosa@rosa.example.org` — plausible-looking, and resolving to
	 * nothing. `ActivityPubHandleTest` pins the method; this pins the block that
	 * puts its output in front of a visitor.
	 */
	public function test_the_handle_uses_the_apex_domain_not_the_artist_subdomain(): void {
		$apex = wp_parse_url( home_url(), PHP_URL_HOST );
		$this->assertIsString( $apex );

		$html = $this->render_via_block( $this->post_id );

		$this->assertStringContainsString( '@' . $apex, $html );
		$this->assertStringNotContainsString( '@rosa.' . $apex, $html, 'A subdomain-anchored handle resolves to nothing.' );
	}

	// -------------------------------------------------------------------------
	// Accessibility — sixteenth audit A-3
	// -------------------------------------------------------------------------

	/**
	 * frontend.js writes its validation error into this paragraph. Without
	 * aria-live, a screen-reader user submitting an invalid instance domain gets
	 * no feedback whatsoever — the failure is completely silent. Fixed in 0.9.66
	 * with no test attached; this is that test.
	 */
	public function test_the_form_status_paragraph_is_a_live_region(): void {
		$html = $this->render_via_block( $this->post_id );

		$this->assertMatchesRegularExpression(
			'/data-agnosis-follow-status[^>]*aria-live="polite"|aria-live="polite"[^>]*data-agnosis-follow-status/',
			$html,
			'The status paragraph must be a live region or a failed submit is announced to nobody.'
		);
	}

	public function test_the_close_button_and_instance_field_have_accessible_names(): void {
		$html = $this->render_via_block( $this->post_id );

		$this->assertMatchesRegularExpression(
			'/class="lf-icon-btn lf-popover-close"[\s\S]*?aria-label="[^"]+"/',
			$html,
			'An icon-only close button needs an accessible name.'
		);

		// The instance input is labelled by a real <label for>, not a placeholder —
		// same rule A-1 applied to the reply form in 0.9.66.
		$this->assertMatchesRegularExpression( '/<label[^>]*for="agnosis-follow-overlay-' . $this->post_id . '-instance"/', $html );
		$this->assertStringContainsString( 'id="agnosis-follow-overlay-' . $this->post_id . '-instance"', $html );
	}

	/**
	 * Ids are scoped per artwork so a page rendering the block for several
	 * artworks cannot point every label and popover target at the first one.
	 */
	public function test_ids_are_scoped_per_artwork(): void {
		$second = self::id( self::factory()->post->create( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'publish',
			'post_author' => $this->artist_id,
		] ) );

		$first_html  = $this->render_via_block( $this->post_id );
		$second_html = $this->render_via_block( $second );

		$this->assertStringContainsString( 'agnosis-follow-overlay-' . $this->post_id . '"', $first_html );
		$this->assertStringContainsString( 'agnosis-follow-overlay-' . $second . '"', $second_html );
		$this->assertStringNotContainsString( 'agnosis-follow-overlay-' . $this->post_id . '"', $second_html );
	}

	// -------------------------------------------------------------------------
	// Assets and localized data
	// -------------------------------------------------------------------------

	public function test_rendering_enqueues_the_blocks_own_assets(): void {
		$this->render_via_block( $this->post_id );

		$this->assertTrue( wp_style_is( 'agnosis-follow-overlay', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'agnosis-follow-overlay', 'enqueued' ) );
	}

	/**
	 * The localized actorUrl must be the ARTIST's actor. Handing over the node's
	 * would silently subscribe a visitor to the whole node firehose while the UI
	 * says they are following one artist — the exact confusion per-artist actors
	 * were introduced to end.
	 */
	public function test_localized_actor_url_is_the_artists_not_the_nodes(): void {
		$this->render_via_block( $this->post_id );

		$data = wp_scripts()->get_data( 'agnosis-follow-overlay', 'data' );
		$this->assertIsString( $data );

		$start   = strpos( $data, '{' );
		$end     = strrpos( $data, '}' );
		$decoded = ( false !== $start && false !== $end )
			? json_decode( substr( $data, $start, $end - $start + 1 ), true )
			: null;

		$this->assertIsArray( $decoded );

		$activitypub = new ActivityPub();
		$this->assertSame( $activitypub->actor_url_for( 'artist', $this->artist_id ), $decoded['actorUrl'] );
		$this->assertNotSame( $activitypub->actor_url_for( 'node', 0 ), $decoded['actorUrl'] );
	}
}
