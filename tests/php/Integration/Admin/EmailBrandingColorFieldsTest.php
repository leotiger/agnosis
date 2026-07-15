<?php
/**
 * Integration tests — the two new Settings → General → Branding color
 * fields (agnosis_email_header_bg / agnosis_email_accent) that let an
 * operator re-brand every outgoing HTML email (audit-adjacent finding, not
 * a numbered audit item — see CHANGELOG.md 0.9.29, and Core\EmailTemplate,
 * which actually reads these two options at send time).
 *
 * field_definitions() and render_field() are both private, exercised via
 * ReflectionMethod — same pattern SettingsResettableFieldsTest already uses.
 *
 * @package Agnosis\Tests\Integration\Admin
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Admin;

use Agnosis\Admin\Settings;

class EmailBrandingColorFieldsTest extends \WP_UnitTestCase {

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

	protected function tearDown(): void {
		delete_option( 'agnosis_email_header_bg' );
		delete_option( 'agnosis_email_accent' );
		parent::tearDown();
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
	// Field definitions
	// -------------------------------------------------------------------------

	public function test_both_color_fields_are_defined_with_the_original_hardcoded_defaults(): void {
		$fields = $this->fields();

		$this->assertArrayHasKey( 'agnosis_email_header_bg', $fields );
		$this->assertSame( 'color', $fields['agnosis_email_header_bg']['input'] );
		$this->assertSame( '#0d0d12', $fields['agnosis_email_header_bg']['default'] );

		$this->assertArrayHasKey( 'agnosis_email_accent', $fields );
		$this->assertSame( 'color', $fields['agnosis_email_accent']['input'] );
		$this->assertSame( '#7c6af7', $fields['agnosis_email_accent']['default'] );
	}

	// -------------------------------------------------------------------------
	// render_field()
	// -------------------------------------------------------------------------

	public function test_color_field_renders_a_native_color_input_with_the_stored_value(): void {
		update_option( 'agnosis_email_accent', '#123456' );
		$fields = $this->fields();

		$html = $this->render( 'agnosis_email_accent', $fields['agnosis_email_accent'] );

		$this->assertStringContainsString( 'type="color"', $html );
		$this->assertStringContainsString( 'value="#123456"', $html );
	}

	public function test_color_field_renders_a_reset_to_default_button(): void {
		$fields = $this->fields();

		$html = $this->render( 'agnosis_email_header_bg', $fields['agnosis_email_header_bg'] );

		$this->assertStringContainsString( 'agnosis-reset-default', $html );
		$this->assertStringContainsString( 'data-target="agnosis_email_header_bg"', $html );
		$this->assertStringContainsString( 'data-default="#0d0d12"', $html );
	}

	// -------------------------------------------------------------------------
	// Sanitize callback (register_setting())
	// -------------------------------------------------------------------------

	public function test_sanitize_callback_accepts_a_valid_hex_colour(): void {
		$fields   = $this->fields();
		$sanitize = $fields['agnosis_email_accent']['sanitize'];

		$this->assertSame( '#abc123', $sanitize( '#abc123' ) );
	}

	public function test_sanitize_callback_falls_back_to_default_on_invalid_input(): void {
		$fields   = $this->fields();
		$sanitize = $fields['agnosis_email_accent']['sanitize'];

		$this->assertSame( '#7c6af7', $sanitize( 'not-a-colour' ) );
		$this->assertSame( '#7c6af7', $sanitize( '' ) );
		$this->assertSame( '#7c6af7', $sanitize( '<script>alert(1)</script>' ) );
	}

	public function test_header_bg_sanitize_callback_falls_back_to_its_own_default(): void {
		$fields   = $this->fields();
		$sanitize = $fields['agnosis_email_header_bg']['sanitize'];

		$this->assertSame( '#0d0d12', $sanitize( 'nope' ) );
	}
}
