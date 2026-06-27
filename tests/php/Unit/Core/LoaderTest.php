<?php
/**
 * Unit tests for Core\Loader.
 *
 * Loader collects action and filter registrations and applies them in one pass
 * via run(). Tests verify that entries are stored correctly with their priority
 * and accepted-args defaults, and that the stored entries carry the right shape.
 *
 * @package Agnosis\Tests\Unit\Core
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\Core;

use Agnosis\Core\Loader;
use PHPUnit\Framework\TestCase;

class LoaderTest extends TestCase {

	private Loader $loader;

	protected function setUp(): void {
		parent::setUp();
		$this->loader = new Loader();
	}

	// -------------------------------------------------------------------------
	// Collection — add_action
	// -------------------------------------------------------------------------

	public function test_add_action_stores_entry(): void {
		$component = new \stdClass();
		$this->loader->add_action( 'init', $component, 'my_callback' );

		$actions = $this->get_actions();

		$this->assertCount( 1, $actions );
		$this->assertSame( 'init',        $actions[0]['hook'] );
		$this->assertSame( $component,    $actions[0]['component'] );
		$this->assertSame( 'my_callback', $actions[0]['callback'] );
	}

	public function test_add_action_defaults_to_priority_ten(): void {
		$this->loader->add_action( 'init', new \stdClass(), 'cb' );

		$this->assertSame( 10, $this->get_actions()[0]['priority'] );
	}

	public function test_add_action_defaults_to_one_accepted_arg(): void {
		$this->loader->add_action( 'init', new \stdClass(), 'cb' );

		$this->assertSame( 1, $this->get_actions()[0]['args'] );
	}

	public function test_add_action_respects_custom_priority_and_args(): void {
		$this->loader->add_action( 'save_post', new \stdClass(), 'cb', 20, 3 );

		$entry = $this->get_actions()[0];
		$this->assertSame( 20, $entry['priority'] );
		$this->assertSame( 3,  $entry['args'] );
	}

	public function test_add_action_accumulates_multiple_entries(): void {
		$obj = new \stdClass();
		$this->loader->add_action( 'init',      $obj, 'a' );
		$this->loader->add_action( 'wp_loaded', $obj, 'b' );
		$this->loader->add_action( 'shutdown',  $obj, 'c' );

		$this->assertCount( 3, $this->get_actions() );
	}

	// -------------------------------------------------------------------------
	// Collection — add_filter
	// -------------------------------------------------------------------------

	public function test_add_filter_stores_entry(): void {
		$component = new \stdClass();
		$this->loader->add_filter( 'the_content', $component, 'filter_cb' );

		$filters = $this->get_filters();

		$this->assertCount( 1, $filters );
		$this->assertSame( 'the_content', $filters[0]['hook'] );
		$this->assertSame( $component,    $filters[0]['component'] );
		$this->assertSame( 'filter_cb',   $filters[0]['callback'] );
	}

	public function test_add_filter_defaults_to_priority_ten(): void {
		$this->loader->add_filter( 'the_title', new \stdClass(), 'cb' );

		$this->assertSame( 10, $this->get_filters()[0]['priority'] );
	}

	public function test_add_filter_defaults_to_one_accepted_arg(): void {
		$this->loader->add_filter( 'the_title', new \stdClass(), 'cb' );

		$this->assertSame( 1, $this->get_filters()[0]['args'] );
	}

	public function test_add_filter_respects_custom_priority_and_args(): void {
		$this->loader->add_filter( 'option_home', new \stdClass(), 'cb', 5, 2 );

		$entry = $this->get_filters()[0];
		$this->assertSame( 5, $entry['priority'] );
		$this->assertSame( 2, $entry['args'] );
	}

	// -------------------------------------------------------------------------
	// Isolation — actions and filters are stored separately
	// -------------------------------------------------------------------------

	public function test_actions_and_filters_are_stored_in_separate_collections(): void {
		$obj = new \stdClass();
		$this->loader->add_action( 'init',        $obj, 'act' );
		$this->loader->add_filter( 'the_content', $obj, 'flt' );

		$this->assertCount( 1, $this->get_actions() );
		$this->assertCount( 1, $this->get_filters() );

		$this->assertSame( 'init',        $this->get_actions()[0]['hook'] );
		$this->assertSame( 'the_content', $this->get_filters()[0]['hook'] );
	}

	// -------------------------------------------------------------------------
	// run() — delegates to WordPress add_action / add_filter
	// -------------------------------------------------------------------------

	public function test_run_does_not_throw_with_registered_entries(): void {
		$obj = new \stdClass();
		$this->loader->add_action( 'init',        $obj, 'act' );
		$this->loader->add_filter( 'the_content', $obj, 'flt' );

		// The global stubs return true; this simply asserts no exception is thrown.
		$this->loader->run();
		$this->assertTrue( true );
	}

	public function test_run_is_safe_with_empty_collections(): void {
		$this->loader->run();
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** @return array<int, array<string, mixed>> */
	private function get_actions(): array {
		$ref = new \ReflectionProperty( Loader::class, 'actions' );
		$ref->setAccessible( true );
		return $ref->getValue( $this->loader );
	}

	/** @return array<int, array<string, mixed>> */
	private function get_filters(): array {
		$ref = new \ReflectionProperty( Loader::class, 'filters' );
		$ref->setAccessible( true );
		return $ref->getValue( $this->loader );
	}
}
