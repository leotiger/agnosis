<?php
/**
 * Integration tests — Settings' "Reset to default" affordance for textarea
 * fields carrying substantial built-in copy (system prompt, artist prompt
 * template, enhancement instructions, trusted embed platforms, invitation
 * intro). Before this, overwriting one of these meant losing the plugin's
 * original text for good — render_field() now emits a `.agnosis-reset-default`
 * button (wired up client-side by reset_default_js()) carrying the built-in
 * default in a data attribute, for fields explicitly flagged 'resettable'.
 *
 * field_definitions() and render_field() are both private, exercised via
 * ReflectionMethod/ReflectionClass — same pattern SettingsNewsletterLocaleTest
 * already uses for this class.
 *
 * @package Agnosis\Tests\Integration\Admin
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Admin;

use Agnosis\Admin\Settings;

class SettingsResettableFieldsTest extends \WP_UnitTestCase {

	private Settings $settings;
	private \ReflectionMethod $field_definitions;
	private \ReflectionMethod $render_field;

	protected function setUp(): void {
		parent::setUp();

		$this->settings = new Settings();

		$rc = new \ReflectionClass( Settings::class );

		$this->field_definitions = $rc->getMethod( 'field_definitions' );
		$this->field_definitions->setAccessible( true );

		$this->render_field = $rc->getMethod( 'render_field' );
		$this->render_field->setAccessible( true );
	}

	/** @return array<string, array<string, mixed>> */
	private function fields(): array {
		return $this->field_definitions->invoke( $this->settings );
	}

	private function render( string $key, array $field ): string {
		ob_start();
		$this->render_field->invoke( $this->settings, $key, $field );
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Every field expected to carry a "Reset to default" button is actually
	// flagged resettable, with a non-empty default to reset to.
	// -------------------------------------------------------------------------

	public function test_expected_fields_are_flagged_resettable_with_a_default(): void {
		$fields = $this->fields();

		foreach ( [
			'agnosis_prompt_system',
			'agnosis_prompt_user_template',
			'agnosis_prompt_enhancement',
			'agnosis_embed_trusted_hosts',
			'agnosis_invitation_intro',
		] as $key ) {
			$this->assertArrayHasKey( $key, $fields );
			$this->assertTrue( $fields[ $key ]['resettable'] ?? false, "$key should be flagged resettable." );
			$this->assertNotSame( '', trim( (string) ( $fields[ $key ]['default'] ?? '' ) ), "$key should have a non-empty built-in default to reset to." );
		}
	}

	public function test_ordinary_textarea_fields_are_not_flagged_resettable(): void {
		$fields = $this->fields();

		// agnosis_embed_block_custom is a textarea but has no meaningful
		// built-in default (empty string) — nothing to reset to, so it must
		// not carry the button.
		$this->assertArrayHasKey( 'agnosis_embed_block_custom', $fields );
		$this->assertFalse( $fields['agnosis_embed_block_custom']['resettable'] ?? false );
	}

	// -------------------------------------------------------------------------
	// render_field() actually emits (or omits) the button correctly
	// -------------------------------------------------------------------------

	public function test_resettable_textarea_renders_reset_button_with_default_in_data_attribute(): void {
		$fields = $this->fields();
		$html   = $this->render( 'agnosis_prompt_system', $fields['agnosis_prompt_system'] );

		$this->assertStringContainsString( 'agnosis-reset-default', $html );
		$this->assertStringContainsString( 'data-target="agnosis_prompt_system"', $html );
		$this->assertStringContainsString( esc_attr( \Agnosis\AI\PromptConfig::default_system_prompt() ), $html );
	}

	public function test_non_resettable_textarea_has_no_reset_button(): void {
		$fields = $this->fields();
		$html   = $this->render( 'agnosis_embed_block_custom', $fields['agnosis_embed_block_custom'] );

		$this->assertStringNotContainsString( 'agnosis-reset-default', $html );
	}

	public function test_non_textarea_field_ignores_resettable_flag_gracefully(): void {
		// Sanity check: a text/number/checkbox field is never given 'resettable',
		// and render_field()'s reset-button branch lives only in the textarea
		// case, so this must render without notices or a stray button.
		$fields = $this->fields();
		$html   = $this->render( 'agnosis_prompt_tag_count', $fields['agnosis_prompt_tag_count'] );

		$this->assertStringNotContainsString( 'agnosis-reset-default', $html );
	}
}
