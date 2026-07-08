<?php
/**
 * Unit tests for Pipeline::polish().
 *
 * The polish method delegates to the description provider's chat() method and
 * applies a tight "spelling + grammar only" prompt. Tests verify correct
 * delegation, fallback on empty response, and short-circuit on blank input.
 *
 * @package Agnosis\Tests\Unit\AI
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\AI;

use Agnosis\AI\Pipeline;
use Agnosis\AI\ProviderInterface;
use PHPUnit\Framework\TestCase;

class PipelinePolishTest extends TestCase {

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

	public function test_polish_returns_provider_output(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( 'Corrected text.' );

		$result = $this->make_pipeline( $provider )->polish( 'Corected tekst.' );

		$this->assertSame( 'Corrected text.', $result );
	}

	public function test_polish_passes_text_to_provider_inside_prompt(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
			->method( 'chat' )
			->with( $this->stringContains( 'Fix spelling and grammar' ) )
			->willReturn( 'Fixed.' );

		$this->make_pipeline( $provider )->polish( 'Sume tekst.' );
	}

	public function test_polish_prompt_instructs_ignoring_mail_footers(): void {
		// Biography/event submissions arrive as raw email text — a mail
		// client's "Sent from my iPhone" footer or a signature block must
		// not be treated as content to polish and keep.
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
			->method( 'chat' )
			->with( $this->stringContains( 'mail-client footers' ) )
			->willReturn( 'Fixed.' );

		$this->make_pipeline( $provider )->polish( 'Some text.' );
	}

	// -------------------------------------------------------------------------
	// Fallback on empty / null-like provider response
	// -------------------------------------------------------------------------

	public function test_polish_falls_back_to_original_when_provider_returns_empty_string(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '' );

		$result = $this->make_pipeline( $provider )->polish( 'Original text.' );

		$this->assertSame( 'Original text.', $result );
	}

	// -------------------------------------------------------------------------
	// Short-circuit on blank input
	// -------------------------------------------------------------------------

	public function test_polish_returns_empty_string_unchanged_without_calling_provider(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->never() )->method( 'chat' );

		$result = $this->make_pipeline( $provider )->polish( '' );

		$this->assertSame( '', $result );
	}

	public function test_polish_returns_whitespace_only_string_unchanged_without_calling_provider(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->never() )->method( 'chat' );

		$result = $this->make_pipeline( $provider )->polish( '   ' );

		$this->assertSame( '   ', $result );
	}

	public function test_polish_returns_newline_only_string_unchanged_without_calling_provider(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->never() )->method( 'chat' );

		$result = $this->make_pipeline( $provider )->polish( "\n\t\r" );

		$this->assertSame( "\n\t\r", $result );
	}
}
