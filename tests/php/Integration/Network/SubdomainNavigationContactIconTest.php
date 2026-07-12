<?php
/**
 * Integration tests — SubdomainNavigation's `type=contact` breadcrumb icon
 * (added 2026-07-12 alongside the contact-form feature).
 *
 * Unlike the biography/events variants (a plain <a href>), `type=contact`
 * renders a popover trigger <button> plus the popover panel itself
 * (embedding the agnosis/contact-form block) — see
 * render_contact_icon_link()'s own docblock. Covers:
 *
 *   - Renders nothing off an artist subdomain (no artist_id resolved).
 *   - Renders nothing when the current artist has opted out of contact
 *     (Artist\ContactForm::artist_accepts_contact()) — same gate the form
 *     block itself enforces, checked twice deliberately.
 *   - Renders the trigger button + popover panel (with the embedded
 *     contact-form block inside) when the artist accepts contact.
 *   - The 'icon' attribute picks between the two registered glyphs.
 *
 * Every scenario dispatches through the REAL WP render_block() pipeline
 * (render_via_block_dispatch() below) rather than calling
 * render_breadcrumb_icon_link_block() directly, deliberately — that method
 * (and render_contact_icon_link(), which it delegates to for type=contact)
 * calls get_block_wrapper_attributes(), which reads WP core's
 * WP_Block_Supports::$block_to_render internally. That's only ever populated
 * when a block renders through a genuine WP_Block::render() call — calling
 * the render_callback directly (the pattern every OTHER block test in this
 * codebase safely uses, since none of those blocks touch
 * get_block_wrapper_attributes()) leaves it unset and trips a
 * "Trying to access array offset on null" error deep in WP core. Dispatching
 * through render_block() for real, exactly as the theme template does via
 * `<!-- wp:agnosis/breadcrumb-icon-link {"type":"contact"} /-->`, is both the
 * fix and a more faithful test of actual production behaviour.
 *
 * @package Agnosis\Tests\Integration\Network
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Network;

use Agnosis\Artist\ContactFormBlock;
use Agnosis\Network\SubdomainNavigation;
use Agnosis\Network\SubdomainRouter;

class SubdomainNavigationContactIconTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// render_contact_icon_link() embeds agnosis/contact-form via WP's own
		// render_block() dispatch (mirrors Newsletter\PopoverBlock's identical
		// technique for agnosis/newsletter-signup), and this file now
		// dispatches agnosis/breadcrumb-icon-link itself the same way (see
		// class docblock) — both registrations need to exist. Plugin::run()
		// registers both on 'init' in production, which has already fired
		// once by the time any test in this process runs, but nothing
		// guarantees that survived intact into this specific test run, so
		// it's asserted here explicitly. Guarded against re-registering an
		// already-registered block type, which would otherwise trip a
		// _doing_it_wrong() notice.
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'agnosis/contact-form' ) ) {
			( new ContactFormBlock() )->register_block();
		}
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'agnosis/breadcrumb-icon-link' ) ) {
			( new SubdomainNavigation() )->register_breadcrumb_icon_link_block();
		}
	}

	protected function tearDown(): void {
		$this->set_current_artist( null );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Simulate SubdomainRouter::boot() having resolved a given artist (or none). */
	private function set_current_artist( ?int $artist_id ): void {
		$ref = new \ReflectionProperty( SubdomainRouter::class, 'artist_id' );
		$ref->setAccessible( true );
		$ref->setValue( null, $artist_id );
	}

	private function create_artist( string $email = 'contact-icon-artist@example.com' ): int {
		$id = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => $email ] );
		get_userdata( $id )->add_role( 'agnosis_artist' );
		return $id;
	}

	/**
	 * Render agnosis/breadcrumb-icon-link through WP's real render_block()
	 * pipeline — see class docblock for why this, not a direct
	 * render_breadcrumb_icon_link_block() call, is required here.
	 *
	 * @param array<string, mixed> $attrs Block attributes ('type', 'icon').
	 */
	private function render_via_block_dispatch( array $attrs ): string {
		return render_block( [
			'blockName'    => 'agnosis/breadcrumb-icon-link',
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		] );
	}

	// -------------------------------------------------------------------------
	// Off an artist subdomain
	// -------------------------------------------------------------------------

	public function test_renders_nothing_off_an_artist_subdomain(): void {
		$this->set_current_artist( null );

		$html = $this->render_via_block_dispatch( [ 'type' => 'contact' ] );

		$this->assertSame( '', $html );
	}

	// -------------------------------------------------------------------------
	// Opt-out gating
	// -------------------------------------------------------------------------

	public function test_renders_nothing_when_artist_has_opted_out(): void {
		$artist_id = $this->create_artist();
		update_user_meta( $artist_id, '_agnosis_contact_optout', '1' );
		$this->set_current_artist( $artist_id );

		$html = $this->render_via_block_dispatch( [ 'type' => 'contact' ] );

		$this->assertSame( '', $html );
	}

	public function test_renders_nothing_for_a_non_artist_user_id(): void {
		$non_artist = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->set_current_artist( $non_artist );

		$html = $this->render_via_block_dispatch( [ 'type' => 'contact' ] );

		$this->assertSame( '', $html );
	}

	// -------------------------------------------------------------------------
	// Renders trigger + popover + embedded form
	// -------------------------------------------------------------------------

	public function test_renders_trigger_button_and_popover_panel_when_artist_accepts_contact(): void {
		$artist_id = $this->create_artist();
		$this->set_current_artist( $artist_id );

		$html = $this->render_via_block_dispatch( [ 'type' => 'contact' ] );

		$this->assertStringContainsString( 'popovertarget="agnosis-contact-popover"', $html );
		$this->assertStringContainsString( 'popovertargetaction="show"', $html );
		$this->assertStringContainsString( 'id="agnosis-contact-popover"', $html );
		$this->assertStringContainsString( 'popover="auto"', $html );
		// The embedded agnosis/contact-form block's own markup must be present.
		$this->assertStringContainsString( 'agnosis-contact-form', $html );
		$this->assertStringContainsString( 'name="email"', $html );
		$this->assertStringContainsString( 'name="message"', $html );
	}

	public function test_default_icon_is_mail_envelope(): void {
		$artist_id = $this->create_artist();
		$this->set_current_artist( $artist_id );

		$html = $this->render_via_block_dispatch( [ 'type' => 'contact' ] );

		// The 'mail' glyph's distinguishing path data (envelope flap).
		$this->assertStringContainsString( 'm2 6 10 7 10-7', $html );
	}

	public function test_icon_attribute_selects_the_speech_bubble_variant(): void {
		$artist_id = $this->create_artist();
		$this->set_current_artist( $artist_id );

		$html = $this->render_via_block_dispatch( [ 'type' => 'contact', 'icon' => 'message' ] );

		$this->assertStringContainsString( 'M21 15a2 2 0 0 1-2 2H7l-4 4V5', $html );
	}

	public function test_unrecognized_type_falls_back_to_biography(): void {
		$artist_id = $this->create_artist();
		$this->set_current_artist( $artist_id );

		$html = $this->render_via_block_dispatch( [ 'type' => 'not-a-real-type' ] );

		// Falls back to the biography branch — no biography post exists for
		// this artist yet, so it correctly renders nothing, same as an
		// explicit type=biography would for an artist without one.
		$this->assertSame( '', $html );
	}
}
