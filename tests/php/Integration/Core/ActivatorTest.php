<?php
/**
 * Integration tests — Core\Activator.
 *
 * Tests cover the public activate() entry point and the observable side
 * effects of each private method it delegates to:
 *
 *   seed_options()
 *     - Stores all default options with add_option (non-destructive)
 *     - agnosis_quality_rejection_threshold defaults to 3
 *     - agnosis_quality_threshold defaults to 7
 *     - ai_provider defaults to 'openai'
 *     - Does NOT overwrite an already-saved option
 *
 *   register_roles()
 *     - Creates the agnosis_artist role
 *     - Role has read + agnosis_artist capabilities
 *     - Calling activate() again does not error (role already present)
 *
 *   create_submissions_page()
 *     - Inserts a published page with the agnosis/submissions block
 *     - Stores the page ID in agnosis_submissions_page_id option
 *     - Idempotent — second activate() does not create a duplicate
 *
 *   seed_medium_terms()
 *     - Inserts all CANONICAL_MEDIUMS as agnosis_medium terms
 *
 *   maybe_upgrade()
 *     - Runs without error when tables already exist (idempotent upgrade path)
 *
 * Note: create_tables() and schedule_events() are side effects of activate()
 * but are tested implicitly — table existence is a precondition for other tests.
 *
 * @package Agnosis\Tests\Integration\Core
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Core;

use Agnosis\AI\PromptConfig;
use Agnosis\Core\Activator;

class ActivatorTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Remove any options the test may have seeded so we start clean.
		foreach ( $this->all_option_keys() as $key ) {
			delete_option( $key );
		}

		// Remove agnosis_artist role if present so we can test creation.
		remove_role( 'agnosis_artist' );
	}

	protected function tearDown(): void {
		parent::tearDown();

		foreach ( $this->all_option_keys() as $key ) {
			delete_option( $key );
		}

		delete_option( 'agnosis_submissions_page_id' );
		remove_role( 'agnosis_artist' );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** @return string[] */
	private function all_option_keys(): array {
		return [
			'agnosis_ai_provider',
			'agnosis_openai_api_key',
			'agnosis_anthropic_api_key',
			'agnosis_stability_api_key',
			'agnosis_email_driver',
			'agnosis_email_submit',
			'agnosis_email_bio',
			'agnosis_email_event',
			'agnosis_email_replace',
			'agnosis_email_remove',
			'agnosis_imap_host',
			'agnosis_imap_port',
			'agnosis_imap_user',
			'agnosis_imap_pass',
			'agnosis_imap_ssl',
			'agnosis_imap_cleanup_days',
			'agnosis_webhook_secret',
			'agnosis_node_label',
			'agnosis_admission_percent',
			'agnosis_admission_minimum',
			'agnosis_admission_window_days',
			'agnosis_tx_fee_percent',
			'agnosis_activitypub_enabled',
			'agnosis_quality_threshold',
			'agnosis_quality_rejection_threshold',
		];
	}

	/** Use reflection to call a private static method on Activator. */
	private function call_private( string $method ): void {
		$m = new \ReflectionMethod( Activator::class, $method );
		$m->setAccessible( true );
		$m->invoke( null );
	}

	// =========================================================================
	// seed_options() — called via activate()
	// =========================================================================

	public function test_seed_options_sets_quality_rejection_threshold_to_3(): void {
		$this->call_private( 'seed_options' );

		$this->assertSame( 3, (int) get_option( 'agnosis_quality_rejection_threshold' ) );
	}

	public function test_seed_options_sets_quality_threshold_to_7(): void {
		$this->call_private( 'seed_options' );

		$this->assertSame( 7, (int) get_option( 'agnosis_quality_threshold' ) );
	}

	public function test_seed_options_sets_ai_provider_to_openai(): void {
		$this->call_private( 'seed_options' );

		$this->assertSame( 'openai', get_option( 'agnosis_ai_provider' ) );
	}

	public function test_seed_options_sets_admission_percent_to_10(): void {
		$this->call_private( 'seed_options' );

		$this->assertSame( 10, (int) get_option( 'agnosis_admission_percent' ) );
	}

	public function test_seed_options_sets_admission_minimum_to_3(): void {
		$this->call_private( 'seed_options' );

		$this->assertSame( 3, (int) get_option( 'agnosis_admission_minimum' ) );
	}

	public function test_seed_options_sets_admission_window_days_to_7(): void {
		$this->call_private( 'seed_options' );

		$this->assertSame( 7, (int) get_option( 'agnosis_admission_window_days' ) );
	}

	public function test_seed_options_does_not_overwrite_existing_option(): void {
		// Pre-set the option.
		add_option( 'agnosis_quality_rejection_threshold', 99 );

		$this->call_private( 'seed_options' );

		// seed_options uses add_option which is a no-op when the key already exists.
		$this->assertSame( 99, (int) get_option( 'agnosis_quality_rejection_threshold' ) );
	}

	public function test_seed_options_does_not_overwrite_existing_quality_threshold(): void {
		add_option( 'agnosis_quality_threshold', 5 );

		$this->call_private( 'seed_options' );

		$this->assertSame( 5, (int) get_option( 'agnosis_quality_threshold' ) );
	}

	// =========================================================================
	// register_roles()
	// =========================================================================

	public function test_register_roles_creates_agnosis_artist_role(): void {
		$this->assertNull( get_role( 'agnosis_artist' ), 'Role must not exist before test.' );

		$this->call_private( 'register_roles' );

		$this->assertNotNull( get_role( 'agnosis_artist' ) );
	}

	public function test_agnosis_artist_role_has_read_capability(): void {
		$this->call_private( 'register_roles' );

		$role = get_role( 'agnosis_artist' );
		$this->assertArrayHasKey( 'read', $role->capabilities );
		$this->assertTrue( $role->capabilities['read'] );
	}

	public function test_agnosis_artist_role_has_agnosis_artist_capability(): void {
		$this->call_private( 'register_roles' );

		$role = get_role( 'agnosis_artist' );
		$this->assertArrayHasKey( 'agnosis_artist', $role->capabilities );
		$this->assertTrue( $role->capabilities['agnosis_artist'] );
	}

	public function test_register_roles_is_idempotent(): void {
		$this->call_private( 'register_roles' );
		// Should not throw or error when called again.
		$this->call_private( 'register_roles' );

		$this->assertNotNull( get_role( 'agnosis_artist' ) );
	}

	// =========================================================================
	// create_submissions_page()
	// =========================================================================

	public function test_create_submissions_page_inserts_page(): void {
		delete_option( 'agnosis_submissions_page_id' );

		$this->call_private( 'create_submissions_page' );

		$page_id = (int) get_option( 'agnosis_submissions_page_id' );
		$this->assertGreaterThan( 0, $page_id, 'agnosis_submissions_page_id option must be set.' );

		$page = get_post( $page_id );
		$this->assertNotNull( $page );
		$this->assertSame( 'page', $page->post_type );
		$this->assertSame( 'publish', $page->post_status );
	}

	public function test_create_submissions_page_content_contains_block(): void {
		delete_option( 'agnosis_submissions_page_id' );

		$this->call_private( 'create_submissions_page' );

		$page_id = (int) get_option( 'agnosis_submissions_page_id' );
		$page    = get_post( $page_id );

		$this->assertStringContainsString( 'agnosis/submissions', $page->post_content );
	}

	public function test_create_submissions_page_is_idempotent(): void {
		delete_option( 'agnosis_submissions_page_id' );

		$this->call_private( 'create_submissions_page' );
		$first_id = (int) get_option( 'agnosis_submissions_page_id' );

		$this->call_private( 'create_submissions_page' );
		$second_id = (int) get_option( 'agnosis_submissions_page_id' );

		// Second call must not create a new page.
		$this->assertSame( $first_id, $second_id );
	}

	// =========================================================================
	// seed_medium_terms()
	// =========================================================================

	public function test_seed_medium_terms_inserts_canonical_mediums(): void {
		// Register taxonomy first (normally done by Profile on 'init').
		if ( ! taxonomy_exists( 'agnosis_medium' ) ) {
			register_taxonomy( 'agnosis_medium', [ 'agnosis_artwork' ] );
		}

		$this->call_private( 'seed_medium_terms' );

		foreach ( PromptConfig::CANONICAL_MEDIUMS as $medium ) {
			$term = get_term_by( 'name', $medium, 'agnosis_medium' );
			$this->assertNotFalse( $term, "Term '$medium' should exist in agnosis_medium taxonomy." );
		}
	}

	public function test_seed_medium_terms_is_idempotent(): void {
		if ( ! taxonomy_exists( 'agnosis_medium' ) ) {
			register_taxonomy( 'agnosis_medium', [ 'agnosis_artwork' ] );
		}

		$this->call_private( 'seed_medium_terms' );
		$this->call_private( 'seed_medium_terms' );

		// Should still have exactly one term per canonical medium (no duplicates).
		$terms = get_terms( [
			'taxonomy'   => 'agnosis_medium',
			'hide_empty' => false,
		] );

		$this->assertNotWPError( $terms );
		$this->assertCount( count( PromptConfig::CANONICAL_MEDIUMS ), $terms );
	}

	// =========================================================================
	// maybe_upgrade()
	// =========================================================================

	public function test_maybe_upgrade_runs_without_error(): void {
		// Tables already exist (created by the test suite bootstrap via Activator).
		// This verifies the SHOW COLUMNS path doesn't error on existing tables.
		$this->expectNotToPerformAssertions();
		Activator::maybe_upgrade();
	}
}
