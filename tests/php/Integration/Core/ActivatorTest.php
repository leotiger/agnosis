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
	// create_about_page() / create_help_page() — out-of-the-box content pages
	// =========================================================================

	public function test_create_about_page_inserts_managed_page(): void {
		delete_option( 'agnosis_about_page_id' );

		$this->call_private( 'create_about_page' );

		$page_id = (int) get_option( 'agnosis_about_page_id' );
		$this->assertGreaterThan( 0, $page_id );

		$page = get_post( $page_id );
		$this->assertNotNull( $page );
		$this->assertSame( 'page', $page->post_type );
		$this->assertSame( 'publish', $page->post_status );
		$this->assertSame( 'about', $page->post_name );
		// The theme's page.html template renders the title via wp:post-title,
		// so post_content must not duplicate it with its own H1 — the page's
		// title lives in post_title only.
		$this->assertSame( 'About', $page->post_title );
		$this->assertStringNotContainsString( '<h1', $page->post_content );
		$this->assertStringContainsString( 'How we use AI', $page->post_content );
		// Tagged for uninstall cleanup.
		$this->assertSame( '1', get_post_meta( $page_id, '_agnosis_managed_page', true ) );
	}

	public function test_create_help_page_inserts_managed_page(): void {
		delete_option( 'agnosis_help_page_id' );

		$this->call_private( 'create_help_page' );

		$page_id = (int) get_option( 'agnosis_help_page_id' );
		$this->assertGreaterThan( 0, $page_id );

		$page = get_post( $page_id );
		$this->assertSame( 'artist-guide', $page->post_name );
		// Title lives in post_title only — no duplicate H1 in post_content
		// (the theme's page.html template renders the title via wp:post-title).
		$this->assertSame( 'Artist Guide', $page->post_title );
		$this->assertStringNotContainsString( '<h1', $page->post_content );
		$this->assertStringContainsString( 'Sending a series or set of images', $page->post_content );
		$this->assertSame( '1', get_post_meta( $page_id, '_agnosis_managed_page', true ) );
	}

	public function test_content_pages_are_idempotent(): void {
		delete_option( 'agnosis_about_page_id' );
		delete_option( 'agnosis_help_page_id' );

		$this->call_private( 'create_about_page' );
		$this->call_private( 'create_help_page' );
		$about_first = (int) get_option( 'agnosis_about_page_id' );
		$help_first  = (int) get_option( 'agnosis_help_page_id' );

		$this->call_private( 'create_about_page' );
		$this->call_private( 'create_help_page' );

		$this->assertSame( $about_first, (int) get_option( 'agnosis_about_page_id' ) );
		$this->assertSame( $help_first, (int) get_option( 'agnosis_help_page_id' ) );
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

	// =========================================================================
	// maybe_upgrade() — agnosis_newsletter_queue.sent_at -> resolved_at (§3f)
	// =========================================================================

	/**
	 * Put agnosis_newsletter_queue back into its pre-0.4.3 shape (sent_at,
	 * no resolved_at) so maybe_upgrade()'s rename path can be exercised.
	 *
	 * Guarded/idempotent rather than a blind DROP + ADD: ALTER TABLE is DDL,
	 * which implicit-commits in MySQL/InnoDB and so is never undone by the
	 * per-test transaction rollback the WP test suite normally relies on for
	 * isolation. A prior run of this fixture that was interrupted before
	 * Activator::maybe_upgrade() renamed the column back (e.g. a failed
	 * assertion, or these two tests running out of the order PHPUnit happened
	 * to pick) would otherwise leave sent_at already present, and a second
	 * blind "ADD COLUMN sent_at" would fail with a duplicate-column DB error —
	 * which is exactly what surfaced running the full suite. A single
	 * existence-checked CHANGE COLUMN (matching Activator::maybe_upgrade()'s
	 * own defensive style) is safe to call repeatedly from either state.
	 */
	private function simulate_legacy_sent_at_column(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_sent_at = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}agnosis_newsletter_queue LIKE 'sent_at'" );
		if ( ! empty( $has_sent_at ) ) {
			return; // Already in the legacy shape — nothing to do.
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_resolved_at = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}agnosis_newsletter_queue LIKE 'resolved_at'" );
		if ( ! empty( $has_resolved_at ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}agnosis_newsletter_queue CHANGE COLUMN resolved_at sent_at DATETIME DEFAULT NULL" );
		} else {
			// Neither column present somehow — add the legacy one fresh.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}agnosis_newsletter_queue ADD COLUMN sent_at DATETIME DEFAULT NULL" );
		}
	}

	public function test_maybe_upgrade_renames_legacy_sent_at_to_resolved_at(): void {
		global $wpdb;

		// Simulate a pre-0.4.3 install: rename resolved_at back to the old sent_at.
		$this->simulate_legacy_sent_at_column();

		Activator::maybe_upgrade();

		$has_resolved = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}agnosis_newsletter_queue LIKE 'resolved_at'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_sent_at  = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}agnosis_newsletter_queue LIKE 'sent_at'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->assertNotEmpty( $has_resolved, 'resolved_at must exist after upgrade.' );
		$this->assertEmpty( $has_sent_at, 'The legacy sent_at column name must be gone (renamed, not duplicated).' );
	}

	public function test_maybe_upgrade_preserves_queue_row_data_when_renaming_sent_at(): void {
		global $wpdb;

		$this->simulate_legacy_sent_at_column();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_newsletter_queue',
			[
				'issue_id'          => 999999,
				'recipient_email'   => 'legacy@example.com',
				'recipient_type'    => 'public',
				'unsubscribe_token' => 'legacytoken',
				'status'            => 'sent',
				'sent_at'           => '2026-01-01 00:00:00',
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		Activator::maybe_upgrade();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var( $wpdb->prepare(
			"SELECT resolved_at FROM {$wpdb->prefix}agnosis_newsletter_queue WHERE recipient_email = %s",
			'legacy@example.com'
		) );

		$this->assertSame( '2026-01-01 00:00:00', $value, 'Existing data must survive the rename.' );
	}

	// =========================================================================
	// maybe_upgrade() — subscribers.email VARCHAR(255) -> VARCHAR(191) (§3f/§2d)
	// =========================================================================

	public function test_maybe_upgrade_shortens_legacy_email_column_width(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}agnosis_newsletter_subscribers MODIFY COLUMN email VARCHAR(255) NOT NULL" );

		Activator::maybe_upgrade();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$col = $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}agnosis_newsletter_subscribers LIKE 'email'" );
		$this->assertStringContainsString( 'varchar(191)', strtolower( (string) $col->Type ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	// =========================================================================
	// maybe_upgrade() — rewrite-rule flush for existing installs (2026-07-06)
	// =========================================================================

	/**
	 * New rewrite rules (Newsletter\Archive's /newsletter/ routes) only take
	 * effect on an existing install once WordPress regenerates its rewrite
	 * rules. Rather than flushing unconditionally on every plugins_loaded
	 * (expensive), this is deferred to run once on init — same pattern as
	 * create_managed_pages() just above it in maybe_upgrade().
	 */
	public function test_maybe_upgrade_defers_a_rewrite_flush_to_init(): void {
		remove_all_actions( 'init' );

		Activator::maybe_upgrade();

		$this->assertGreaterThan( 0, has_action( 'init', 'flush_rewrite_rules' ), 'maybe_upgrade() must schedule a rewrite flush on init for existing installs to pick up new rewrite rules.' );
	}
}
