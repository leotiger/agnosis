<?php
/**
 * Unit tests for Pipeline::merge_biography().
 *
 * merge_biography() sends a chat prompt to the description provider and returns
 * the merged text, or '' on provider failure (caller keeps new submission as-is).
 *
 * @package Agnosis\Tests\Unit\AI
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\AI;

use Agnosis\AI\Pipeline;
use Agnosis\AI\ProviderInterface;
use PHPUnit\Framework\TestCase;

class PipelineMergeBiographyTest extends TestCase {

	// -------------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------------

	private function make_pipeline( ProviderInterface $provider ): Pipeline {
		$pipeline = new Pipeline();

		$ref = new \ReflectionProperty( Pipeline::class, 'description_provider' );
		$ref->setAccessible( true );
		$ref->setValue( $pipeline, $provider );

		return $pipeline;
	}

	// -------------------------------------------------------------------------
	// Happy path
	// -------------------------------------------------------------------------

	public function test_returns_merged_text_from_provider(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '<p>Ana is a sculptor based in Madrid. She recently won the Premio Nacional.</p>' );

		$result = $this->make_pipeline( $provider )->merge_biography(
			'Ana is a sculptor based in Madrid.',
			'I just won the Premio Nacional.'
		);

		$this->assertStringContainsString( 'Premio Nacional', $result );
		$this->assertStringContainsString( 'Madrid', $result );
	}

	public function test_prompt_includes_existing_and_new_text(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
			->method( 'chat' )
			->with(
				$this->logicalAnd(
					$this->stringContains( 'sculptor based in Madrid' ),
					$this->stringContains( 'Premio Nacional' )
				)
			)
			->willReturn( 'merged' );

		$this->make_pipeline( $provider )->merge_biography(
			'Ana is a sculptor based in Madrid.',
			'I just won the Premio Nacional.'
		);
	}

	public function test_prompt_instructs_disregarding_mail_footers(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
			->method( 'chat' )
			->with( $this->stringContains( 'mail-client footers' ) )
			->willReturn( 'merged' );

		$this->make_pipeline( $provider )->merge_biography(
			'Ana is a sculptor based in Madrid.',
			'I just won the Premio Nacional.'
		);
	}

	// -------------------------------------------------------------------------
	// Short-circuit on empty input
	// -------------------------------------------------------------------------

	public function test_returns_empty_when_existing_is_blank(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->never() )->method( 'chat' );

		$result = $this->make_pipeline( $provider )->merge_biography( '   ', 'New update.' );

		$this->assertSame( '', $result );
	}

	public function test_returns_empty_when_update_is_blank(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->never() )->method( 'chat' );

		$result = $this->make_pipeline( $provider )->merge_biography( 'Existing bio.', '   ' );

		$this->assertSame( '', $result );
	}

	// -------------------------------------------------------------------------
	// Provider failure — caller falls back to new submission
	// -------------------------------------------------------------------------

	public function test_returns_empty_when_provider_returns_empty_string(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '' );

		$result = $this->make_pipeline( $provider )->merge_biography(
			'Existing bio.',
			'New update.'
		);

		// Caller is responsible for falling back; method just signals failure with ''.
		$this->assertSame( '', $result );
	}
}
