<?php
/**
 * Unit tests for Pipeline::extract_event_fields().
 *
 * The method sends a chat prompt to the description provider and parses the
 * JSON response to extract an event location. Tests verify correct parsing,
 * fallback on empty/malformed responses, and short-circuit on empty input.
 *
 * @package Agnosis\Tests\Unit\AI
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\AI;

use Agnosis\AI\Pipeline;
use Agnosis\AI\ProviderInterface;
use PHPUnit\Framework\TestCase;

class PipelineExtractEventFieldsTest extends TestCase {

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

	public function test_returns_location_from_valid_json(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '{"location":"Gallery Mitte, Berlin"}' );

		$result = $this->make_pipeline( $provider )->extract_event_fields( [
			'description' => 'Join us at Gallery Mitte, Berlin for the opening night.',
		] );

		$this->assertSame( 'Gallery Mitte, Berlin', $result['location'] );
	}

	public function test_includes_subject_and_body_in_prompt(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
			->method( 'chat' )
			->with( $this->stringContains( 'Subject: Opening Night' ) )
			->willReturn( '{"location":""}' );

		$this->make_pipeline( $provider )->extract_event_fields( [
			'subject'     => 'Opening Night',
			'description' => 'Come celebrate with us.',
		] );
	}

	public function test_returns_empty_location_when_none_mentioned(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '{"location":""}' );

		$result = $this->make_pipeline( $provider )->extract_event_fields( [
			'description' => 'Come celebrate with us.',
		] );

		$this->assertSame( '', $result['location'] );
	}

	// -------------------------------------------------------------------------
	// Malformed / unusual provider responses
	// -------------------------------------------------------------------------

	public function test_strips_markdown_fences_from_response(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( "```json\n{\"location\":\"Tate Modern\"}\n```" );

		$result = $this->make_pipeline( $provider )->extract_event_fields( [
			'description' => 'Exhibition at Tate Modern.',
		] );

		$this->assertSame( 'Tate Modern', $result['location'] );
	}

	public function test_returns_empty_location_on_invalid_json(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( 'not json at all' );

		$result = $this->make_pipeline( $provider )->extract_event_fields( [
			'description' => 'Some text.',
		] );

		$this->assertSame( '', $result['location'] );
	}

	public function test_returns_empty_location_when_provider_returns_empty_string(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '' );

		$result = $this->make_pipeline( $provider )->extract_event_fields( [
			'description' => 'Some text.',
		] );

		$this->assertSame( '', $result['location'] );
	}

	// -------------------------------------------------------------------------
	// Short-circuit on empty submission
	// -------------------------------------------------------------------------

	public function test_does_not_call_provider_when_submission_is_empty(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->never() )->method( 'chat' );

		$result = $this->make_pipeline( $provider )->extract_event_fields( [] );

		$this->assertSame( '', $result['location'] );
	}

	public function test_does_not_call_provider_when_body_and_subject_are_blank(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->never() )->method( 'chat' );

		$result = $this->make_pipeline( $provider )->extract_event_fields( [
			'description' => '   ',
			'subject'     => '',
		] );

		$this->assertSame( '', $result['location'] );
	}
}
