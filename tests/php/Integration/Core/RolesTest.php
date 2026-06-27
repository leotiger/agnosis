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
		// Start each test without the role so creation is testable.
		remove_role( 'agnosis_artist' );
	}

	protected function tearDown(): void {
		parent::tearDown();
		remove_role( 'agnosis_artist' );
	}

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
