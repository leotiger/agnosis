<?php
/**
 * Hook loader — collects all action/filter registrations and applies them in one pass.
 *
 * Keeps every service class free of direct add_action / add_filter calls,
 * making unit-testing trivial and dependency tracking explicit.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

class Loader {

	/** @var array<int, array{hook: string, component: object, callback: string, priority: int, args: int}> */
	private array $actions = [];

	/** @var array<int, array{hook: string, component: object, callback: string, priority: int, args: int}> */
	private array $filters = [];

	public function add_action(
		string $hook,
		object $component,
		string $callback,
		int $priority = 10,
		int $accepted_args = 1
	): void {
		$this->actions[] = compact( 'hook', 'component', 'callback', 'priority' ) + [ 'args' => $accepted_args ];
	}

	public function add_filter(
		string $hook,
		object $component,
		string $callback,
		int $priority = 10,
		int $accepted_args = 1
	): void {
		$this->filters[] = compact( 'hook', 'component', 'callback', 'priority' ) + [ 'args' => $accepted_args ];
	}

	/** Register everything with WordPress. */
	public function run(): void {
		foreach ( $this->filters as $f ) {
			// @phpstan-ignore-next-line — dynamic [object, method] callbacks are valid callables at runtime.
			add_filter( $f['hook'], [ $f['component'], $f['callback'] ], $f['priority'], $f['args'] );
		}
		foreach ( $this->actions as $a ) {
			// @phpstan-ignore-next-line — dynamic [object, method] callbacks are valid callables at runtime.
			add_action( $a['hook'], [ $a['component'], $a['callback'] ], $a['priority'], $a['args'] );
		}
	}
}
