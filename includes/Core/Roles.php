<?php
/**
 * Custom role registration.
 *
 * Ensures the agnosis_artist role exists on every init so it is available
 * in test environments and fresh installs even before the activation hook runs.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

class Roles {

	/**
	 * Ensure the agnosis_artist role exists.
	 *
	 * Idempotent — safe to call on every init; add_role() is a no-op when
	 * the role already exists.
	 */
	public function ensure(): void {
		if ( null === get_role( 'agnosis_artist' ) ) {
			add_role(
				'agnosis_artist',
				__( 'Agnosis Artist', 'agnosis' ),
				[
					'read'           => true,
					'agnosis_artist' => true,
				]
			);
		}
	}
}
