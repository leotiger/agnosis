<?php
/**
 * Integration tests — agnosis/newsletter-signup block rendering.
 *
 * @package Agnosis\Tests\Integration\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Newsletter;

use Agnosis\Newsletter\SignupBlock;

class SignupBlockTest extends \WP_UnitTestCase {

	private SignupBlock $block;

	protected function setUp(): void {
		parent::setUp();
		$this->block = new SignupBlock();
	}

	public function test_render_block_is_empty_when_public_newsletter_disabled(): void {
		update_option( 'agnosis_newsletter_public_enabled', false );

		$html = $this->block->render_block();

		$this->assertSame( '', $html );
	}

	public function test_render_block_outputs_form_when_enabled(): void {
		update_option( 'agnosis_newsletter_public_enabled', true );

		$html = $this->block->render_block();

		$this->assertStringContainsString( '<form', $html );
		$this->assertStringContainsString( 'type="email"', $html );
		$this->assertStringContainsString( 'agnosis-newsletter-signup', $html );
	}
}
