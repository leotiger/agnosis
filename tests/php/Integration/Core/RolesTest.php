<?php
/**
 * Integration tests for Core\Roles.
 *
 * @package Agnosis\Tests\Integration\Core
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Core;

use Agnosis\Core\Roles;

class RolesTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		// Start each test without the role so creation is testable. Safe here
		// (unlike a teardown-side removal — see the note we no longer need
		// below) because it runs *inside* WP_UnitTestCase's per-test DB
		// transaction, so parent::tearDown()'s rollback reverts it.
		remove_role( 'agnosis_artist' );
	}

	// Deliberately no tearDown() override: a prior version called
	// remove_role( 'agnosis_artist' ) *after* parent::tearDown() — i.e.
	// outside the per-test transaction parent::tearDown() had just rolled
	// back — which permanently deleted the role from the process-wide
	// $wp_roles singleton (and the real 'wp_user_roles' option row) for the
	// rest of the entire suite run, not just this test. Every later test
	// class's `$user->add_role( 'agnosis_artist' )` calls kept writing the
	// capability meta fine, but WP_User::roles is filtered against
	// $wp_roles->is_role(), so the role silently never appeared in ->roles
	// again — surfaced as SubdomainNavigationContactIconTest failing only
	// when run as part of the full suite (never in isolation), since Core/
	// sorts before Network/ alphabetically. parent::tearDown()'s rollback
	// already reverts whatever this class's own tests changed; no extra
	// cleanup is needed or safe to add here.

	public function test_ensure_creates_the_role(): void {
		$this->assertNull( get_role( 'agnosis_artist' ), 'Precondition: role must not exist.' );

		( new Roles() )->ensure();

		$this->assertNotNull( get_role( 'agnosis_artist' ) );
	}

	public function test_created_role_has_read_capability(): void {
		( new Roles() )->ensure();

		$this->assertTrue( get_role( 'agnosis_artist' )->has_cap( 'read' ) );
	}

	public function test_created_role_has_agnosis_artist_capability(): void {
		( new Roles() )->ensure();

		$this->assertTrue( get_role( 'agnosis_artist' )->has_cap( 'agnosis_artist' ) );
	}

	public function test_ensure_is_idempotent(): void {
		$roles = new Roles();
		$roles->ensure();
		$roles->ensure(); // Second call must not throw or duplicate.

		$this->assertNotNull( get_role( 'agnosis_artist' ) );
	}

	public function test_ensure_does_not_overwrite_existing_role(): void {
		// Pre-create the role with an extra capability.
		add_role( 'agnosis_artist', 'Agnosis Artist', [ 'read' => true, 'agnosis_artist' => true, 'custom_extra' => true ] );

		( new Roles() )->ensure();

		// Role must still exist and retain the extra cap (ensure() is a no-op when present).
		$role = get_role( 'agnosis_artist' );
		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( 'custom_extra' ) );
	}
}
