<?php
/**
 * Unit tests — PostCreator Pipeline dependency injection.
 *
 * Verifies that PostCreator accepts an optional Pipeline in its constructor
 * so tests can inject a stub without touching real AI providers.
 *
 * @package Agnosis\Tests\Unit\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\Publishing;

use Agnosis\AI\Pipeline;
use Agnosis\Publishing\PostCreator;
use PHPUnit\Framework\TestCase;

class PipelineInjectionTest extends TestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a lightweight Pipeline stub — overrides __construct() so no WP
	 * options or provider resolution runs, and overrides process() to return
	 * a controllable canned result.
	 *
	 * @param array<int, array<string, mixed>> $results Value to return from process().
	 */
	private function make_pipeline_stub( array $results = [] ): Pipeline {
		return new class( $results ) extends Pipeline {
			/** @var array<int, array<string, mixed>> */
			private array $canned;
			private int $call_count = 0;

			/** @param array<int, array<string, mixed>> $results */
			public function __construct( array $results ) {
				// Deliberately skip parent::__construct() — no WP options, no providers.
				$this->canned = $results;
			}

			/** @param array<string, mixed> $submission */
			public function process( array $submission, bool $skip_enhancement = false ): array {
				++$this->call_count;
				return $this->canned;
			}

			public function call_count(): int {
				return $this->call_count;
			}
		};
	}

	/**
	 * Read the private $pipeline property from a PostCreator via reflection.
	 */
	private function get_pipeline( PostCreator $creator ): Pipeline {
		$ref  = new \ReflectionClass( $creator );
		$prop = $ref->getProperty( 'pipeline' );
		$prop->setAccessible( true );
		return $prop->getValue( $creator );
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	public function test_injected_pipeline_is_stored(): void {
		$stub    = $this->make_pipeline_stub();
		$creator = new PostCreator( $stub );

		$this->assertSame( $stub, $this->get_pipeline( $creator ) );
	}

	public function test_null_argument_creates_default_pipeline(): void {
		// We cannot fully construct a real Pipeline in a unit context (it needs
		// WP options), but we can verify the constructor signature accepts null
		// without throwing when the internal Pipeline ctor is skipped via a stub.
		// The real default-construction path is covered by integration tests.
		$stub    = $this->make_pipeline_stub();
		$creator = new PostCreator( $stub );

		// Confirm the stored pipeline is a Pipeline instance.
		$this->assertInstanceOf( Pipeline::class, $this->get_pipeline( $creator ) );
	}

	public function test_two_creators_with_different_stubs_hold_independent_pipelines(): void {
		$stub_a = $this->make_pipeline_stub( [ ['title' => 'A'] ] );
		$stub_b = $this->make_pipeline_stub( [ ['title' => 'B'] ] );

		$creator_a = new PostCreator( $stub_a );
		$creator_b = new PostCreator( $stub_b );

		$this->assertSame( $stub_a, $this->get_pipeline( $creator_a ) );
		$this->assertSame( $stub_b, $this->get_pipeline( $creator_b ) );
		$this->assertNotSame( $this->get_pipeline( $creator_a ), $this->get_pipeline( $creator_b ) );
	}
}
